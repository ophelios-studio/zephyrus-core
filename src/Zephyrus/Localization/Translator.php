<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

use Zephyrus\Core\App;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Formatting\FormatterException;

/**
 * Translates dot-notation keys from the catalogs of a LocaleLoaderInterface.
 *
 * Lookup order: the requested locale, its base language (fr-CA, then fr), the default locale, then the default's
 * base language. A missing key returns the key itself, interpolated like a value. Catalogs are cached per instance.
 *
 * Placeholders are {name} or {name|pipe|pipe:argument}. A placeholder without a parameter stays verbatim.
 * Pipes, applied left to right: lower, upper, title, trim, ltrim, rtrim, number, truncate, plural, default, then
 * any Formatter name (built-in or registered), which needs a Formatter set in App.
 */
final class Translator
{
    private const TEXT_PIPES = [
        'lower', 'upper', 'title', 'trim', 'ltrim', 'rtrim', 'number', 'truncate', 'plural', 'default',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $catalogCache = [];

    /** @var array<string, ?\MessageFormatter> Null when the locale falls back to "exactly 1 is singular". */
    private array $pluralSelectors = [];

    public function __construct(
        private readonly LocaleLoaderInterface $loader,
        private readonly string $defaultLocale = 'en'
    ) {
    }

    /**
     * Returns the translation of $key, for $locale or the default locale.
     *
     * @param array<string, scalar|null> $parameters
     *
     * @throws LocalizationException When the loader fails, a pipe is unknown, or a formatter pipe needs
     *                               App::setFormatter() or is rejected by a built-in formatter. A custom
     *                               formatter's own exception propagates unchanged.
     */
    public function trans(string $key, array $parameters = [], ?string $locale = null): string
    {
        $chain = $this->resolveLocaleChain($locale ?? $this->defaultLocale);

        foreach ($chain as $candidateLocale) {
            $catalog = $this->catalog($candidateLocale);
            $value = $this->resolveKey($key, $catalog);
            if ($value !== null) {
                return $this->interpolate($key, $value, $parameters, $candidateLocale);
            }
        }

        return $this->interpolate($key, $key, $parameters, $chain[0]);
    }

    /**
     * @return array<int, string>
     */
    private function resolveLocaleChain(string $requestedLocale): array
    {
        $normalizedDefault = $this->normalizeLocale($this->defaultLocale);
        if ($normalizedDefault === '') {
            $normalizedDefault = 'en';
        }

        $normalizedRequested = $this->normalizeLocale($requestedLocale);
        if ($normalizedRequested === '') {
            $normalizedRequested = $normalizedDefault;
        }

        $chain = [$normalizedRequested];

        $requestedBase = $this->baseLocale($normalizedRequested);
        if ($requestedBase !== $normalizedRequested) {
            $chain[] = $requestedBase;
        }

        if (!in_array($normalizedDefault, $chain, true)) {
            $chain[] = $normalizedDefault;
        }

        $defaultBase = $this->baseLocale($normalizedDefault);
        if ($defaultBase !== $normalizedDefault && !in_array($defaultBase, $chain, true)) {
            $chain[] = $defaultBase;
        }

        return $chain;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim(str_replace('_', '-', $locale));
        if ($locale === '') {
            return '';
        }

        $parts = explode('-', $locale, 2);
        $normalized = strtolower($parts[0]);

        if (isset($parts[1])) {
            $normalized .= '-' . strtoupper($parts[1]);
        }

        return $normalized;
    }

    private function baseLocale(string $locale): string
    {
        return explode('-', $locale, 2)[0];
    }

    /**
     * Returns the scalar at $key, or null when missing or not scalar (e.g. an intermediate array node).
     *
     * @param array<string, mixed> $catalog
     */
    private function resolveKey(string $key, array $catalog): ?string
    {
        // A literal key containing dots wins over dot-notation traversal.
        if (array_key_exists($key, $catalog)) {
            $value = $catalog[$key];
            return (is_scalar($value) || $value === null) ? (string) $value : null;
        }

        $segments = explode('.', $key);
        $current = $catalog;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return (is_scalar($current) || $current === null) ? (string) $current : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(string $locale): array
    {
        if (!array_key_exists($locale, $this->catalogCache)) {
            $this->catalogCache[$locale] = $this->loader->load($locale);
        }

        return $this->catalogCache[$locale];
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function interpolate(string $key, string $value, array $parameters, string $locale): string
    {
        if ($parameters === []) {
            return $value;
        }

        return preg_replace_callback('/\{([a-zA-Z0-9_]+)(\|[^}]+)?\}/', function (array $matches) use ($key, $parameters, $locale): string {
            $name = $matches[1];
            $pipeExpression = $matches[2] ?? '';

            if (!array_key_exists($name, $parameters)) {
                return $matches[0];
            }

            $resolved = (string) $parameters[$name];

            if ($pipeExpression !== '') {
                $resolved = $this->applyPipes($key, $resolved, ltrim($pipeExpression, '|'), $locale);
            }

            return $resolved;
        }, $value) ?? $value;
    }

    private function applyPipes(string $key, string $value, string $pipeExpression, string $locale): string
    {
        $current = $value;

        foreach (explode('|', $pipeExpression) as $pipeSegment) {
            $pipeSegment = trim($pipeSegment);
            if ($pipeSegment === '') {
                continue;
            }

            [$pipeName, $pipeArgument] = array_pad(explode(':', $pipeSegment, 2), 2, null);

            $current = match (strtolower($pipeName)) {
                'lower'    => mb_strtolower($current),
                'upper'    => mb_strtoupper($current),
                'title'    => mb_convert_case($current, MB_CASE_TITLE),
                'trim'     => trim($current),
                'ltrim'    => ltrim($current),
                'rtrim'    => rtrim($current),
                'number'   => $this->formatNumber($current, $pipeArgument),
                'truncate' => $this->applyTruncate($current, $pipeArgument),
                'plural'   => $this->applyPlural($current, $pipeArgument, $locale),
                'default'  => ($current === '' ? ($pipeArgument ?? '') : $current),
                default    => $this->applyFormatterPipe($key, $pipeName, $current),
            };
        }

        return $current;
    }

    /**
     * Formats a numeric value; a non-numeric value is returned unchanged.
     *
     * Argument: precision[:thousands_sep[:decimal_sep]], e.g. "2:,:." gives 1,234.56. Defaults: precision 0, no
     * thousands separator, "." decimal separator.
     */
    private function formatNumber(string $value, ?string $argument): string
    {
        if (!is_numeric($value)) {
            return $value;
        }

        $parts        = $argument !== null ? explode(':', $argument, 3) : [];
        $precision    = max(0, (int) ($parts[0] ?? 0));
        $thousandsSep = $parts[1] ?? '';
        $decimalSep   = $parts[2] ?? '.';

        return number_format((float) $value, $precision, $decimalSep, $thousandsSep);
    }

    /**
     * Truncates to at most $length characters, then appends the suffix, so the result can exceed $length.
     *
     * Argument: length[:suffix], the suffix defaulting to an ellipsis. A length of 0 or less never truncates.
     */
    private function applyTruncate(string $value, ?string $argument): string
    {
        if ($argument === null) {
            return $value;
        }

        $parts  = explode(':', $argument, 2);
        $length = (int) $parts[0];
        $suffix = $parts[1] ?? '…';

        if ($length <= 0 || mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length) . $suffix;
    }

    /**
     * Returns the singular or plural form, using the plural rules of the locale.
     *
     * Argument: singular[:plural], e.g. "item:items". Without a plural, an "s" is appended to the singular.
     * A non-numeric value always takes the singular.
     */
    private function applyPlural(string $value, ?string $argument, string $locale): string
    {
        if ($argument === null) {
            return $value;
        }

        $parts    = explode(':', $argument, 2);
        $singular = $parts[0];
        $plural   = $parts[1] ?? $singular . 's';

        if (!is_numeric($value)) {
            return $singular;
        }

        return $this->takesSingular(abs((float) $value), $locale) ? $singular : $plural;
    }

    /**
     * Whether $number takes the singular, by the ICU plural rules of $locale; exactly 1 when ICU has none usable.
     */
    private function takesSingular(float $number, string $locale): bool
    {
        if (!array_key_exists($locale, $this->pluralSelectors)) {
            $this->pluralSelectors[$locale] = $this->createPluralSelector($locale);
        }

        $selector = $this->pluralSelectors[$locale];
        if ($selector === null) {
            return abs($number - 1.0) < PHP_FLOAT_EPSILON;
        }

        return $selector->format([$number]) === 'one';
    }

    /**
     * Null unless ICU accepts the locale and classifies 1 as "one".
     */
    private function createPluralSelector(string $locale): ?\MessageFormatter
    {
        try {
            $selector = new \MessageFormatter($locale, '{0, plural, one{one} other{other}}');
        } catch (\IntlException) {
            return null;
        }

        return $selector->format([1.0]) === 'one' ? $selector : null;
    }

    /**
     * @param list<string> $customNames
     * @return list<string>
     */
    private function validPipeNames(array $customNames): array
    {
        return array_values(array_unique([...self::TEXT_PIPES, ...Formatter::BUILT_IN_FORMATTERS, ...$customNames]));
    }

    private function isBuiltInFormatterName(string $pipeName): bool
    {
        return in_array(strtolower($pipeName), array_map(strtolower(...), Formatter::BUILT_IN_FORMATTERS), true);
    }

    /**
     * Delegates an unknown pipe to the Formatter set in App, built-in or custom.
     *
     * Throws LocalizationException when no formatter answers to the name, or a built-in name is used while no
     * Formatter is set. An empty value is returned as is, so a following default pipe applies.
     *
     * @throws LocalizationException
     */
    private function applyFormatterPipe(string $key, string $pipeName, string $value): string
    {
        $formatter = App::getFormatter();
        if ($formatter === null) {
            if (!$this->isBuiltInFormatterName($pipeName)) {
                throw LocalizationException::unknownPipe($pipeName, $key, $this->validPipeNames([]));
            }

            throw LocalizationException::formatterRequired($pipeName, $key);
        }

        $isCustom = $formatter->hasCustomFormatter($pipeName);
        if (!$formatter->has($pipeName)) {
            throw LocalizationException::unknownPipe(
                $pipeName,
                $key,
                $this->validPipeNames($formatter->getCustomFormatterNames()),
            );
        }

        if ($value === '') {
            return $value;
        }

        if ($isCustom) {
            return $formatter->format($pipeName, $value);
        }

        try {
            return $formatter->format($pipeName, $this->castPipeValue($value));
        } catch (\TypeError|FormatterException $e) {
            throw LocalizationException::formatterPipeFailed($pipeName, $key, $e);
        }
    }

    /**
     * Passes numeric strings as int or float, since built-in formatters are typed under strict types.
     */
    private function castPipeValue(string $value): int|float|string
    {
        if (!is_numeric($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : (float) $value;
    }
}
