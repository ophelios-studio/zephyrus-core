<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tracy\Debugger;
use Zephyrus\Core\DebugIntegration;

final class DebugIntegrationAllowlistTest extends TestCase
{
    /** @return iterable<string, array{string|array<int, mixed>|null}> */
    public static function listsNamingNoClient(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'separators only' => [' , '];
        yield 'array entry is empty' => [['']];
        yield 'array entry is a space' => [[' ']];
    }

    #[DataProvider('listsNamingNoClient')]
    public function testAllowedClientListIsNullWhenNoClientIsNamed(string|array|null $clients): void
    {
        self::assertNull(DebugIntegration::allowedClientList($clients));
    }

    public function testAllowedClientListReturnsTheEntriesOfAStringInOrder(): void
    {
        self::assertSame(['a@1.1.1.1', 'b@2.2.2.2'], DebugIntegration::allowedClientList('a@1.1.1.1, b@2.2.2.2'));
    }

    public function testAllowedClientListDropsAnArrayEntryMadeOfSeparators(): void
    {
        self::assertSame(['s@1.1.1.1'], DebugIntegration::allowedClientList([' ', 's@1.1.1.1']));
    }

    public function testAllowedClientListAcceptsASlashInTheSecret(): void
    {
        self::assertSame(
            ['k9/Xq2+ab==@172.17.0.1'],
            DebugIntegration::allowedClientList('k9/Xq2+ab==@172.17.0.1'),
        );
    }

    public function testAllowedClientListNamesThePositionOfAnEntryAfterAnEmptyOne(): void
    {
        try {
            DebugIntegration::allowedClientList(['', '@1.2.3.4']);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 2 has an empty secret before "@": with no tracy-debug cookie it would '
                . 'admit every client at that address. Set a secret, or list the bare address if that is intended.',
                $e->getMessage(),
            );
        }
    }

    public function testAllowedClientListRefusesARangeBehindASecret(): void
    {
        try {
            DebugIntegration::allowedClientList('s@10.0.0.0/8');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 1 is a range: Tracy matches exact addresses only, so list each address.',
                $e->getMessage(),
            );
        }
    }

    public function testARefusalFromAllowedClientListTraceNeverCarriesASecret(): void
    {
        $previous = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            DebugIntegration::allowedClientList('goodsecret9longer@172.17.0.1, topsecretlonger1@10.0.0.0/8');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 2 is a range: Tracy matches exact addresses only, so list each address.',
                $e->getMessage(),
            );
            $trace = print_r($e->getTrace(), true);
            self::assertStringNotContainsString('goodsecret9longer', $trace);
            self::assertStringNotContainsString('topsecretlonger1', $trace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    #[RunInSeparateProcess]
    public function testInitializeRefusesAnEmptySecretBeforeEnablingTracy(): void
    {
        try {
            DebugIntegration::initialize(debug: true, allowedClients: '@172.17.0.1');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 1 has an empty secret before "@": with no tracy-debug cookie it would '
                . 'admit every client at that address. Set a secret, or list the bare address if that is intended.',
                $e->getMessage(),
            );
        }

        self::assertFalse(Debugger::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testARefusalFromInitializeTraceNeverCarriesASecret(): void
    {
        $previous = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            DebugIntegration::initialize(debug: true, allowedClients: 'goodsecret9longer@172.17.0.1, topsecretlonger1@10.0.0.0/8');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 2 is a range: Tracy matches exact addresses only, so list each address.',
                $e->getMessage(),
            );
            $trace = print_r($e->getTrace(), true);
            self::assertStringNotContainsString('goodsecret9longer', $trace);
            self::assertStringNotContainsString('topsecretlonger1', $trace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
}
