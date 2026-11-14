<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use ArrayIterator;
use ArrayObject;
use Closure;
use InvalidArgumentException;
use PHPMailer\PHPMailer\PHPMailer;
use ReflectionFunction;
use ReflectionMethod;
use SensitiveParameterValue;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Dumper\Describer;
use Tracy\Dumper\Exposer;
use Tracy\Dumper\Value;
use Tracy\Logger;
use Zephyrus\Core\Config\ConfigSection;

/**
 * Wires the Tracy debugger and decides which clients may see its rendered output.
 *
 * The Bluescreen shows stack trace arguments, $_SERVER, $_ENV and $_COOKIE, so it must never reach every client.
 * A client receives the Bluescreen only when it is
 *
 *   - listed by exact address in the allowlist passed as $allowedClients (a loopback address only when the request
 *     carries no forwarding header), or
 *   - presenting the `tracy-debug` cookie whose value matches a `secret@address` allowlist entry, or
 *   - with no allowlist, the loopback address under PHP's built-in server (`cli-server`) only, and the request
 *     carries no X-Forwarded-For, Forwarded or X-Real-IP header.
 *
 * With debug on, the debugger stays enabled for every client, so PHP errors still reach the log directory and the
 * notification email when initialize() is given them. Whether debug is enabled at all is decided by
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

    /** Why the last initialize() left debug on but the client unadmitted; null otherwise. */
    private static ?string $refusalHint = null;

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
        'Zephyrus\Core\ApplicationBuilder::$debugAllowedClients',
        'Zephyrus\Http\Response::$cookies',
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
     * The allowlist is validated by allowedClientList() before Tracy is touched.
     *
     * @param bool                    $debug          Whether the application is in debug mode.
     * @param string|null             $logDirectory   Directory for Tracy log files. Null keeps Debugger::$logDirectory;
     *                                                with no directory at all, Tracy logs to PHP's error log.
     * @param string|null             $email          Email for error notifications.
     * @param string|string[]|null    $allowedClients Addresses (or `secret@address` entries matched against the
     *                                                `tracy-debug` cookie) permitted to receive the Bluescreen. A
     *                                                list admits loopback only when it names it, and a bare loopback
     *                                                entry only when no forwarded header is present. Null admits
     *                                                loopback clients only under PHP's built-in server
     *                                                (`cli-server`), and only when no forwarded header is present.
     *                                                Never grant this to a range you do not control: an entry here
     *                                                can read the application's live secrets out of a stack trace.
     * @param string|null             $sessionName    The application's session cookie name, masked like SENSITIVE_KEYS.
     * @param string                  $serverApi      The SAPI name; without an allowlist, only `cli-server` admits one.
     * @throws InvalidArgumentException on an empty secret or address, a range in the address, or an array entry
     *                                   that is not a string or holds whitespace or a comma.
     */
    public static function initialize(
        bool $debug,
        ?string $logDirectory = null,
        ?string $email = null,
        #[\SensitiveParameter] string|array|null $allowedClients = null,
        ?string $sessionName = null,
        string $serverApi = PHP_SAPI,
    ): void {
        $clients = self::allowedClientList($allowedClients);
        self::$refusalHint = null;

        if (!$debug) {
            Debugger::$productionMode = true;

            return;
        }

        $admitted = self::admitsClient($clients, $serverApi);
        if (!$admitted) {
            self::$refusalHint = self::describeRefusal($clients !== null);
        }

        Debugger::enable(
            $admitted ? Debugger::Development : Debugger::Production,
            $logDirectory,
            $email,
        );

        // Tracy's default logger cannot write without a directory, and would drop what it catches.
        $logger = Debugger::getLogger();
        if ($logger instanceof Logger && $logger::class === Logger::class && ($logger->directory ?? '') === '') {
            Debugger::setLogger(new TracyErrorLogLogger());
        }

        self::hideFrameworkSecrets($sessionName);
    }

    /**
     * Why debug is on but the client got the production page, or null when debug is off or the client is admitted.
     *
     * @internal Read by HttpKernel to explain a 500 in the error log.
     */
    public static function refusalHint(): ?string
    {
        return self::$refusalHint;
    }

    private static function describeRefusal(bool $hasAllowlist): string
    {
        if (!$hasAllowlist) {
            return 'the debugger admits no client without an allowlist, except a direct loopback client under php -S; '
                . 'see withDebugClientAllowlist()';
        }

        $address = $_SERVER['REMOTE_ADDR'] ?? null;
        $printable = is_string($address) && preg_match('/\A[\x21-\x7e]{1,45}\z/', $address) === 1 ? $address : 'unknown';

        return 'the debugger did not admit ' . $printable . '; add it, or secret@' . $printable
            . ', to withDebugClientAllowlist()';
    }

    /**
     * Whether this boot's client gets the rendered debugger output: a client the allowlist names or, with no
     * allowlist, a loopback client Tracy detects that carries no X-Real-IP header, and only under PHP's built-in
     * server.
     *
     * @param list<string>|null $clients An allowlist returned by allowedClientList().
     * @param string            $serverApi The SAPI name; without an allowlist, only `cli-server` admits one.
     * @internal Shared by ApplicationBuilder::build() and initialize().
     */
    public static function admitsClient(#[\SensitiveParameter] ?array $clients, string $serverApi = PHP_SAPI): bool
    {
        if ($clients !== null) {
            return self::isListedClient($clients);
        }

        if ($serverApi !== 'cli-server') {
            return false;
        }

        $address = $_SERVER['REMOTE_ADDR'] ?? null;
        if (is_string($address) && self::isLoopback($address) && isset($_SERVER['HTTP_X_REAL_IP'])) {
            return false;
        }

        return Debugger::detectDebugMode();
    }

    /**
     * Whether the client is listed by exact address, or by a `secret@address` entry matching the `tracy-debug` cookie.
     * A bare loopback entry does not admit a request carrying X-Forwarded-For, Forwarded or X-Real-IP.
     *
     * @param list<string> $clients
     */
    private static function isListedClient(#[\SensitiveParameter] array $clients): bool
    {
        $address = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($address) || $address === '') {
            return false;
        }

        $secret = $_COOKIE[Debugger::CookieSecret] ?? null;
        $presented = is_string($secret) ? $secret . '@' . $address : null;
        $bareAddressAdmits = !self::isLoopback($address)
            || (!isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !isset($_SERVER['HTTP_FORWARDED']) && !isset($_SERVER['HTTP_X_REAL_IP']));

        foreach ($clients as $entry) {
            if (($bareAddressAdmits && $entry === $address) || ($presented !== null && hash_equals($entry, $presented))) {
                return true;
            }
        }

        return false;
    }

    /** Whether the address is in 127.0.0.0/8, is ::1, or is an IPv4-mapped 127.0.0.0/8 address. */
    private static function isLoopback(string $address): bool
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return strlen($packed) === 4 ? $packed[0] === "\x7f" : $packed === str_repeat("\0", 15) . "\x01";
    }

    /**
     * Validates a debug client allowlist and returns its entries, or null when it names none.
     *
     * Checks each entry the way Tracy splits and compares them. Messages name the position, never the secret.
     *
     * @param string|array<mixed>|null $clients
     * @return list<string>|null
     * @throws InvalidArgumentException on an empty secret or address, a range in the address, or an array entry that is not a string or holds whitespace or a comma.
     * @internal Shared by ApplicationBuilder::withDebugClientAllowlist() and initialize().
     */
    public static function allowedClientList(#[\SensitiveParameter] string|array|null $clients): ?array
    {
        if ($clients === null) {
            return null;
        }

        if (is_string($clients)) {
            $entries = preg_split('#[,\s]+#', $clients, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($entries as $index => $entry) {
                self::assertAllowedClientEntry($index + 1, $entry);
            }

            return $entries === [] ? null : $entries;
        }

        $entries = [];
        $position = 0;
        foreach ($clients as $entry) {
            ++$position;
            if (!is_string($entry)) {
                throw new InvalidArgumentException('Debug client allowlist entry ' . $position . ' is not a string.');
            }
            if (preg_match('#\A[,\s]*\z#', $entry) === 1) {
                continue;
            }
            if (preg_match('#[,\s]#', $entry) === 1) {
                throw new InvalidArgumentException(
                    'Debug client allowlist entry ' . $position
                    . ' holds whitespace or a comma: pass each address as its own array entry.',
                );
            }
            self::assertAllowedClientEntry($position, $entry);
            $entries[] = $entry;
        }

        return $entries === [] ? null : $entries;
    }

    /** @throws InvalidArgumentException */
    private static function assertAllowedClientEntry(int $position, #[\SensitiveParameter] string $entry): void
    {
        $at = strpos($entry, '@');
        if ($at === 0) {
            throw new InvalidArgumentException(
                'Debug client allowlist entry ' . $position . ' has an empty secret before "@": with no tracy-debug '
                . 'cookie it would admit every client at that address. Set a secret, or list the bare address if that '
                . 'is intended.',
            );
        }
        if ($at !== false && $at === strlen($entry) - 1) {
            throw new InvalidArgumentException('Debug client allowlist entry ' . $position . ' has no address after "@".');
        }
        $address = $at === false ? $entry : substr($entry, $at + 1);
        if (str_contains($address, '/')) {
            throw new InvalidArgumentException(
                'Debug client allowlist entry ' . $position
                . ' is a range: Tracy matches exact addresses only, so list each address.',
            );
        }
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
