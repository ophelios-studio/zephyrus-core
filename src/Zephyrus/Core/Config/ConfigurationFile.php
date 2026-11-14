<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use Zephyrus\Core\Cache\ConfigurationCache;
use Zephyrus\Exceptions\MessageValue;

/**
 * Parses a YAML configuration file with support for the custom !env tag.
 *
 * The !env tag allows referencing environment variables directly in YAML:
 *
 *   database:
 *     password: !env DB_PASSWORD
 *     host: !env DB_HOST, localhost
 *
 * The tag format is: !env VAR_NAME[, default_value]
 *
 * Environment variables are resolved from $_ENV and the process environment at
 * parse time, so .env files must be loaded before this class is used. An unset
 * variable without a default resolves to null, and every !env value is a string
 * or null. Refused names raise a ConfigurationException, see
 * {@see EnvironmentVariable::read()}. The router: section refuses every tag.
 *
 * When vendor/bin/zephyrus cache:warm has written var/zephyrus/<file name>.php
 * beside the file, the YAML is read from that cache while it matches the file and
 * its owners and modes pass the checks; !env tags are still resolved on every read.
 */
final class ConfigurationFile
{
    /** Shared by the runtime parse and cache:warm. Never add Yaml::PARSE_CONSTANT. */
    private const int YAML_FLAGS = Yaml::PARSE_CUSTOM_TAGS;

    /** @var array<string, mixed>|null */
    private ?array $content = null;

    public function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * Read the entire configuration or a specific top-level section.
     *
     * @return mixed The full config array, a specific section, or null if the section does not exist.
     * @throws ConfigurationException when the file is missing or unparsable, or an !env tag is refused.
     */
    public function read(?string $section = null): mixed
    {
        $content = $this->parse();

        if ($section === null) {
            return $content;
        }

        return $content[$section] ?? null;
    }

    /**
     * Return the full parsed configuration as an array.
     *
     * @return array<string, mixed>
     * @throws ConfigurationException when the file is missing or unparsable, or an !env tag is refused.
     */
    public function toArray(): array
    {
        return $this->parse();
    }

    /**
     * Check whether a top-level section exists.
     *
     * @throws ConfigurationException when the file is missing or unparsable, or an !env tag is refused.
     */
    public function hasSection(string $section): bool
    {
        return array_key_exists($section, $this->parse());
    }

    /**
     * Return all top-level section names.
     *
     * @return string[]
     * @throws ConfigurationException when the file is missing or unparsable, or an !env tag is refused.
     */
    public function sections(): array
    {
        return array_keys($this->parse());
    }

    /**
     * Parse a YAML file with its tags left unresolved. A file that does not parse to an array reads as empty.
     *
     * @internal Shared with ConfigurationCache, which caches this result.
     * @return array<string, mixed>
     * @throws ConfigurationException when the file does not parse.
     */
    public static function parseYaml(string $path): array
    {
        try {
            $parsed = Yaml::parseFile($path, self::YAML_FLAGS);
        } catch (\Throwable $e) {
            throw ConfigurationException::parseFailed($path, $e);
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Parse the YAML file, or read its warmed cache (cached after first call).
     *
     * @return array<string, mixed>
     */
    private function parse(): array
    {
        if ($this->content !== null) {
            return $this->content;
        }

        if (!is_file($this->path)) {
            throw ConfigurationException::fileNotFound($this->path);
        }

        $parsed = ConfigurationCache::forSource($this->path)->load() ?? self::parseYaml($this->path);
        $this->content = $this->processYamlTags($parsed);

        return $this->content;
    }

    /**
     * Resolve the custom YAML tags recursively. Only !env is resolved; other tags keep their value.
     *
     * @param array<string, mixed> $config
     * @param string|null $prefix Dotted path of $config, used to name the key in error messages.
     * @return array<string, mixed>
     */
    private function processYamlTags(array $config, ?string $prefix = null): array
    {
        foreach ($config as $key => $value) {
            $path = $prefix === null ? (string) $key : $prefix . '.' . $key;

            if ($prefix === null && $key === RouterConfig::SECTION) {
                RouterConfig::refuseTags($value);
            }

            if (is_array($value)) {
                $config[$key] = $this->processYamlTags($value, $path);
            } elseif ($value instanceof TaggedValue) {
                $config[$key] = $this->resolveTag($value, $path);
            }
        }

        return $config;
    }

    /**
     * Resolve a single YAML custom tag.
     */
    private function resolveTag(TaggedValue $tagged, string $path): mixed
    {
        return match ($tagged->getTag()) {
            'env' => $this->resolveEnvTag($tagged->getValue(), $path),
            default => $tagged->getValue(),
        };
    }

    /**
     * Resolve an !env tag value (VAR_NAME[, default_value]) from $_ENV or the process environment.
     * An empty name, a list or mapping value, and the names refused by {@see EnvironmentVariable::read()}
     * raise a ConfigurationException.
     */
    private function resolveEnvTag(mixed $value, string $path): mixed
    {
        if (is_array($value)) {
            throw new ConfigurationException(
                sprintf(
                    '!env tag at %s: it must name one variable, not a list or mapping.',
                    MessageValue::quote($path),
                ),
            );
        }

        $raw = (string) $value;
        $arguments = explode(',', $raw, 2);
        $envKey = trim($arguments[0], " \t\n\r\0\x0B\"'");
        $default = isset($arguments[1]) ? trim($arguments[1], " \t\n\r\0\x0B\"'") : null;

        if ($envKey === '') {
            throw new ConfigurationException(
                sprintf('!env tag at %s: it has an empty variable name.', MessageValue::quote($path)),
            );
        }

        try {
            return EnvironmentVariable::read($envKey) ?? $default;
        } catch (\InvalidArgumentException $e) {
            throw new ConfigurationException(
                sprintf('!env tag at %s: %s', MessageValue::quote($path), $e->getMessage()),
                previous: $e,
            );
        }
    }
}
