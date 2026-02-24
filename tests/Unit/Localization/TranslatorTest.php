<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;
use Zephyrus\Localization\JsonLocaleLoader;
use Zephyrus\Localization\Translator;

final class TranslatorTest extends TestCase
{
    public function testTranslatesFromRequestedLocale(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Bonjour', $translator->trans('messages.plain', locale: 'fr'));
    }

    public function testFallsBackToDefaultLocaleWhenKeyMissingInRequestedLocale(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Welcome Alice', $translator->trans('messages.welcome', ['name' => 'Alice'], 'fr'));
    }

    public function testReturnsKeyWhenMissingInAllCatalogs(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('unknown.key', $translator->trans('unknown.key', locale: 'fr'));
    }

    public function testInterpolatesParameters(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('The email field is required', $translator->trans('errors.required', ['field' => 'email']));
    }

    public function testFallsBackFromRegionalLocaleToLanguageLocale(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Bonjour', $translator->trans('messages.plain', locale: 'fr-CA'));
    }

    public function testInterpolationSupportsTextPipes(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame(
            'Hello ALICE / alice / Alice',
            $translator->trans('messages.pipe_text', ['name' => 'alice']),
        );
    }

    public function testInterpolationSupportsNumberPipeWithPrecision(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame(
            'Invoice total: 12.35',
            $translator->trans('messages.pipe_number', ['total' => 12.3456]),
        );
    }

    public function testInterpolationLeavesUnknownParameterPlaceholderUntouched(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Welcome {name}', $translator->trans('messages.welcome'));
    }

    public function testInterpolationIgnoresUnknownPipeName(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Value: alice', $translator->trans('messages.pipe_unknown', ['name' => 'alice']));
    }

    private function buildTranslator(): Translator
    {
        $loader = new JsonLocaleLoader(__DIR__ . '/../../Fixtures/locales');

        return new Translator($loader, 'en');
    }
}
