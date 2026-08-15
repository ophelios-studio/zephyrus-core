<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Throwable;
use Zephyrus\Security\SecureHeadersConfig;

/**
 * Declares the environment variables an application cannot run without, and
 * refuses to boot when they are not satisfied.
 *
 * ## Why this exists
 *
 * Unknown environment values fail OPEN, and the result is a confident,
 * healthy-looking, wrong process. Real failures that share this shape:
 *
 *   - APP_ENV=prod, not the literal `production`, resolved to the debug
 *     default and served exception messages and absolute-path stack traces.
 *     It answered HTTP 200 while doing it, because middleware had already sent
 *     headers by the time anything noticed, so PHP could no longer set a 500.
 *     A platform health check saw a healthy machine.
 *   - MODE=INGESTT served a green /health over a completely empty router: the
 *     machine stayed in rotation, healthy and useless.
 *   - A deployed tier with no encryption key booted fine and failed later, at
 *     the first write that needed it.
 *
 * A value nobody declared is a value nobody reasoned about. This stops the
 * process at boot instead, before anything can answer.
 *
 * ## Usage
 *
 * Call enforce() as the FIRST thing in your entry point, before the kernel, the
 * render engine or any middleware exists:
 *
 *   EnvironmentContract::create()
 *       ->requireOneOf('APP_ENV', ['dev', 'staging', 'production'])
 *       ->requireOneOf('MODE', ['WEB', 'API'], canonicalise: true)
 *       ->requireSet('DATABASE_URL')
 *       ->requireBase64Bytes('ENCRYPTION_KEY', 32)
 *       ->enforce();
 *
 * Works in any SAPI. Under CLI it writes the reasons to stderr and exits 78
 * (EX_CONFIG) so a worker or a container dies visibly instead of idling. Under
 * a web SAPI it answers 500 with an empty body and security headers.
 *
 * ## Deliberately not on KernelBuilder
 *
 * It would run too late to be trustworthy and would give false comfort. The
 * guard has to execute before anything can print, and a worker or scheduler
 * entry point never builds an HttpKernel at all. Its predecessor in a sibling
 * project ran after the framework kernel booted, so the error firewall was not
 * yet installed and the refusal printed its own stack trace at HTTP 200. Keep
 * it standalone, and call it first.
 *
 * ## What it never does
 *
 * It never prints a value. Reasons name the VARIABLE and, for a length
 * mismatch, the decoded length. The secret itself, and any prefix of it, stays
 * out of the log and out of the response.
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
     * The variable must be present and match one of the permitted values.
     *
     * Comparison trims and, unless $caseSensitive, ignores case, so two parts
     * of an application can never disagree about the same variable.
     *
     * @param list<string> $allowed
     * @param bool $canonicalise When true, the matched value is written back to
     *   $_ENV, $_SERVER and putenv() as the ALLOWLIST LITERAL, never as the
     *   caller's input. Readers comparing the raw value with a strict === then
     *   agree with readers that normalise. Only ever applied after the value
     *   has been validated, so it cannot canonicalise a typo into something
     *   that looks legitimate.
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
     * The variable must be present, be valid base64, and decode to exactly
     * $bytes bytes. This is the shape of a symmetric key: 32 bytes for
     * libsodium's secretbox.
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
     * Every reason this process must not start, or an empty list when it may.
     *
     * Pure: pass $values to evaluate a hypothetical environment, omit it to
     * read the real one. Being pure is what makes the decision unit-testable
     * without an exit().
     *
     * @param array<string, string|null>|null $values
     * @return list<string>
     */
    public function violations(?array $values = null): array
    {
        $reasons = [];

        foreach ($this->rules as $rule) {
            $name = (string) $rule['name'];
            $raw = $values === null ? self::readEnvironment($name) : ($values[$name] ?? null);
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
     * Exposed as data so the refusal can be asserted in a test without running
     * a process to its exit(). The body is EMPTY on purpose: during a refusal
     * this response is the entire site, on every URL, for as long as the
     * misconfiguration lasts, so it carries nothing about the cause. The
     * headers are the same defaults SecureHeadersMiddleware would have applied,
     * written by hand because this path runs outside the response pipeline: a
     * refusal page that is framable and sniffable is still the whole site.
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
     * Validate the environment and, on any violation, stop the process without
     * ever printing the reason to the client.
     *
     * Output is hardened FIRST: the image may ship no php.ini, so display_errors
     * can default to On and would otherwise print whatever follows. The reasons
     * go to the error log, and to stderr under CLI. The client gets a 500 with
     * an empty body regardless of any debug setting, because the reason is
     * operator information and never response content.
     *
     * The satisfied path returns normally and is unit-tested directly. The
     * refusal path ends in exit(), so it is covered by a subprocess test
     * driving tests/Fixtures/boot_contract_entrypoint.php and asserting the
     * exit code, stderr and the absence of any secret in the output.
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
            // The refusal path must never become the error it is refusing.
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

        if (self::matchAllowed($value, $allowed, (bool) $rule['caseSensitive']) === null) {
            // Naming the rejected value is safe here and is the whole point of
            // the message: an allowlisted variable is a tier or an environment
            // name, never a secret. requireBase64Bytes is the rule for secrets
            // and it never echoes its value.
            return sprintf(
                '%s="%s" is not a recognised value. Use one of: %s.',
                $name,
                $value,
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
     * Write back the canonical literal for every satisfied requireOneOf rule
     * that asked for it. Only ever reached once violations() came back empty,
     * and only ever writes a string taken from the allowlist itself.
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
            $raw = $values === null ? self::readEnvironment($name) : ($values[$name] ?? null);
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
     * Return the ALLOWLIST literal matching $value, or null when none does.
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

    private static function readEnvironment(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }
}
