<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

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
 * {@see EnvironmentVariable::read()}.
 */
final class ConfigurationFile
{
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
     * Parse the YAML file (cached after first call). A file that does not parse to an array reads as empty.
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

        try {
            $parsed = Yaml::parseFile($this->path, Yaml::PARSE_CUSTOM_TAGS);
        } catch (\Throwable $e) {
            throw ConfigurationException::parseFailed($this->path, $e);
        }

        if (!is_array($parsed)) {
            $parsed = [];
        }

        $this->content = $this->processYamlTags($parsed);

        return $this->content;
    }

    /**
     * Resolve the custom YAML tags recursively. Only !env is resolved; other tags keep their value.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function processYamlTags(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $config[$key] = $this->processYamlTags($value);
            } elseif ($value instanceof TaggedValue) {
                $config[$key] = $this->resolveTag($value, (string) $key);
            }
        }

        return $config;
    }

    /**
     * Resolve a single YAML custom tag.
     */
    private function resolveTag(TaggedValue $tagged, string $key): mixed
    {
        return match ($tagged->getTag()) {
            'env' => $this->resolveEnvTag($tagged->getValue(), $key),
            default => $tagged->getValue(),
        };
    }

    /**
     * Resolve an !env tag value (VAR_NAME[, default_value]) from $_ENV or the process environment.
     * An empty name, a list or mapping value, and the names refused by {@see EnvironmentVariable::read()}
     * raise a ConfigurationException.
     */
    private function resolveEnvTag(mixed $value, string $key): mixed
    {
        if (is_array($value)) {
            throw new ConfigurationException(sprintf('!env tag: the key "%s" must name one variable, not a list or mapping.', $key));
        }

        $raw = (string) $value;
        $arguments = explode(',', $raw, 2);
        $envKey = trim($arguments[0], " \t\n\r\0\x0B\"'");
        $default = isset($arguments[1]) ? trim($arguments[1], " \t\n\r\0\x0B\"'") : null;

        if ($envKey === '') {
            throw new ConfigurationException(sprintf('!env tag: the key "%s" has an empty variable name.', $key));
        }

        try {
            return EnvironmentVariable::read($envKey) ?? $default;
        } catch (\InvalidArgumentException $e) {
            throw new ConfigurationException('!env tag: ' . $e->getMessage(), previous: $e);
        }
    }
}
