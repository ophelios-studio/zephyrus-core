<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Routing\Exception\RouteSignatureException;

final readonly class RouteSignature
{
    public function __construct(private string $secret)
    {
    }

    public function sign(string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . '_sig=' . $this->compute($url);
    }

    public function verify(string $url): bool
    {
        [$payload, $signature] = $this->split($url);

        return hash_equals($this->compute($payload), $signature);
    }

    public function assertValid(string $url): void
    {
        if (!$this->verify($url)) {
            throw new RouteSignatureException('Invalid route signature');
        }
    }

    private function compute(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $url): array
    {
        $parts = parse_url($url);
        $query = $parts['query'] ?? '';

        parse_str($query, $params);

        if (!isset($params['_sig']) || !is_string($params['_sig'])) {
            return ['', ''];
        }

        $signature = $params['_sig'];
        unset($params['_sig']);

        $base = '';

        if (isset($parts['scheme'])) {
            $base .= $parts['scheme'] . '://';
        }

        if (isset($parts['host'])) {
            $base .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        $base .= $parts['path'] ?? '';

        if ($params !== []) {
            ksort($params);
            $base .= '?' . http_build_query($params, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
        }

        return [$base, $signature];
    }
}
