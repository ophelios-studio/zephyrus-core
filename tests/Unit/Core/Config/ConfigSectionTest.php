<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigSection;

final class ConfigSectionTest extends TestCase
{
    public function testGetReturnsValueByKey(): void
    {
        $section = new class(['name' => 'TestApp', 'version' => 2]) extends ConfigSection {
        };

        self::assertSame('TestApp', $section->get('name'));
        self::assertSame(2, $section->get('version'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $section = new class([]) extends ConfigSection {
        };

        self::assertSame('fallback', $section->get('missing', 'fallback'));
        self::assertNull($section->get('missing'));
    }

    public function testGetSupportsDotNotation(): void
    {
        $section = new class([
            'smtp' => [
                'host' => 'mail.example.com',
                'port' => 587,
            ],
        ]) extends ConfigSection {
        };

        self::assertSame('mail.example.com', $section->get('smtp.host'));
        self::assertSame(587, $section->get('smtp.port'));
        self::assertNull($section->get('smtp.missing'));
    }

    public function testGetStringReturnsTypedValue(): void
    {
        $section = new class(['name' => 'Test', 'count' => 42]) extends ConfigSection {
        };

        self::assertSame('Test', $section->getString('name'));
        self::assertSame('42', $section->getString('count'));
        self::assertSame('default', $section->getString('missing', 'default'));
    }

    public function testGetIntReturnsTypedValue(): void
    {
        $section = new class(['port' => '8080', 'rate' => 3.14]) extends ConfigSection {
        };

        self::assertSame(8080, $section->getInt('port'));
        self::assertSame(3, $section->getInt('rate'));
        self::assertSame(99, $section->getInt('missing', 99));
    }

    public function testGetFloatReturnsTypedValue(): void
    {
        $section = new class(['rate' => '3.14']) extends ConfigSection {
        };

        self::assertSame(3.14, $section->getFloat('rate'));
        self::assertSame(1.5, $section->getFloat('missing', 1.5));
    }

    public function testGetBoolReturnsTypedValue(): void
    {
        $section = new class([
            'enabled' => true,
            'disabled' => false,
            'yes_str' => '1',
            'no_str' => '0',
        ]) extends ConfigSection {
        };

        self::assertTrue($section->getBool('enabled'));
        self::assertFalse($section->getBool('disabled'));
        self::assertTrue($section->getBool('yes_str'));
        self::assertFalse($section->getBool('no_str'));
        self::assertTrue($section->getBool('missing', true));
    }

    public function testGetArrayReturnsTypedValue(): void
    {
        $section = new class([
            'items' => ['a', 'b', 'c'],
            'scalar' => 'not-array',
        ]) extends ConfigSection {
        };

        self::assertSame(['a', 'b', 'c'], $section->getArray('items'));
        self::assertSame([], $section->getArray('scalar'));
        self::assertSame(['default'], $section->getArray('missing', ['default']));
    }

    public function testHasChecksKeyExistence(): void
    {
        $section = new class(['name' => 'Test']) extends ConfigSection {
        };

        self::assertTrue($section->has('name'));
        self::assertFalse($section->has('missing'));
    }

    public function testNormalizesSnakeCaseKeys(): void
    {
        $section = new class([
            'app_name' => 'Test',
            'api_rate_limit' => 100,
        ]) extends ConfigSection {
        };

        self::assertSame('Test', $section->get('appName'));
        self::assertSame(100, $section->get('apiRateLimit'));
    }

    public function testToArrayReturnsNormalizedValues(): void
    {
        $section = new class(['key' => 'value']) extends ConfigSection {
        };

        self::assertSame(['key' => 'value'], $section->toArray());
    }

    public function testNestedSnakeCaseNormalization(): void
    {
        $section = new class([
            'smtp_config' => [
                'mail_host' => 'example.com',
                'mail_port' => 465,
            ],
        ]) extends ConfigSection {
        };

        self::assertSame('example.com', $section->get('smtpConfig.mailHost'));
        self::assertSame(465, $section->get('smtpConfig.mailPort'));
    }
}
