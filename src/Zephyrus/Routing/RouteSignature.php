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
        [$payload] = $this->split($url);
        $signature = $this->compute($payload);

        return $this->appendSignature($payload, $signature, $this->extractFragment($url));
    }

    public function signTemporary(string $url, int $ttlSeconds, ?int $now = null): string
    {
        if ($ttlSeconds <= 0) {
            throw new RouteSignatureException('Temporary signature TTL must be greater than zero seconds');
        }

        [$payload] = $this->split($url);
        $expiresAt = ($now ?? time()) + $ttlSeconds;
        $payloadWithExpiry = $this->appendQueryParameter($payload, '_exp', (string) $expiresAt);
        [$canonicalPayloadWithExpiry] = $this->split($payloadWithExpiry);
        $signature = $this->compute($canonicalPayloadWithExpiry);

        return $this->appendSignature($canonicalPayloadWithExpiry, $signature, $this->extractFragment($url));
    }

    public function verify(string $url): bool
    {
        return $this->verifyAt($url);
    }

    public function verifyAt(string $url, ?int $now = null): bool
    {
        [$payload, $signature, $expiry] = $this->split($url);

        if ($signature === '') {
            return false;
        }

        if ($expiry !== null) {
            if (!$this->isValidExpiry($expiry)) {
                return false;
            }

            if (($now ?? time()) > (int) $expiry) {
                return false;
            }
        }

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
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function split(string $url): array
    {
        $parts = parse_url($url);
        $query = $parts['query'] ?? '';

        parse_str($query, $params);

        $signature = '';
        if (isset($params['_sig']) && is_string($params['_sig'])) {
            $signature = $params['_sig'];
        }

        $expiry = null;
        if (isset($params['_exp']) && is_string($params['_exp'])) {
            $expiry = $params['_exp'];
        }

        unset($params['_sig']);

        $base = $this->buildBaseUrl($parts);

        if ($params !== []) {
            ksort($params);
            $base .= '?' . http_build_query($params, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
        }

        return [$base, $signature, $expiry];
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function buildBaseUrl(array $parts): string
    {
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

        if (isset($parts['path'])) {
            $base .= $parts['path'];
        } elseif (!isset($parts['scheme']) && !isset($parts['host'])) {
            $base .= '';
        }

        return $base;
    }

    private function extractFragment(string $url): ?string
    {
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        return is_string($fragment) && $fragment !== '' ? $fragment : null;
    }

    private function appendSignature(string $payload, string $signature, ?string $fragment): string
    {
        $signed = $this->appendQueryParameter($payload, '_sig', $signature);

        if ($fragment === null) {
            return $signed;
        }

        return $signed . '#' . $fragment;
    }

    private function appendQueryParameter(string $url, string $key, string $value): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . $key . '=' . rawurlencode($value);
    }

    private function isValidExpiry(string $expiry): bool
    {
        return preg_match('/^\d+$/', $expiry) === 1;
    }
}
