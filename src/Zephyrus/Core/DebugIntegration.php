<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use ArrayIterator;
use ArrayObject;
use Closure;
use PHPMailer\PHPMailer\PHPMailer;
use ReflectionFunction;
use ReflectionMethod;
use SensitiveParameterValue;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Dumper\Describer;
use Tracy\Dumper\Exposer;
use Tracy\Dumper\Value;
use Zephyrus\Core\Config\ConfigSection;

/**
 * Wires the Tracy debugger and decides which clients may see its rendered output.
 *
 * The Bluescreen shows stack trace arguments, $_SERVER, $_ENV and $_COOKIE, so it must never reach every client.
 * Tracy runs in Debugger::Detect mode: a client receives the Bluescreen only when it is
 *
 *   - the loopback address, and the request did not arrive through a proxy, or
 *   - presenting the `tracy-debug` cookie whose value matches a `secret@ip` allowlist entry, or
 *   - listed in the allowlist passed as $allowedClients.
 *
 * Every other client gets logs and notifications only. With debug on, the debugger stays enabled for every client, so
 * errors still reach the log directory and the notification email. Whether debug is enabled at all is decided by
 * ApplicationBuilder::build(). Debugger::enable() sets zend.exception_ignore_args=0 for the whole process, so every
 * trace, logged ones included, carries real argument values.
 *
 * Usage (typically automatic via ApplicationBuilder):
 *
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

    /**
     * Class properties masked by Tracy's Class::$property form. Tracy's "POST (preview)" still shows the request body.
     * Exception and Error traces are masked whole in property dumps, arguments included; the Bluescreen stack section
     * still shows arguments, masked by parameter name.
     * Dump the object itself: dump((array) $object) exposes private properties under mangled keys no exporter can mask.
     */
    public const array SENSITIVE_PROPERTIES = [
        'Zephyrus\Http\RequestBody::$raw',
        'Zephyrus\Data\DatabaseException::$driverMessage',
        'Zephyrus\Mailer\MailerException::$transportMessage',
        'Zephyrus\Mailer\Mailer::$mail',
        'Zephyrus\Mailer\Mailer::$htmlBody',
        'Zephyrus\Mailer\Mailer::$textBody',
        'Exception::$trace',
        'Error::$trace',
        'Exception::$string',
        'Error::$string',
    ];

    /**
     * Name patterns masked by the Bluescreen scrubber and by the closure capture renderer.
     *
     * dump() and the debug bar use only the exact names (SENSITIVE_KEYS, SENSITIVE_PROPERTIES, the session name),
     * which mask any value. The pattern skips int, float, bool and null, so a numeric secret must be added to
     * Debugger::getBlueScreen()->keysToHide and Debugger::$keysToHide by name.
     */
    public const string SENSITIVE_KEY_PATTERN = '/password|passwd|passphrase|secret|token|pepper|api[_-]?key|private[_-]?key|credential|authorization|auth_pw|cookie|sessid|throttle|tracy-debug/i';

    /**
     * Initialize Tracy Debugger if debug mode is enabled.
     *
     * When debug is false, Tracy is never enabled, so none of its ini_set() calls run. productionMode is still
     * set to true, because Tracy's dump() prints unless it is true, and it defaults to null.
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

        // Tracy reads a string or array passed in place of Detect as the allowlist
        // for the same detection, so with or without one the client must still match.
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
     * Registers the framework's secret key names and the exporters for config sections, closures, array iterators and objects, and PHPMailer.
     *
     * Both keysToHide registries are written: dump() and the debug bar read one, the Bluescreen keeps the other.
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

        // @phpstan-ignore assign.propertyType (same as above)
        Dumper::$objectExporters[ArrayObject::class] = self::exposeArrayObject(...);

        // @phpstan-ignore assign.propertyType (same as above)
        Dumper::$objectExporters[PHPMailer::class] = self::exposePhpMailer(...);

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
     * Render an ArrayIterator with its storage, an array or a wrapped object, as a private property.
     *
     * @param ArrayIterator<array-key, mixed> $iterator
     */
    private static function exposeArrayIterator(ArrayIterator $iterator, Value $value, Describer $describer): void
    {
        self::exposeArrayContainer($iterator, ArrayIterator::class, $value, $describer);
    }

    /**
     * Render an ArrayObject with its storage, an array or a wrapped object, as a private property.
     *
     * @param ArrayObject<array-key, mixed> $container
     */
    private static function exposeArrayObject(ArrayObject $container, Value $value, Describer $describer): void
    {
        self::exposeArrayContainer($container, ArrayObject::class, $value, $describer);
    }

    /**
     * @param ArrayIterator<array-key, mixed>|ArrayObject<array-key, mixed> $container
     * @param class-string $storageClass
     */
    private static function exposeArrayContainer(ArrayIterator|ArrayObject $container, string $storageClass, Value $value, Describer $describer): void
    {
        $flags = self::invokeSplMethod($container, $storageClass, 'getFlags');
        self::invokeSplMethod($container, $storageClass, 'setFlags', ArrayObject::STD_PROP_LIST);
        Exposer::exposeObject($container, $value, $describer);
        self::invokeSplMethod($container, $storageClass, 'setFlags', $flags);

        // Index 1 is the storage: the wrapped object, the array, or null when the object wraps itself.
        $storage = self::invokeSplMethod($container, $storageClass, '__serialize')[1];
        $describer->addPropertyTo($value, 'storage', $storage, Value::PropertyPrivate, null, $storageClass);
        $value->value .= ' (' . self::invokeSplMethod($container, $storageClass, 'count') . ')';
    }

    /**
     * Call the SPL implementation of a method, so a subclass override cannot change what the dump reads.
     *
     * @param ArrayIterator<array-key, mixed>|ArrayObject<array-key, mixed> $container
     * @param class-string $storageClass
     */
    private static function invokeSplMethod(ArrayIterator|ArrayObject $container, string $storageClass, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($storageClass, $method))->invoke($container, ...$arguments);
    }

    /**
     * Render a PHPMailer through an allow-list of transport settings, so recipients, bodies and headers never reach a dump.
     */
    private static function exposePhpMailer(PHPMailer $mail, Value $value, Describer $describer): void
    {
        $describer->addPropertyTo($value, 'Host', $mail->Host, Value::PropertyPublic, null, PHPMailer::class);
        $describer->addPropertyTo($value, 'Port', $mail->Port, Value::PropertyPublic, null, PHPMailer::class);
        $describer->addPropertyTo($value, 'SMTPSecure', $mail->SMTPSecure, Value::PropertyPublic, null, PHPMailer::class);
        $describer->addPropertyTo($value, 'SMTPAuth', $mail->SMTPAuth, Value::PropertyPublic, null, PHPMailer::class);
        $describer->addPropertyTo($value, 'CharSet', $mail->CharSet, Value::PropertyPublic, null, PHPMailer::class);
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
