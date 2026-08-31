<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;



/**
 * Immutable top-level configuration tree.
 *
 * Aggregates all typed section objects from a single nested array, providing
 * a single entry point for application configuration. Every section except
 * `database` is always present with safe defaults so callers never need null
 * checks for the common sections. `database` is nullable because a database
 * connection is not universally required (CLI tools, API consumers, etc.).
 *
 * Configuration is loaded from YAML files using ConfigurationFile, which
 * supports the !env custom tag for environment variable resolution.
 *
 * Typical usage:
 *
 *   $config = Configuration::fromYamlFile(__DIR__ . '/../config.yml');
 *   $config->application->environment  // Environment::Production
 *   $config->database?->host           // 'localhost' or null if not configured
 *   $config->section('custom')         // CustomConfig section or null
 *
 * The `defaults()` factory produces a fully populated configuration using the
 * built-in defaults of every section -- useful in tests and minimal bootstraps.
 */
final readonly class Configuration
{
    /**
     * @param array<string, ConfigSection> $customSections
     */
    public function __construct(
        public ApplicationConfig  $application,
        public SessionConfig      $session,
        public SecurityConfig     $security,
        public LocalizationConfig $localization,
        public ?DatabaseConfig    $database,
        private array $customSections = [],
    ) {
    }

    /**
     * Build a Configuration tree from a nested key-value array.
     *
     * Each top-level key maps to a configuration section:
     *   'application'  => ApplicationConfig::fromArray(...)
     *   'session'      => SessionConfig::fromArray(...)
     *   'security'     => SecurityConfig::fromArray(...)
     *   'localization' => LocalizationConfig::fromArray(...)
     *   'database'     => DatabaseConfig::fromArray(...) -- omit to leave null
     *
     * Unknown top-level keys are available via section() if custom section
     * factories are registered.
     *
     * @param array<string, mixed> $config
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     *        Map of section name => ConfigSection subclass FQCN. These classes
     *        must have a static fromArray(array): static method.
     * @throws ConfigurationException if any section value violates its constraints.
     */
    public static function fromArray(array $config, array $sectionFactories = []): self
    {
        $customSections = [];
        $builtInSections = ['application', 'session', 'security', 'localization', 'database'];

        foreach ($sectionFactories as $name => $className) {
            if (in_array($name, $builtInSections, true)) {
                continue;
            }

            if (isset($config[$name]) && is_array($config[$name])) {
                $normalizedName = self::normalizeKey($name);
                $customSections[$normalizedName] = $className::fromArray($config[$name]);
            }
        }

        return new self(
            application:    ApplicationConfig::fromArray((array) ($config['application'] ?? [])),
            session:        SessionConfig::fromArray((array) ($config['session'] ?? [])),
            security:       SecurityConfig::fromArray((array) ($config['security'] ?? [])),
            localization:   LocalizationConfig::fromArray((array) ($config['localization'] ?? [])),
            database:       isset($config['database'])
                ? DatabaseConfig::fromArray((array) $config['database'])
                : null,
            customSections: $customSections,
        );
    }

    /**
     * Build a Configuration tree from a YAML file.
     *
     * Supports the !env custom tag for environment variable resolution.
     *
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     */
    public static function fromYamlFile(string $path, array $sectionFactories = []): self
    {
        $configFile = new ConfigurationFile($path);
        return self::fromArray($configFile->toArray(), $sectionFactories);
    }

    /**
     * Build a Configuration tree from multiple YAML files merged recursively.
     *
     * Later files override earlier files (`array_replace_recursive` semantics).
     *
     * @param string[] $paths
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     */
    public static function fromYamlFiles(array $paths, array $sectionFactories = []): self
    {
        return self::fromYamlFilesInternal($paths, ignoreMissing: false, sectionFactories: $sectionFactories);
    }

    /**
     * Build a Configuration tree from multiple YAML files, ignoring missing ones.
     *
     * Useful for optional local overrides (e.g. config.local.yml).
     *
     * @param string[] $paths
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     */
    public static function fromOptionalYamlFiles(array $paths, array $sectionFactories = []): self
    {
        return self::fromYamlFilesInternal($paths, ignoreMissing: true, sectionFactories: $sectionFactories);
    }

    /**
     * Build a Configuration tree from a PHP file that returns an array.
     *
     * Kept for backward compatibility and test usage.
     *
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     */
    public static function fromFile(string $path, array $sectionFactories = []): self
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['yml', 'yaml'], true)) {
            return self::fromYamlFile($path, $sectionFactories);
        }

        if (!is_file($path)) {
            throw ConfigurationException::fileNotFound($path);
        }

        try {
            /** @var mixed $loaded */
            $loaded = require $path;
        } catch (\Throwable $exception) {
            throw ConfigurationException::loadFailed($path, $exception);
        }

        if (!is_array($loaded)) {
            throw ConfigurationException::invalidFormat($path);
        }

        return self::fromArray($loaded, $sectionFactories);
    }

    /**
     * Build a Configuration tree from multiple files merged recursively.
     *
     * Supports both YAML and PHP files (detected by extension).
     * Later files override earlier files (`array_replace_recursive` semantics).
     *
     * @param string[] $paths
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     */
    public static function fromFiles(array $paths, array $sectionFactories = []): self
    {
        return self::fromFilesInternal($paths, ignoreMissing: false, sectionFactories: $sectionFactories);
    }

    /**
     * Build a Configuration tree from multiple files and ignore missing paths.
     *
     * @param string[] $paths
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     */
    public static function fromOptionalFiles(array $paths, array $sectionFactories = []): self
    {
        return self::fromFilesInternal($paths, ignoreMissing: true, sectionFactories: $sectionFactories);
    }

    /**
     * Get a custom configuration section by name.
     *
     * Accepts both snake_case and camelCase keys.
     */
    public function section(string $name): ?ConfigSection
    {
        return $this->customSections[self::normalizeKey($name)] ?? null;
    }

    /**
     * Check whether a custom section exists.
     *
     * Accepts both snake_case and camelCase keys.
     */
    public function hasSection(string $name): bool
    {
        return isset($this->customSections[self::normalizeKey($name)]);
    }

    /**
     * Export configuration sections to a plain associative array.
     *
     * ## Secrets are redacted by default
     *
     * This method is what a debug panel, a diagnostics route or a config dump
     * renders. It used to export `security.encryptionKey` and
     * `database.password` verbatim, alongside every custom section's raw
     * backing array, so the single most damaging pair of values in the process
     * travelled to whatever rendered a configuration overview -- and, together
     * with the debugger serving its output to any client, to that client.
     *
     * The default is therefore the safe one. A caller that genuinely needs the
     * values asks for them explicitly, at the call site, where a reader can see
     * the request:
     *
     *   $config->toArray();              // safe to render
     *   $config->toArray(revealSecrets: true);   // never render this
     *
     * A null or empty secret is left as-is rather than replaced, so an
     * UNCONFIGURED key still reads as unconfigured instead of looking like a
     * key somebody hid.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $revealSecrets = false): array
    {
        $result = [
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
                'csrfAutoHtml' => $this->security->csrfAutoHtml,
                'csrfExceptions' => $this->security->csrfExceptions,
                'allowedHosts' => $this->security->allowedHosts,
                'maxBodySize' => $this->security->maxBodySize,
                'trustedProxies' => $this->security->trustedProxies,
                'trustedHeaders' => $this->security->trustedHeaders,
                'encryptionKey' => self::redact($this->security->encryptionKey, $revealSecrets),
            ],
            'localization' => [
                'locale' => $this->localization->locale,
                'supportedLocales' => $this->localization->supportedLocales,
                'localePath' => $this->localization->localePath,
                'timezone' => $this->localization->timezone,
                'currency' => $this->localization->currency,
            ],
            'database' => $this->database === null ? null : [
                'driver'           => $this->database->driver,
                'host'             => $this->database->host,
                'port'             => $this->database->port,
                'database'         => $this->database->database,
                'username'         => $this->database->username,
                'password'         => self::redact($this->database->password, $revealSecrets),
                'charset'          => $this->database->charset,
            ],
        ];

        foreach ($this->customSections as $name => $section) {
            $result[$name] = $section->toArray($revealSecrets);
        }

        return $result;
    }

    /**
     * Produce a configuration tree where every section uses its built-in defaults.
     *
     * Equivalent to `Configuration::fromArray([])`. Useful in tests and
     * minimal bootstraps that don't need a config file.
     */
    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /**
     * Substitute a secret unless the caller explicitly asked for values.
     *
     * Null and '' pass through untouched: there is nothing to hide, and
     * masking them would make an unwired key look configured.
     */
    private static function redact(?string $value, bool $revealSecrets): ?string
    {
        if ($revealSecrets || $value === null || $value === '') {
            return $value;
        }

        return ConfigSection::REDACTED;
    }

    /**
     * Load a single file as an array (detects YAML vs PHP by extension).
     *
     * @return array<string, mixed>
     */
    private static function loadFileAsArray(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['yml', 'yaml'], true)) {
            return (new ConfigurationFile($path))->toArray();
        }

        if (!is_file($path)) {
            throw ConfigurationException::fileNotFound($path);
        }

        try {
            /** @var mixed $loaded */
            $loaded = require $path;
        } catch (\Throwable $exception) {
            throw ConfigurationException::loadFailed($path, $exception);
        }

        if (!is_array($loaded)) {
            throw ConfigurationException::invalidFormat($path);
        }

        return $loaded;
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

            $loaded = self::loadFileAsArray($path);
            $merged = array_replace_recursive($merged, $loaded);
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
                throw ConfigurationException::invalidPath(sprintf('Configuration file path at index %d must be a string.', $index));
            }

            $trimmed = trim($path);
            if ($trimmed === '') {
                throw ConfigurationException::invalidPath(sprintf('Configuration file path at index %d must not be empty.', $index));
            }

            if (in_array($trimmed, $normalized, true)) {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return $normalized;
    }

    /**
     * @param string[] $paths
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     */
    private static function fromFilesInternal(array $paths, bool $ignoreMissing, array $sectionFactories = []): self
    {
        return self::fromArray(self::mergeFilesToArray($paths, $ignoreMissing), $sectionFactories);
    }

    /**
     * @param string[] $paths
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     */
    private static function fromYamlFilesInternal(array $paths, bool $ignoreMissing, array $sectionFactories = []): self
    {
        $merged = [];

        foreach (self::normalizePaths($paths) as $path) {
            if ($ignoreMissing && !is_file($path)) {
                continue;
            }

            $loaded = (new ConfigurationFile($path))->toArray();
            $merged = array_replace_recursive($merged, $loaded);
        }

        return self::fromArray($merged, $sectionFactories);
    }

    /**
     * Normalize a key from snake_case to camelCase.
     */
    private static function normalizeKey(string $key): string
    {
        if (!str_contains($key, '_')) {
            return $key;
        }

        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    }
}
