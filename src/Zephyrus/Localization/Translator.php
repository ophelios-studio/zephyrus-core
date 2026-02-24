<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

final class Translator
{
    /** @var array<string, array<string, string>> */
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
            if (array_key_exists($key, $catalog)) {
                return $this->interpolate($catalog[$key], $parameters);
            }
        }

        return $this->interpolate($key, $parameters);
    }

    /**
     * @return array<int, string>
     */
    private function resolveLocaleChain(string $requestedLocale): array
    {
        $chain = [$requestedLocale];

        if (str_contains($requestedLocale, '-')) {
            $chain[] = explode('-', $requestedLocale, 2)[0];
        }

        if (!in_array($this->defaultLocale, $chain, true)) {
            $chain[] = $this->defaultLocale;
        }

        if (str_contains($this->defaultLocale, '-')) {
            $defaultBase = explode('-', $this->defaultLocale, 2)[0];
            if (!in_array($defaultBase, $chain, true)) {
                $chain[] = $defaultBase;
            }
        }

        return $chain;
    }

    /**
     * @return array<string, string>
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
                'lower' => mb_strtolower($current),
                'upper' => mb_strtoupper($current),
                'title' => mb_convert_case($current, MB_CASE_TITLE),
                'trim' => trim($current),
                'number' => $this->formatNumber($current, $pipeArgument),
                default => $current,
            };
        }

        return $current;
    }

    private function formatNumber(string $value, ?string $decimals): string
    {
        if (!is_numeric($value)) {
            return $value;
        }

        $precision = (int) ($decimals ?? 0);
        if ($precision < 0) {
            $precision = 0;
        }

        return number_format((float) $value, $precision, '.', '');
    }
}
