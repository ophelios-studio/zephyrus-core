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
 *     hostname: !env DB_HOST, localhost
 *
 * The tag format is: !env VAR_NAME[, default_value]
 *
 * Environment variables are resolved from $_ENV and $_SERVER at parse time,
 * so .env files must be loaded before this class is used.
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
     */
    public function toArray(): array
    {
        return $this->parse();
    }

    /**
     * Check whether a top-level section exists.
     */
    public function hasSection(string $section): bool
    {
        return array_key_exists($section, $this->parse());
    }

    /**
     * Return all top-level section names.
     *
     * @return string[]
     */
    public function sections(): array
    {
        return array_keys($this->parse());
    }

    /**
     * Parse the YAML file (cached after first call).
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
     * Recursively process custom YAML tags in the parsed content.
     *
     * Currently supports:
     *   !env VAR_NAME[, default] - Resolves to the environment variable value.
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
                $config[$key] = $this->resolveTag($value);
            }
        }

        return $config;
    }

    /**
     * Resolve a single YAML custom tag.
     */
    private function resolveTag(TaggedValue $tagged): mixed
    {
        return match ($tagged->getTag()) {
            'env' => $this->resolveEnvTag($tagged->getValue()),
            default => $tagged->getValue(),
        };
    }

    /**
     * Resolve an !env tag value.
     *
     * Format: VAR_NAME[, default_value]
     *
     * ## An HTTP_-prefixed name is never read from $_SERVER: CVE-2016-5385
     *
     * $_SERVER is not the process environment. Under CGI and FastCGI every
     * request header arrives in it as HTTP_<NAME>, so "!env HTTP_PROXY" used to
     * resolve to whatever a caller put in a "Proxy:" header. That is httpoxy,
     * and it turns one line of configuration into a caller-chosen outbound
     * proxy for the whole application.
     *
     * The trustworthy sources are consulted first and unconditionally: $_ENV,
     * then getenv(), which reads the real process environment even when
     * variables_order leaves $_ENV empty, as it does under php-fpm by default.
     *
     * The $_SERVER fallback is KEPT for every other name, because setting
     * configuration through fastcgi_param / SetEnv is a documented deployment
     * pattern and removing it would silently swap live values for defaults. It
     * is the HTTP_ family, and only that family, that a client can write.
     */
    private function resolveEnvTag(mixed $value): mixed
    {
        $raw = (string) $value;
        $arguments = explode(',', $raw, 2);
        $envKey = trim($arguments[0], " \t\n\r\0\x0B\"'");
        $default = isset($arguments[1]) ? trim($arguments[1], " \t\n\r\0\x0B\"'") : null;

        if (array_key_exists($envKey, $_ENV)) {
            return $_ENV[$envKey];
        }

        $fromProcessEnvironment = getenv($envKey);
        if ($fromProcessEnvironment !== false) {
            return $fromProcessEnvironment;
        }

        if (!str_starts_with($envKey, 'HTTP_') && array_key_exists($envKey, $_SERVER)) {
            return $_SERVER[$envKey];
        }

        return $default;
    }
}
