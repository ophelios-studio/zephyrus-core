<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\ApplicationBuilder;

final class ApplicationBuilderDebugAllowlistTest extends TestCase
{
    /** @return iterable<string, array{string|array<int, mixed>|null}> */
    public static function acceptedEntries(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'separators only' => [' , '];
        yield 'empty array' => [[]];
        yield 'empty array entry' => [['']];
        yield 'array entry of separators only' => [[' ', 's@1.1.1.1']];
        yield 'secret with a slash and a base64 padding' => ['k9/Xq2+ab==@172.17.0.1'];
        yield 'secret and address' => ['s3cret@172.17.0.1'];
        yield 'bare address' => ['10.0.0.5'];
        yield 'two entries split on a comma and a space' => ['a@1.1.1.1, b@2.2.2.2'];
        yield 'array of a secret entry and a bare IPv6 address' => [['a@1.1.1.1', '::1']];
    }

    #[DataProvider('acceptedEntries')]
    public function testAcceptsEntriesTracyCanMatch(string|array|null $clients): void
    {
        self::assertInstanceOf(ApplicationBuilder::class, ApplicationBuilder::create()->withDebugClientAllowlist($clients));
    }

    public function testRefusesAnEmptySecretBecauseItAdmitsEveryClientAtTheAddress(): void
    {
        try {
            ApplicationBuilder::create()->withDebugClientAllowlist('@172.17.0.1');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 1 has an empty secret before "@": with no tracy-debug cookie it would '
                . 'admit every client at that address. Set a secret, or list the bare address if that is intended.',
                $e->getMessage(),
            );
        }
    }

    public function testRefusesAnEmptySecretInASplitStringAtItsPosition(): void
    {
        try {
            ApplicationBuilder::create()->withDebugClientAllowlist('a@1.1.1.1, @172.17.0.1');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 2 has an empty secret before "@": with no tracy-debug cookie it would '
                . 'admit every client at that address. Set a secret, or list the bare address if that is intended.',
                $e->getMessage(),
            );
        }
    }

    public function testRefusesAnEntryWithNoAddressAfterTheAt(): void
    {
        try {
            ApplicationBuilder::create()->withDebugClientAllowlist('s3cret@');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Debug client allowlist entry 1 has no address after "@".', $e->getMessage());
        }
    }

    public function testRefusesARangeBecauseTracyMatchesExactAddresses(): void
    {
        try {
            ApplicationBuilder::create()->withDebugClientAllowlist('10.0.0.0/8');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 1 is a range: Tracy matches exact addresses only, so list each address.',
                $e->getMessage(),
            );
        }
    }

    public function testRefusesAnArrayEntryHoldingWhitespaceOrAComma(): void
    {
        try {
            ApplicationBuilder::create()->withDebugClientAllowlist(['a@1.1.1.1', '10.0.0.5 ', '10.0.0.6']);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 2 holds whitespace or a comma: pass each address as its own array entry.',
                $e->getMessage(),
            );
        }
    }

    public function testRefusesAnArrayEntryHoldingAComma(): void
    {
        try {
            ApplicationBuilder::create()->withDebugClientAllowlist(['a@1.1.1.1,b@2.2.2.2']);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 1 holds whitespace or a comma: pass each address as its own array entry.',
                $e->getMessage(),
            );
        }
    }

    public function testRefusesANonStringArrayEntry(): void
    {
        try {
            ApplicationBuilder::create()->withDebugClientAllowlist(['a@1.1.1.1', 42]);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Debug client allowlist entry 2 is not a string.', $e->getMessage());
        }
    }

    public function testARefusalNeverPrintsTheSecret(): void
    {
        foreach (['topsecret@', 'topsecret@10.0.0.0/8', '@topsecret'] as $entry) {
            try {
                ApplicationBuilder::create()->withDebugClientAllowlist($entry);
                self::fail('expected a refusal');
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString('topsecret', $e->getMessage());
            }
        }
    }

    public function testARefusalTraceNeverCarriesASecret(): void
    {
        $previous = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            ApplicationBuilder::create()->withDebugClientAllowlist('goodsecret9longer@172.17.0.1, topsecretlonger1@10.0.0.0/8');
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
