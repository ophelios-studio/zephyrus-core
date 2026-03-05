<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use RuntimeException;

/**
 * Immutable top-level configuration tree.
 *
 * Aggregates all typed section objects from a single nested array, providing
 * a single entry point for application configuration.  Every section except
 * `database` is always present with safe defaults so callers never need null
 * checks for the common sections.  `database` is nullable because a database
 * connection is not universally required (CLI tools, API consumers, etc.).
 *
 * Typical usage:
 *
 *   $config = Configuration::fromArray(require __DIR__ . '/../config/app.php');
 *   $config->application->environment  // Environment::Production
 *   $config->database?->host           // 'localhost' or null if not configured
 *
 * The `defaults()` factory produces a fully populated configuration using the
 * built-in defaults of every section — useful in tests and minimal bootstraps.
 */
final readonly class Configuration
{
    public function __construct(
        public ApplicationConfig  $application,
        public SessionConfig      $session,
        public SecurityConfig     $security,
        public LocalizationConfig $localization,
        public ?DatabaseConfig    $database,
    ) {
    }

    /**
     * Build a Configuration tree from a nested key-value array.
     *
     * Each top-level key maps to a configuration section:
     *   'application' => ApplicationConfig::fromArray(...)
     *   'session'     => SessionConfig::fromArray(...)
     *   'security'    => SecurityConfig::fromArray(...)
     *   'localization'=> LocalizationConfig::fromArray(...)
     *   'database'    => DatabaseConfig::fromArray(...) — omit to leave null
     *
     * @param array<string, mixed> $config
     * @throws ConfigurationException if any section value violates its constraints.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            application:  ApplicationConfig::fromArray((array) ($config['application']  ?? [])),
            session:      SessionConfig::fromArray((array) ($config['session']      ?? [])),
            security:     SecurityConfig::fromArray((array) ($config['security']    ?? [])),
            localization: LocalizationConfig::fromArray((array) ($config['localization'] ?? [])),
            database:     isset($config['database'])
                ? DatabaseConfig::fromArray((array) $config['database'])
                : null,
        );
    }

    /**
     * Build a Configuration tree from a PHP file that returns an array.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Configuration file not found: %s', $path));
        }

        try {
            /** @var mixed $loaded */
            $loaded = require $path;
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                sprintf('Configuration file failed to load: %s', $path),
                previous: $exception,
            );
        }

        if (!is_array($loaded)) {
            throw new RuntimeException(sprintf('Configuration file must return an array: %s', $path));
        }

        return self::fromArray($loaded);
    }

    /**
     * Build a Configuration tree from multiple PHP files merged recursively.
     *
     * Later files override earlier files (`array_replace_recursive` semantics).
     *
     * @param string[] $paths
     */
    public static function fromFiles(array $paths): self
    {
        return self::fromFilesInternal($paths, ignoreMissing: false);
    }

    /**
     * Build a Configuration tree from multiple PHP files and ignore missing paths.
     *
     * Useful for optional local overrides (e.g. app.local.php).
     *
     * @param string[] $paths
     */
    public static function fromOptionalFiles(array $paths): self
    {
        return self::fromFilesInternal($paths, ignoreMissing: true);
    }

    /**
     * @param string[] $paths
     * @return array<string, mixed>
     */
    private static function mergeFilesToArray(array $paths, bool $ignoreMissing): array
    {
        $merged = [];

        foreach (self::normalizePaths($paths) as $path) {
            if ($ignoreMissing && !is_file($path)) {
                continue;
            }

            $loaded = self::fromFile($path);
            $merged = array_replace_recursive($merged, $loaded->toArray());
        }

        return $merged;
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private static function normalizePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $index => $path) {
            if (!is_string($path)) {
                throw new RuntimeException(sprintf('Configuration file path at index %d must be a string.', $index));
            }

            $trimmed = trim($path);
            if ($trimmed === '') {
                throw new RuntimeException(sprintf('Configuration file path at index %d must not be empty.', $index));
            }

            $normalized[] = $trimmed;
        }

        return $normalized;
    }

    /**
     * @param string[] $paths
     */
    private static function fromFilesInternal(array $paths, bool $ignoreMissing): self
    {
        return self::fromArray(self::mergeFilesToArray($paths, $ignoreMissing));
    }

    /**
     * Export configuration sections to a plain associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'application' => [
                'environment' => $this->application->environment->value,
                'debug' => $this->application->debug,
            ],
            'session' => [
                'name' => $this->session->name,
                'lifetime' => $this->session->lifetime,
                'secure' => $this->session->secure,
                'httpOnly' => $this->session->httpOnly,
                'sameSite' => $this->session->sameSite,
                'cookiePath' => $this->session->cookiePath,
            ],
            'security' => [
                'forceHttps' => $this->security->forceHttps,
                'csrfEnabled' => $this->security->csrfEnabled,
                'allowedHosts' => $this->security->allowedHosts,
                'maxBodySize' => $this->security->maxBodySize,
            ],
            'localization' => [
                'defaultLocale' => $this->localization->defaultLocale,
                'supportedLocales' => $this->localization->supportedLocales,
                'jsonLocalePaths' => $this->localization->jsonLocalePaths,
                'jsonExtension' => $this->localization->jsonExtension,
            ],
            'database' => $this->database === null ? null : [
                'driver' => $this->database->driver,
                'host' => $this->database->host,
                'port' => $this->database->port,
                'database' => $this->database->database,
                'username' => $this->database->username,
                'password' => $this->database->password,
                'charset' => $this->database->charset,
            ],
        ];
    }

    /**
     * Produce a configuration tree where every section uses its built-in defaults.
     *
     * Equivalent to `Configuration::fromArray([])`.  Useful in tests and
     * minimal bootstraps that don't need a config file.
     */
    public static function defaults(): self
    {
        return self::fromArray([]);
    }
}
