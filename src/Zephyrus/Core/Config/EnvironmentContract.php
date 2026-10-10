<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Throwable;
use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Security\SecureHeadersConfig;

/**
 * Declares the environment variables an application needs and stops the process at boot
 * when one is missing or invalid. Unknown values must fail closed, not run with a default.
 *
 * Usage, as the first call of the entry point, before the kernel or any middleware exists:
 *
 *   EnvironmentContract::create()
 *       ->requireOneOf('APP_ENV', ['dev', 'staging', 'production'])
 *       ->requireOneOf('MODE', ['WEB', 'API'], canonicalise: true)
 *       ->requireSet('DATABASE_URL')
 *       ->requireBase64Bytes('ENCRYPTION_KEY', 32)
 *       ->enforce();
 *
 * Under CLI a refusal writes the reasons to stderr and exits 78 (EX_CONFIG). Under a web SAPI
 * it answers 500 with an empty body and security headers.
 *
 * Standalone, not part of KernelBuilder: it must run before anything can print, and worker
 * entry points never build a kernel.
 *
 * Reasons name the variable; only requireOneOf echoes the rejected value, so never use it for a secret.
 * A base64 length mismatch reports the decoded length only, so no secret or prefix of it reaches
 * the log or the response.
 */
final class EnvironmentContract
{
    private const RULE_SET = 'set';
    private const RULE_ONE_OF = 'one_of';
    private const RULE_BASE64_BYTES = 'base64_bytes';

    /** Exit code for a configuration failure, sysexits.h EX_CONFIG. */
    public const EXIT_CONFIG = 78;

    /** @var list<array<string, mixed>> */
    private array $rules = [];

    public static function create(): self
    {
        return new self();
    }

    /**
     * The variable must be present and not blank.
     */
    public function requireSet(string $name): self
    {
        return $this->withRule([
            'type' => self::RULE_SET,
            'name' => $name,
        ]);
    }

    /**
     * The variable must be present and match one of the permitted values, after trimming.
     *
     * Case is ignored unless $caseSensitive is true.
     *
     * @param list<string> $allowed
     * @param bool $canonicalise When true, a valid value is written back to $_ENV, $_SERVER and
     *   putenv() as the allowlist literal, so strict and normalising readers agree.
     */
    public function requireOneOf(
        string $name,
        array $allowed,
        bool $caseSensitive = false,
        bool $canonicalise = false,
    ): self {
        return $this->withRule([
            'type' => self::RULE_ONE_OF,
            'name' => $name,
            'allowed' => array_values($allowed),
            'caseSensitive' => $caseSensitive,
            'canonicalise' => $canonicalise,
        ]);
    }

    /**
     * The variable must be valid base64 and decode to exactly $bytes bytes (e.g. 32 for a
     * libsodium secretbox key).
     */
    public function requireBase64Bytes(string $name, int $bytes): self
    {
        return $this->withRule([
            'type' => self::RULE_BASE64_BYTES,
            'name' => $name,
            'bytes' => $bytes,
        ]);
    }

    /**
     * Every reason the process must not start, or an empty list when it may.
     *
     * Pure: pass $values to evaluate a given environment, omit it to read the real one.
     *
     * @param array<string, string|null>|null $values
     * @return list<string>
     */
    public function violations(?array $values = null): array
    {
        $reasons = [];

        foreach ($this->rules as $rule) {
            $name = (string) $rule['name'];

            if ($values === null) {
                try {
                    $raw = EnvironmentVariable::read($name);
                } catch (\InvalidArgumentException $e) {
                    $reasons[] = $e->getMessage();

                    continue;
                }

                // Reported without the value: the web server may hold a secret here.
                if ($raw === null && array_key_exists($name, $_SERVER)) {
                    $reasons[] = sprintf(
                        '%s is set only by the web server (fastcgi_param/SetEnv), which is not read; export it to the process environment.',
                        $name,
                    );

                    continue;
                }
            } else {
                $raw = $values[$name] ?? null;
            }

            $value = is_string($raw) ? trim($raw) : '';

            $reason = match ($rule['type']) {
                self::RULE_SET => $this->checkSet($name, $value),
                self::RULE_ONE_OF => $this->checkOneOf($rule, $name, $value),
                self::RULE_BASE64_BYTES => $this->checkBase64Bytes($rule, $name, $value),
                default => null,
            };

            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * @param array<string, string|null>|null $values
     */
    public function isSatisfied(?array $values = null): bool
    {
        return $this->violations($values) === [];
    }

    /**
     * The response a web SAPI receives on refusal.
     *
     * The body is empty on purpose: during a refusal this response is the whole site, and it
     * must not describe the cause. The headers mirror SecureHeadersMiddleware defaults, written
     * by hand because this path runs outside the response pipeline.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function refusalResponse(): array
    {
        $config = SecureHeadersConfig::defaults();

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => $config->xFrameOptions,
            'X-Content-Type-Options' => $config->xContentTypeOptions,
            'Referrer-Policy' => $config->referrerPolicy,
        ];

        return [
            'status' => 500,
            'headers' => $headers,
            'body' => '',
        ];
    }

    /**
     * The text a CLI entry point writes to stderr on refusal.
     *
     * @param list<string> $reasons
     */
    public static function refusalText(array $reasons): string
    {
        return "Refusing to boot:\n  - " . implode("\n  - ", $reasons) . "\n";
    }

    /**
     * Validate the environment and, on any violation, stop the process with exit().
     *
     * Returns normally when satisfied. Output is hardened first, because the image may ship
     * no php.ini and display_errors would otherwise print the reasons to the client. Reasons
     * go to the error log and, under CLI, to stderr.
     *
     * @param array<string, string|null>|null $values
     */
    public function enforce(?array $values = null): void
    {
        $reasons = $this->violations($values);

        if ($reasons === []) {
            $this->canonicaliseValidatedValues($values);

            return;
        }

        try {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');

            foreach ($reasons as $reason) {
                error_log('Refusing to boot: ' . $reason);
            }

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, self::refusalText($reasons));
                exit(self::EXIT_CONFIG);
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            if (!headers_sent()) {
                $refusal = self::refusalResponse();
                http_response_code($refusal['status']);
                foreach ($refusal['headers'] as $header => $value) {
                    header($header . ': ' . $value);
                }
            }
        } catch (Throwable) {
            // The refusal path must never raise the error it is refusing.
            if (!headers_sent()) {
                http_response_code(500);
            }
        }

        exit(1);
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function withRule(array $rule): self
    {
        $clone = clone $this;
        $clone->rules[] = $rule;

        return $clone;
    }

    private function checkSet(string $name, string $value): ?string
    {
        if ($value === '') {
            return sprintf('%s is not set.', $name);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function checkOneOf(array $rule, string $name, string $value): ?string
    {
        /** @var list<string> $allowed */
        $allowed = $rule['allowed'];

        if ($value === '') {
            return sprintf('%s is not set. Use one of: %s.', $name, implode(', ', $allowed));
        }

        // Echoing the value is safe here: a oneOf variable is an environment or tier name, never a
        // secret. Secrets use requireBase64Bytes, which never echoes its value.
        if (self::matchAllowed($value, $allowed, (bool) $rule['caseSensitive']) === null) {
            return sprintf(
                '%s=%s is not a recognised value. Use one of: %s.',
                $name,
                MessageValue::quote($value),
                implode(', ', $allowed),
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function checkBase64Bytes(array $rule, string $name, string $value): ?string
    {
        $bytes = (int) $rule['bytes'];

        if ($value === '') {
            return sprintf('%s is not set.', $name);
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return sprintf('%s is not valid base64.', $name);
        }

        if (strlen($decoded) !== $bytes) {
            return sprintf(
                '%s decodes to %d byte(s); exactly %d are required.',
                $name,
                strlen($decoded),
                $bytes,
            );
        }

        return null;
    }

    /**
     * Writes the allowlist literal back for each satisfied canonicalising requireOneOf rule.
     *
     * Only reached once violations() is empty, so every written value is an allowlist entry.
     *
     * @param array<string, string|null>|null $values
     */
    private function canonicaliseValidatedValues(?array $values): void
    {
        foreach ($this->rules as $rule) {
            if ($rule['type'] !== self::RULE_ONE_OF || $rule['canonicalise'] !== true) {
                continue;
            }

            $name = (string) $rule['name'];
            $raw = $values === null ? EnvironmentVariable::read($name) : ($values[$name] ?? null);
            $value = is_string($raw) ? trim($raw) : '';

            /** @var list<string> $allowed */
            $allowed = $rule['allowed'];
            $canonical = self::matchAllowed($value, $allowed, (bool) $rule['caseSensitive']);

            if ($canonical === null) {
                continue;
            }

            $_ENV[$name] = $canonical;
            $_SERVER[$name] = $canonical;
            putenv($name . '=' . $canonical);
        }
    }

    /**
     * Returns the allowlist literal matching $value, or null when none does.
     *
     * @param list<string> $allowed
     */
    private static function matchAllowed(string $value, array $allowed, bool $caseSensitive): ?string
    {
        foreach ($allowed as $candidate) {
            $matches = $caseSensitive
                ? $value === $candidate
                : strcasecmp($value, $candidate) === 0;

            if ($matches) {
                return $candidate;
            }
        }

        return null;
    }
}
