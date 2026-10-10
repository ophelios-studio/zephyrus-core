<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use ArrayIterator;
use Closure;
use ReflectionFunction;
use SensitiveParameterValue;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Dumper\Describer;
use Tracy\Dumper\Exposer;
use Tracy\Dumper\Value;
use Zephyrus\Core\Config\ConfigSection;

/**
 * Wires Tracy Debugger integration based on application configuration.
 *
 * ## Why this file is careful
 *
 * Tracy has two very different jobs behind one switch. In DEVELOPMENT mode it
 * renders the Bluescreen: a full HTML page carrying the exception, the stack
 * trace WITH argument values, $_SERVER, $_ENV, $_COOKIE and every object
 * reachable from the frames. In PRODUCTION mode it logs and shows nothing.
 *
 * This class used to hardcode Debugger::Development. That decision was made
 * once, at boot, for EVERY client, so the Bluescreen went to whoever managed to
 * trigger a 500 -- an anonymous caller on the public internet included. Tracy's
 * enable() also sets zend.exception_ignore_args to 0, which puts real argument
 * values (an encryption key, an SMTP password, a reset token) into every stack
 * trace the process produces from then on, and forces display_errors off, so
 * anything the application had hardened is overwritten either way.
 *
 * That is not a hypothetical. An operator diagnosing a live incident routinely
 * sets APP_ENV=dev and APP_DEBUG=true on a PRODUCTION tier for a few minutes.
 * During that window the old behaviour handed the tier's live secrets to any
 * client who could reach a 500.
 *
 * So the mode is now Debugger::Detect, and the client has to earn the
 * Bluescreen:
 *
 *   - it is the loopback address (a developer on their own machine), which
 *     Tracy only grants when the request did NOT arrive through a proxy, or
 *   - it presents the `tracy-debug` cookie whose value matches an allowlist
 *     entry given as `secret@ip`, or
 *   - its address is in the allowlist passed to $allowedClients.
 *
 * Every other client gets the production behaviour: logged, not rendered. The
 * debugger is still ENABLED in both cases, so errors still reach the log
 * directory and the notification email; only the rendering audience changed.
 *
 * ## The second half of this fix lives in ApplicationBuilder
 *
 * DebugIntegration decides WHO sees the debugger. ApplicationBuilder decides
 * whether a production-like environment may turn it on at all; see
 * ApplicationBuilder::build(). The two are deliberately independent, so an
 * operator who deliberately acknowledges debug on production still does not
 * broadcast it to the world.
 *
 * Usage (typically automatic via ApplicationBuilder):
 *
 *   DebugIntegration::initialize(debug: true);
 *   DebugIntegration::initialize(debug: true, logDirectory: '/var/log/app');
 *   DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.4']);
 *   DebugIntegration::initialize(debug: true, allowedClients: 'mysecret@203.0.113.4');
 */
final class DebugIntegration
{
    /** The scrubber this class installed, so a repeated initialize() does not wrap it again. */
    private static ?Closure $frameworkScrubber = null;

    /** @var list<string> Application-specific exact names (the session cookie), set by initialize(). */
    private static array $applicationKeys = [];

    /** Exact key names masked on dump(), the debug bar and the Bluescreen. dump() has no default list of its own. */
    public const array SENSITIVE_KEYS = [
        'smtpPassword',
        'password',
        'passwordPepper',
        'password_pepper',
        'pepper',
        'throttleKey',
        'throttle_key',
        '_csrf_token',
        'encryptionKey',
        'encryption_key',
        'secret',
        'token',
        'apiKey',
        'api_key',
        'privateKey',
        'private_key',
        'cookie',
        'set-cookie',
        'authorization',
        'proxy-authorization',
        'x-csrf-token',
        'x-xsrf-token',
        'x-api-key',
        'x-auth-token',
        'tracy-debug',
    ];

    /** Class properties masked by Tracy's Class::$property form. Tracy's own "POST (preview)" still shows the request body. */
    public const array SENSITIVE_PROPERTIES = [
        'Zephyrus\Http\RequestBody::$raw',
        'Zephyrus\Data\DatabaseException::$driverMessage',
        'Zephyrus\Mailer\MailerException::$transportMessage',
        'Exception::$trace',
        'Error::$trace',
    ];

    /**
     * Name patterns the Bluescreen scrubber masks, and every renderer for a variable a closure captures.
     *
     * Elsewhere dump() and the debug bar apply only the exact names (SENSITIVE_KEYS,
     * SENSITIVE_PROPERTIES and the session name). The pattern skips int, float,
     * bool and null values; exact names mask any value.
     */
    public const string SENSITIVE_KEY_PATTERN = '/password|passwd|passphrase|secret|token|pepper|api[_-]?key|private[_-]?key|credential|authorization|auth_pw|cookie|sessid|throttle|tracy-debug/i';

    /**
     * Initialize Tracy Debugger if debug mode is enabled.
     *
     * When debug is false, Tracy is never enabled, so none of its ini_set()
     * calls run. productionMode is still set to true, because Tracy's dump()
     * prints unless it is true, and it defaults to null.
     *
     * An application Closure or ConfigSection exporter in Dumper::$objectExporters
     * must be registered after this call, or it is replaced.
     *
     * @param bool                    $debug          Whether the application is in debug mode.
     * @param string|null             $logDirectory   Directory for Tracy log files. Null uses Tracy's default.
     * @param string|null             $email          Email for error notifications.
     * @param string|string[]|null    $allowedClients Addresses (or `secret@address` entries matched
     *                                                against the `tracy-debug` cookie) permitted to
     *                                                receive the Bluescreen. Null means loopback and
     *                                                the cookie only. Never grant this to a range you
     *                                                do not control: an entry here can read the
     *                                                application's live secrets out of a stack trace.
     * @param string|null             $sessionName    The application's session cookie name, masked like SENSITIVE_KEYS.
     */
    public static function initialize(
        bool $debug,
        ?string $logDirectory = null,
        ?string $email = null,
        string|array|null $allowedClients = null,
        ?string $sessionName = null,
    ): void {
        if (!$debug) {
            Debugger::$productionMode = true;

            return;
        }

        // Tracy re-detects only when productionMode is null: a debug-off boot set it.
        Debugger::$productionMode = null;

        // Debugger::Detect is null, and Tracy reads a string or an array as the
        // allowlist for the very same detection. Passing $allowedClients
        // straight through therefore keeps ONE code path: with or without an
        // allowlist, the client still has to match something.
        Debugger::enable(
            $allowedClients ?? Debugger::Detect,
            $logDirectory,
            $email,
        );

        self::hideFrameworkSecrets($sessionName);
    }

    /**
     * Keep dump() silent when no configuration decided debug mode.
     *
     * An application that enables Tracy by hand must do so before build(), or
     * pass an explicit mode to Debugger::enable(); otherwise this forces
     * production mode on it.
     */
    public static function initializeWithoutConfiguration(): void
    {
        if (!Debugger::isEnabled()) {
            self::initialize(debug: false);
        }
    }

    /**
     * Teach Tracy the framework's own secret-bearing key names and how to render config sections and closures.
     *
     * Both registries are written because they feed different renderers:
     * Debugger::$keysToHide reaches dump() and the debug bar, while the
     * Bluescreen keeps its own list.
     */
    private static function hideFrameworkSecrets(?string $sessionName): void
    {
        if ($sessionName !== null) {
            self::$applicationKeys = array_values(array_unique([...self::$applicationKeys, $sessionName]));
        }

        // Entries become public properties so Tracy's sensitivity check still sees them.
        // @phpstan-ignore assign.propertyType (Tracy's phpdoc omits callables, its Describer invokes any)
        Dumper::$objectExporters[ConfigSection::class] = static function (ConfigSection $section, Value $value, Describer $describer): void {
            foreach ($section->toArray() as $key => $entry) {
                $describer->addPropertyTo($value, (string) $key, $entry, Value::PropertyPublic, null, $section::class);
            }
        };

        // @phpstan-ignore assign.propertyType (same as above)
        Dumper::$objectExporters[Closure::class] = self::exposeClosure(...);

        // @phpstan-ignore assign.propertyType (same as above)
        Dumper::$objectExporters[ArrayIterator::class] = self::exposeArrayIterator(...);

        $hidden = array_values(array_unique([
            ...self::SENSITIVE_KEYS,
            ...self::SENSITIVE_PROPERTIES,
            ...self::$applicationKeys,
        ]));

        Debugger::$keysToHide = array_values(array_unique(array_merge(Debugger::$keysToHide, $hidden)));

        $blueScreen = Debugger::getBlueScreen();
        $blueScreen->keysToHide = array_values(array_unique(array_merge($blueScreen->keysToHide, $hidden)));

        // Not scrubbable (phpinfo output): an app wanting it back sets showEnvironment = true after build().
        $blueScreen->showEnvironment = false;

        $current = $blueScreen->scrubber;
        if ($current !== null && $current === self::$frameworkScrubber) {
            return;
        }

        // Composed, not replaced: an application scrubber set before boot still applies.
        $previous = $current;
        $frameworkScrubber = static fn (string $key, mixed $value, ?string $class): bool
            => self::isSensitiveEntry($key, $value)
            || ($previous !== null && (bool) $previous($key, $value, $class));

        self::$frameworkScrubber = $frameworkScrubber;
        $blueScreen->scrubber = $frameworkScrubber;
    }

    /**
     * Render a closure as Tracy does, but with its captured variables subject to masking.
     *
     * Tracy adds them as virtual properties, which it never checks for sensitivity.
     */
    private static function exposeClosure(Closure $closure, Value $value, Describer $describer): void
    {
        $reflection = new ReflectionFunction($closure);
        if ($describer->location) {
            $describer->addPropertyTo($value, 'file', $reflection->getFileName() . ':' . $reflection->getStartLine());
        }

        $parameters = [];
        foreach ($reflection->getParameters() as $parameter) {
            $parameters[] = '$' . $parameter->getName();
        }

        $value->value .= '(' . implode(', ', $parameters) . ')';

        $boundThis = $reflection->getClosureThis();
        if ($boundThis !== null) {
            $describer->addPropertyTo($value, 'this', null, described: new Value(Value::TypeText, get_debug_type($boundThis)));
        }

        $bindings = $reflection->getStaticVariables();
        if ($bindings === []) {
            return;
        }

        $use = new Value(Value::TypeObject);
        $use->depth = $value->depth + 1;
        foreach ($bindings as $name => $binding) {
            // Tracy masks a SensitiveParameterValue on the property path, whatever its key.
            if (!$binding instanceof SensitiveParameterValue && self::isSensitiveCapture($name, $binding, $describer)) {
                $binding = new SensitiveParameterValue($binding);
            }

            $describer->addPropertyTo($use, '$' . $name, $binding, Value::PropertyPublic);
        }

        $use->value = '$' . implode(', $', array_keys($bindings));
        $use->collapsed = true;
        $describer->addPropertyTo($value, 'use', null, described: $use);
    }

    /**
     * Render an ArrayIterator's elements as a storage property, so masking applies to them.
     *
     * @param ArrayIterator<array-key, mixed> $iterator
     */
    private static function exposeArrayIterator(ArrayIterator $iterator, Value $value, Describer $describer): void
    {
        $flags = $iterator->getFlags();
        $iterator->setFlags(ArrayIterator::STD_PROP_LIST);
        Exposer::exposeObject($iterator, $value, $describer);
        $iterator->setFlags($flags);

        $describer->addPropertyTo($value, 'storage', $iterator->getArrayCopy(), Value::PropertyPrivate, null, ArrayIterator::class);
        $value->value .= ' (' . count($iterator) . ')';
    }

    /**
     * Tracy checks a capture under its '$name' key, so the renderer's own registries are consulted here with the bare name.
     */
    private static function isSensitiveCapture(string $name, mixed $binding, Describer $describer): bool
    {
        return self::isSensitiveEntry($name, $binding)
            || isset($describer->keysToHide[strtolower($name)])
            || ($describer->scrubber !== null && ($describer->scrubber)($name, $binding, null));
    }

    /**
     * Whether the name matches an exact sensitive name or SENSITIVE_KEY_PATTERN, ignoring case and a leading dollar sign.
     */
    public static function isSensitiveKey(string $key): bool
    {
        return self::matchesSensitivePattern($key) || self::isExactSensitiveName($key);
    }

    /**
     * Exact names mask any value; the name pattern skips int, float, bool and null,
     * so a numeric setting such as a minimum password length stays readable.
     */
    private static function isSensitiveEntry(string $key, mixed $value): bool
    {
        if (self::isExactSensitiveName($key)) {
            return true;
        }

        $isReadableScalar = $value === null || is_bool($value) || is_int($value) || is_float($value);

        return !$isReadableScalar && self::matchesSensitivePattern($key);
    }

    private static function matchesSensitivePattern(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY_PATTERN, strtolower(ltrim($key, '$'))) === 1;
    }

    private static function isExactSensitiveName(string $key): bool
    {
        $name = strtolower(ltrim($key, '$'));

        return in_array($name, array_map(strtolower(...), [...self::SENSITIVE_KEYS, ...self::$applicationKeys]), true);
    }
}
