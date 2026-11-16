<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Controller\Controller;
use Zephyrus\Validation\FormValidator;
use Zephyrus\Validation\Rule;
use Zephyrus\Validation\Rules;
use Zephyrus\Validation\ValidationException;

final class SensitiveValueTest extends TestCase
{
    private const string SECRET = 'correct horse battery staple';

    public function testAnExceptionRaisedByARuleKeepsTheValueOutOfItsTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $form = new FormValidator([
                'password' => [Rule::of(
                    static function (#[\SensitiveParameter] mixed $v): bool {
                        throw new \RuntimeException('rule failure');
                    },
                    'Invalid.',
                )],
            ]);

            try {
                $form->validate(['password' => self::SECRET]);
                self::fail('expected the rule to throw');
            } catch (\RuntimeException $e) {
                self::assertFalse(self::containsSecret($e->getTrace()));
                self::assertStringNotContainsString(self::SECRET, $e->getTraceAsString());
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '' : $previous);
        }
    }

    public function testAControllerValidationKeepsTheInputOutOfTheTraceOfARefusal(): void
    {
        $form = new FormValidator(['password' => [Rules::minLength(100)]]);

        $trace = self::traceOf(static fn (object $controller) => $controller->run($form, ['password' => self::SECRET]));

        self::assertInstanceOf(ValidationException::class, $trace['exception']);
        self::assertFalse(self::containsSecret($trace['trace']));
    }

    public function testAControllerValidationKeepsTheInputOutOfTheTraceOfARuleThatThrows(): void
    {
        $form = new FormValidator(['password' => [Rule::of(
            static function (#[\SensitiveParameter] mixed $v): bool {
                throw new \RuntimeException('rule failure');
            },
            'Invalid.',
        )]]);

        $trace = self::traceOf(static fn (object $controller) => $controller->run($form, ['password' => self::SECRET]));

        self::assertInstanceOf(\RuntimeException::class, $trace['exception']);
        self::assertFalse(self::containsSecret($trace['trace']));
    }

    /**
     * @return array{exception: \Throwable, trace: array<int, mixed>}
     */
    private static function traceOf(\Closure $call): array
    {
        $controller = new class extends Controller {
            /**
             * @param array<string, mixed> $data
             */
            public function run(FormValidator $form, #[\SensitiveParameter] array $data): void
            {
                $this->validate($form, $data);
            }
        };

        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $call($controller);
        } catch (\Throwable $e) {
            return ['exception' => $e, 'trace' => $e->getTrace()];
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '' : $previous);
        }
        self::fail('expected an exception');
    }

    #[DataProvider('factories')]
    public function testEveryRuleFactoryMarksItsValueParameterSensitive(string $factory, array $arguments): void
    {
        $rule = Rules::$factory(...$arguments);
        $closure = (new \ReflectionProperty(Rule::class, 'test'))->getValue($rule);
        $parameter = (new \ReflectionFunction($closure))->getParameters()[0];

        self::assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class));
    }

    public function testEveryPublicRuleFactoryHasARowInTheProvider(): void
    {
        $public = [];
        foreach ((new \ReflectionClass(Rules::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                $public[] = $method->getName();
            }
        }
        $listed = array_keys(self::factories());
        sort($public);
        sort($listed);

        self::assertSame($public, $listed);
    }

    public function testEveryPrivateHelperThatReceivesAValueMarksItSensitive(): void
    {
        $unmarked = [];
        foreach ((new \ReflectionClass(Rules::class))->getMethods(\ReflectionMethod::IS_PRIVATE) as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() === 'v' && $parameter->getAttributes(\SensitiveParameter::class) === []) {
                    $unmarked[] = $method->getName();
                }
            }
        }

        self::assertSame([], $unmarked);
    }

    /**
     * @return array<string, array{string, list<mixed>}>
     */
    public static function factories(): array
    {
        return [
        'required' => ['required', []],
        'minLength' => ['minLength', [1]],
        'maxLength' => ['maxLength', [1]],
        'email' => ['email', []],
        'integer' => ['integer', []],
        'numeric' => ['numeric', []],
        'integerString' => ['integerString', []],
        'decimalString' => ['decimalString', []],
        'min' => ['min', [1]],
        'greaterThan' => ['greaterThan', [1]],
        'max' => ['max', [1]],
        'lessThan' => ['lessThan', [1]],
        'between' => ['between', [1, 2]],
        'betweenExclusive' => ['betweenExclusive', [1, 2]],
        'regex' => ['regex', ['/x/', 'm']],
        'ascii' => ['ascii', []],
        'alphaNumeric' => ['alphaNumeric', []],
        'startsWith' => ['startsWith', ['a']],
        'endsWith' => ['endsWith', ['a']],
        'contains' => ['contains', ['a']],
        'in' => ['in', [['a']]],
        'url' => ['url', []],
        'httpUrl' => ['httpUrl', []],
        'notBlank' => ['notBlank', []],
        'boolean' => ['boolean', []],
        'uuid' => ['uuid', []],
        'date' => ['date', []],
        'dateTime' => ['dateTime', []],
        'rfc3339DateTime' => ['rfc3339DateTime', []],
        'timezone' => ['timezone', []],
        'time24' => ['time24', []],
        'phoneE164' => ['phoneE164', []],
        'hexColor' => ['hexColor', []],
        'macAddress' => ['macAddress', []],
        'cronExpression' => ['cronExpression', []],
        'postalCode' => ['postalCode', []],
        'countMin' => ['countMin', [1]],
        'countMax' => ['countMax', [1]],
        'ip' => ['ip', []],
        'ipv4' => ['ipv4', []],
        'ipv6' => ['ipv6', []],
        'hostname' => ['hostname', []],
        'cidr' => ['cidr', []],
        'port' => ['port', []],
        'host' => ['host', []],
        'privateIp' => ['privateIp', []],
        'publicIp' => ['publicIp', []],
        'subnetMask' => ['subnetMask', []],
        'portRange' => ['portRange', []],
        'json' => ['json', []],
        'jsonObject' => ['jsonObject', []],
        'jsonArray' => ['jsonArray', []],
        'slug' => ['slug', []],
        'lowercase' => ['lowercase', []],
        'uppercase' => ['uppercase', []],
        'noWhitespace' => ['noWhitespace', []],
        'base64' => ['base64', []],
        'base64Url' => ['base64Url', []],
        'semver' => ['semver', []],
        'ulid' => ['ulid', []],
        'sha256' => ['sha256', []],
        'httpPath' => ['httpPath', []],
        'pathSegment' => ['pathSegment', []],
        'safeFilename' => ['safeFilename', []],
        'fileExtension' => ['fileExtension', []],
        'queryString' => ['queryString', []],
        'percentEncoded' => ['percentEncoded', []],
        'httpStatusCode' => ['httpStatusCode', []],
        'httpMethod' => ['httpMethod', []],
        'httpVersion' => ['httpVersion', []],
        'mimeType' => ['mimeType', []],
        'bearerToken' => ['bearerToken', []],
        'languageTag' => ['languageTag', []],
        'acceptLanguage' => ['acceptLanguage', []],
        'httpHeaderName' => ['httpHeaderName', []],
        'httpHeaderValue' => ['httpHeaderValue', []],
        'jwt' => ['jwt', []],
        'hostPort' => ['hostPort', []],
        'countryCode' => ['countryCode', []],
        'locale' => ['locale', []],
        'uuidV1toV5' => ['uuidV1toV5', []],
        'uuidV4' => ['uuidV4', []],
        'uuidV6' => ['uuidV6', []],
        'uuidV7' => ['uuidV7', []],
        'uuidV8' => ['uuidV8', []],
        'currencyCode' => ['currencyCode', []],
        'iban' => ['iban', []],
        'bic' => ['bic', []],
        'cardNumber' => ['cardNumber', []],
        'cardNumberLuhn' => ['cardNumberLuhn', []],
        'cardCvv' => ['cardCvv', []],
        'cardExpiryMmyy' => ['cardExpiryMmyy', []],
        'cardExpiryMmyyyy' => ['cardExpiryMmyyyy', []],
        'nonEmptyString' => ['nonEmptyString', []],
        'inCaseInsensitive' => ['inCaseInsensitive', [['a']]],
        'jsonPointer' => ['jsonPointer', []],
        'latitude' => ['latitude', []],
        'longitude' => ['longitude', []],
        'unixTimestamp' => ['unixTimestamp', []],
        'epochMilliseconds' => ['epochMilliseconds', []],
        'etag' => ['etag', []],
        'ifNoneMatch' => ['ifNoneMatch', []],
        'ifMatch' => ['ifMatch', []],
        'httpDate' => ['httpDate', []],
        'ifModifiedSince' => ['ifModifiedSince', []],
        'ifUnmodifiedSince' => ['ifUnmodifiedSince', []],
        'ifRange' => ['ifRange', []],
        'byteRange' => ['byteRange', []],
        'contentRange' => ['contentRange', []],
        ];
    }

    private static function containsSecret(mixed $node): bool
    {
        if (is_string($node)) {
            return str_contains($node, self::SECRET);
        }
        if (is_array($node)) {
            foreach ($node as $child) {
                if (self::containsSecret($child)) {
                    return true;
                }
            }
        }
        return false;
    }
}
