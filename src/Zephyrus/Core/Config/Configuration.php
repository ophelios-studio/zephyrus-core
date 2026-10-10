<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;



/**
 * Immutable top-level configuration tree.
 *
 * Aggregates the typed section objects built from one nested array. Every section
 * except `database` is always present with safe defaults; `database` is null when
 * no connection is configured. YAML files are read by ConfigurationFile, which
 * resolves the !env tag.
 *
 * Typical usage:
 *
 *   $config = Configuration::fromYamlFile(__DIR__ . '/../config.yml');
 *   $config->application->environment  // Environment::Production
 *   $config->database?->host           // 'localhost' or null if not configured
 *   $config->section('custom')         // CustomConfig section or null
 */
final readonly class Configuration
{
    /** Names of the sections read through the typed properties. */
    public const BUILT_IN_SECTIONS = ['application', 'session', 'security', 'localization', 'database'];

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
     * The built-in keys are application, session, security, localization and database
     * (omit database to leave it null). A custom factory runs only when its key holds
     * an array, and is read back with section().
     *
     * @param array<string, mixed> $config
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     *        Section name => ConfigSection subclass.
     * @throws ConfigurationException if any section value violates its constraints.
     * @throws \InvalidArgumentException if a factory is not keyed by a section name, or targets a built-in section name.
     */
    public static function fromArray(array $config, array $sectionFactories = []): self
    {
        $customSections = [];
        foreach ($sectionFactories as $name => $className) {
            if (!is_string($name)) { // @phpstan-ignore function.alreadyNarrowedType
                throw new \InvalidArgumentException(self::unnamedFactoryMessage($name, $className));
            }

            $normalizedName = self::normalizeKey($name);
            if (in_array($normalizedName, self::BUILT_IN_SECTIONS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Section factory "%s" collides with the built-in section "%s"; read it with $configuration->%s instead.',
                    $name,
                    $normalizedName,
                    $normalizedName,
                ));
            }

            if (isset($config[$name]) && is_array($config[$name])) {
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

    private static function unnamedFactoryMessage(int|string $index, mixed $className): string
    {
        if (!is_string($className)) {
            return sprintf('Section factory at index %d must be keyed by its section name.', $index);
        }

        $shortName = basename(str_replace('\\', '/', $className));
        $sectionName = strtolower(preg_replace('/Config$/', '', $shortName) ?: $shortName);

        return sprintf(
            "Section factory %s at index %d must be keyed by its section name, for example ['%s' => %s::class].",
            $shortName,
            $index,
            $sectionName,
            $shortName,
        );
    }

    /**
     * Build a Configuration tree from a YAML file.
     *
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     * @throws ConfigurationException when the file is missing or unparsable, an !env tag is refused,
     *        or a section value is invalid.
     * @throws \InvalidArgumentException if a factory is not keyed by a section name, or targets a built-in section name.
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
     * @throws ConfigurationException when a path is not a non-empty string, a file is missing or unparsable,
     *        an !env tag is refused, or a section value is invalid.
     * @throws \InvalidArgumentException if a factory is not keyed by a section name, or targets a built-in section name.
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
     * @throws ConfigurationException when a path is not a non-empty string, a present file is unparsable,
     *        an !env tag is refused, or a section value is invalid.
     * @throws \InvalidArgumentException if a factory is not keyed by a section name, or targets a built-in section name.
     */
    public static function fromOptionalYamlFiles(array $paths, array $sectionFactories = []): self
    {
        return self::fromYamlFilesInternal($paths, ignoreMissing: true, sectionFactories: $sectionFactories);
    }

    /**
     * Build a Configuration tree from one file: YAML by extension, otherwise a PHP file returning an array.
     *
     * @param array<string, class-string<ConfigSection>> $sectionFactories
     * @throws ConfigurationException when the file is missing, is a YAML file that does not parse, fails to load,
     *        or does not return an array, an !env tag is refused, or a section value is invalid.
     * @throws \InvalidArgumentException if a factory is not keyed by a section name, or targets a built-in section name.
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
     * @throws ConfigurationException when a path is not a non-empty string, a file is missing, is a YAML file
     *        that does not parse, fails to load or does not return an array, an !env tag is refused,
     *        or a section value is invalid.
     * @throws \InvalidArgumentException if a factory is not keyed by a section name, or targets a built-in section name.
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
     * @throws ConfigurationException when a path is not a non-empty string, a present file is a YAML file
     *        that does not parse, fails to load or does not return an array, an !env tag is refused,
     *        or a section value is invalid.
     * @throws \InvalidArgumentException if a factory is not keyed by a section name, or targets a built-in section name.
     */
    public static function fromOptionalFiles(array $paths, array $sectionFactories = []): self
    {
        return self::fromFilesInternal($paths, ignoreMissing: true, sectionFactories: $sectionFactories);
    }

    /**
     * Get a custom configuration section by name.
     *
     * Accepts both snake_case and camelCase keys.
     *
     * @throws \InvalidArgumentException when the name is a built-in section, which is read from its typed property.
     */
    public function section(string $name): ?ConfigSection
    {
        $normalized = self::normalizeKey($name);

        if (in_array($normalized, self::BUILT_IN_SECTIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Section "%s" is a built-in typed property; use $configuration->%s instead.',
                $normalized,
                $normalized,
            ));
        }

        return $this->customSections[$normalized] ?? null;
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
     * Export the sections to a plain associative array, with secrets redacted.
     *
     * Secrets are replaced by ConfigSection::REDACTED unless $revealSecrets is true.
     * Pass true only where the raw values are needed, never when rendering a debug
     * panel or a config dump.
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
     * Build a configuration tree where every section uses its built-in defaults.
     *
     * Equivalent to `fromArray([])`.
     */
    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /**
     * Mask a secret unless $revealSecrets is true. Null and '' pass through: masking
     * them would make an unset key look configured.
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
