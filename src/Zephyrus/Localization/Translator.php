<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

use Zephyrus\Core\App;
use Zephyrus\Formatting\Formatter;

final class Translator
{
    /** @var array<string, array<string, mixed>> */
    private array $catalogCache = [];

    public function __construct(
        private readonly LocaleLoaderInterface $loader,
        private readonly string $defaultLocale = 'en'
    ) {
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function trans(string $key, array $parameters = [], ?string $locale = null): string
    {
        foreach ($this->resolveLocaleChain($locale ?? $this->defaultLocale) as $candidateLocale) {
            $catalog = $this->catalog($candidateLocale);
            $value = $this->resolveKey($key, $catalog);
            if ($value !== null) {
                return $this->interpolate($value, $parameters);
            }
        }

        return $this->interpolate($key, $parameters);
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
     * Resolve a dot-notation key by traversing the nested catalog array.
     *
     * Returns the string value if found, or null if the key does not exist
     * or resolves to a non-scalar value (e.g. an intermediate array node).
     *
     * @param array<string, mixed> $catalog
     */
    private function resolveKey(string $key, array $catalog): ?string
    {
        // Fast path: direct key match (for flat catalogs or top-level keys)
        if (array_key_exists($key, $catalog)) {
            $value = $catalog[$key];
            return (is_scalar($value) || $value === null) ? (string) $value : null;
        }

        // Dot-notation traversal for nested catalogs
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
    private function interpolate(string $value, array $parameters): string
    {
        if ($parameters === []) {
            return $value;
        }

        return preg_replace_callback('/\{([a-zA-Z0-9_]+)(\|[^}]+)?\}/', function (array $matches) use ($parameters): string {
            $name = $matches[1];
            $pipeExpression = $matches[2] ?? '';

            if (!array_key_exists($name, $parameters)) {
                return $matches[0];
            }

            $resolved = (string) $parameters[$name];

            if ($pipeExpression !== '') {
                $resolved = $this->applyPipes($resolved, ltrim($pipeExpression, '|'));
            }

            return $resolved;
        }, $value) ?? $value;
    }

    private function applyPipes(string $value, string $pipeExpression): string
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
                'plural'   => $this->applyPlural($current, $pipeArgument),
                'default'  => ($current === '' ? ($pipeArgument ?? '') : $current),
                default    => $this->applyFormatterPipe($pipeName, $current),
            };
        }

        return $current;
    }

    /**
     * Format a numeric value.
     *
     * Argument format: precision[:thousands_sep[:decimal_sep]]
     *   number:2        → 12.35   (no thousands separator, dot decimal)
     *   number:2:,:.    → 1,234.56
     *   number:0:_      → 1_234
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
     * Truncate a string to at most $length multibyte characters.
     *
     * Argument format: length[:suffix]
     *   truncate:10       → appends "…" when truncated
     *   truncate:10:...   → appends "..." when truncated
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
     * Return the singular or plural form based on the numeric value.
     *
     * Argument format: singular:plural
     *   plural:item:items   → "item" when |value| == 1, else "items"
     *   plural:child:children
     *
     * When no plural form is given, an "s" is appended to the singular.
     */
    private function applyPlural(string $value, ?string $argument): string
    {
        if ($argument === null) {
            return $value;
        }

        $parts    = explode(':', $argument, 2);
        $singular = $parts[0];
        $plural   = $parts[1] ?? $singular . 's';

        $numeric = is_numeric($value) ? abs((float) $value) : 1.0;

        return (abs($numeric - 1.0) < PHP_FLOAT_EPSILON) ? $singular : $plural;
    }

    /**
     * Delegate an unknown pipe to the Formatter registered in App.
     *
     * Supports both built-in Formatter methods (money, date, decimal, …) and
     * custom formatters registered via Formatter::register().
     *
     * If no Formatter is available or the method/custom formatter does not
     * exist, the value passes through unchanged.
     */
    private function applyFormatterPipe(string $pipeName, string $value): string
    {
        $formatter = App::getFormatter();
        if ($formatter === null) {
            return $value;
        }

        // Try built-in Formatter methods first (money, date, decimal, …).
        if (method_exists($formatter, $pipeName)) {
            try {
                $castValue = is_numeric($value) ? (float) $value : $value;
                return $formatter->$pipeName($castValue);
            } catch (\Throwable) {
                return $value;
            }
        }

        // Try custom registered formatters.
        try {
            return $formatter->format($pipeName, $value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
