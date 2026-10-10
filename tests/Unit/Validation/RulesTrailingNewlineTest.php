<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Validation\Rule;
use Zephyrus\Validation\Rules;

final class RulesTrailingNewlineTest extends TestCase
{
    /**
     * @return iterable<string, array{Rule, string}>
     */
    public static function anchoredRuleSamples(): iterable
    {
        yield 'integerString' => [Rules::integerString(), '-12'];
        yield 'decimalString' => [Rules::decimalString(), '12.34'];
        yield 'alphaNumeric' => [Rules::alphaNumeric(), 'abc123'];
        yield 'uuid' => [Rules::uuid(), '123e4567-e89b-12d3-a456-426614174000'];
        yield 'time24' => [Rules::time24(), '23:59'];
        yield 'phoneE164' => [Rules::phoneE164(), '+14155550123'];
        yield 'hexColor' => [Rules::hexColor(), '#1a2b3c'];
        yield 'macAddress' => [Rules::macAddress(), '00:1A:2B:3C:4D:5E'];
        yield 'postalCode' => [Rules::postalCode(), 'K1A 0B1'];
        yield 'slug' => [Rules::slug(), 'my-slug-1'];
        yield 'noWhitespace' => [Rules::noWhitespace(), 'abc'];
        yield 'semver' => [Rules::semver(), '1.2.3-rc.1+build.5'];
        yield 'ulid' => [Rules::ulid(), '01ARZ3NDEKTSV4RRFFQ69G5FAV'];
        yield 'sha256' => [Rules::sha256(), str_repeat('a', 64)];
        yield 'pathSegment' => [Rules::pathSegment(), 'file.name_1'];
        yield 'safeFilename' => [Rules::safeFilename(), 'report-2026.pdf'];
        yield 'fileExtension' => [Rules::fileExtension(), 'pdf'];
        yield 'percentEncoded' => [Rules::percentEncoded(), 'a%20b-c'];
        yield 'httpVersion' => [Rules::httpVersion(), 'HTTP/1.1'];
        yield 'mimeType' => [Rules::mimeType(), 'text/plain'];
        yield 'bearerToken' => [Rules::bearerToken(), 'abc.def-ghi'];
        yield 'languageTag' => [Rules::languageTag(), 'en-CA'];
        yield 'httpHeaderName' => [Rules::httpHeaderName(), 'X-Request-Id'];
        yield 'httpHeaderValue' => [Rules::httpHeaderValue(), 'text/plain; charset=utf-8'];
        yield 'jwt' => [Rules::jwt(), 'aaa.bbb.ccc'];
        yield 'hostPort' => [Rules::hostPort(), '[::1]:8080'];
        yield 'countryCode' => [Rules::countryCode(), 'CA'];
        yield 'locale' => [Rules::locale(), 'fr_CA'];
        yield 'uuidV1toV5' => [Rules::uuidV1toV5(), '123e4567-e89b-12d3-a456-426614174000'];
        yield 'uuidV4' => [Rules::uuidV4(), '123e4567-e89b-42d3-a456-426614174000'];
        yield 'uuidV6' => [Rules::uuidV6(), '123e4567-e89b-62d3-a456-426614174000'];
        yield 'uuidV7' => [Rules::uuidV7(), '123e4567-e89b-72d3-a456-426614174000'];
        yield 'uuidV8' => [Rules::uuidV8(), '123e4567-e89b-82d3-a456-426614174000'];
        yield 'currencyCode' => [Rules::currencyCode(), 'EUR'];
        yield 'iban' => [Rules::iban(), 'DE89370400440532013000'];
        yield 'bic' => [Rules::bic(), 'DEUTDEFF'];
        yield 'cardCvv' => [Rules::cardCvv(), '123'];
        yield 'cardExpiryMmyy' => [Rules::cardExpiryMmyy(), '12/29'];
        yield 'cardExpiryMmyyyy' => [Rules::cardExpiryMmyyyy(), '12/2029'];
        yield 'etag' => [Rules::etag(), '"abc"'];
        yield 'contentRange' => [Rules::contentRange(), 'bytes 0-499/1234'];
    }

    #[DataProvider('anchoredRuleSamples')]
    public function testRuleAcceptsValidValueAndRefusesTrailingNewline(Rule $rule, string $valid): void
    {
        self::assertTrue($rule->test($valid));
        self::assertFalse($rule->test($valid . "\n"));
    }
}
