<?php

declare(strict_types=1);

/**
 * Zephyrus global helper functions, autoloaded via composer.json autoload.files.
 */

use Zephyrus\Core\App;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\EnvironmentVariable;
use Zephyrus\Formatting\FormatterException;

if (!function_exists('env')) {
    /**
     * Read an environment variable from $_ENV or the process environment, returning $default when unset.
     *
     * $_SERVER is never consulted: it carries client-controlled request data.
     * The strings true, false, null and empty (optionally in parentheses) are cast to their native values.
     *
     * @throws \InvalidArgumentException for a refused variable name, see {@see \Zephyrus\Core\Config\EnvironmentVariable::read()}.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = EnvironmentVariable::read($key);

        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('config')) {
    /**
     * Read a config section, or a dot-notation property of it.
     * Returns $default when no configuration is set, the custom section is unknown or the property is missing.
     *
     * Built-in sections are listed in Configuration::BUILT_IN_SECTIONS; custom sections
     * need their factory in the $sectionFactories argument of the Configuration factories.
     *
     * @param string      $section  Section name (e.g. 'application', 'database', or custom).
     * @param string|null $property Dot-notation property within the section.
     * @param mixed       $default  Default value when the property is not found.
     * @return mixed The ConfigSection (or built-in config object) when $property is null, else the property value.
     * @throws \InvalidArgumentException when $section is a spelling of a built-in section (see Configuration::section()).
     */
    function config(string $section, ?string $property = null, mixed $default = null): mixed
    {
        $configuration = App::getConfiguration();
        if ($configuration === null) {
            return $default;
        }

        $configSection = in_array($section, Configuration::BUILT_IN_SECTIONS, true)
            ? $configuration->{$section}
            : $configuration->section($section);
        if ($configSection === null) {
            return $default;
        }

        if ($property === null) {
            return $configSection;
        }

        if ($configSection instanceof \Zephyrus\Core\Config\ConfigSection) {
            return $configSection->get($property, $default);
        }

        // Built-in config classes are plain readonly objects, read by property.
        if (property_exists($configSection, $property)) {
            return $configSection->$property;
        }

        return $default;
    }
}

if (!function_exists('session')) {
    /**
     * Read a session value, or set several at once from an array (returning null). Without a registered session manager, reads return $default and writes are ignored.
     *
     * @param string|array<string, mixed> $key     Session key, or key-value pairs to set.
     * @param mixed                       $default Default when reading a missing key.
     *
     * @throws \Zephyrus\Session\SessionException when a manager is registered and writing finds no active session.
     */
    function session(string|array $key, mixed $default = null): mixed
    {
        $manager = App::getSession();
        if ($manager === null) {
            return is_string($key) ? $default : null;
        }

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $manager->set($k, $v);
            }
            return null;
        }

        return $manager->get($key, $default);
    }
}

if (!function_exists('localize')) {
    /**
     * Translate a key with {placeholder} interpolation. Without a translator, returns $key unchanged.
     *
     * @param string               $key        The translation key (e.g. 'messages.welcome').
     * @param array<string, mixed> $parameters Named parameters for {placeholder} interpolation.
     * @param string|null          $locale     Optional locale override.
     */
    function localize(string $key, array $parameters = [], ?string $locale = null): string
    {
        $translator = App::getTranslator();
        if ($translator === null) {
            return $key;
        }
        return $translator->trans($key, $parameters, $locale);
    }
}

if (!function_exists('i18n')) {
    /**
     * Alias for localize().
     */
    function i18n(string $key, array $parameters = [], ?string $locale = null): string
    {
        return localize($key, $parameters, $locale);
    }
}

if (!function_exists('e')) {
    /**
     * Escape a value for HTML output. Null renders as an empty string.
     *
     * The php engine does not escape templates, so every variable it prints must go through this helper.
     * ENT_QUOTES escapes single quotes too, for single-quoted attributes. ENT_SUBSTITUTE replaces invalid
     * UTF-8 with U+FFFD; without it the whole value would silently become an empty string.
     *
     * @param string|int|float|bool|Stringable|null $value The value to render.
     */
    function e(string|int|float|bool|Stringable|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('format')) {
    /**
     * Format a value with the Formatter service. Built-in names are listed in Formatter::BUILT_IN_FORMATTERS.
     *
     * Examples assume an en_US Formatter: other locales print USD as "US$" or "$ US".
     *   format('money', 19.99)           => "$19.99"
     *   format('date', new DateTime())   => "Mar 9, 2026"
     *   format('filesize', 1048576)      => "1.0 MB"
     *
     * @param string $type    The formatter name.
     * @param mixed  ...$args Arguments to pass to the formatter.
     * @throws FormatterException when no Formatter is set, or when $type is
     *         neither a registered custom formatter nor a built-in formatter.
     */
    function format(string $type, mixed ...$args): string
    {
        $formatter = App::getFormatter();
        if ($formatter === null) {
            throw FormatterException::formatterRequired($type);
        }

        return $formatter->format($type, ...$args);
    }
}

if (!function_exists('asset')) {
    /**
     * Generate a cache-busted asset URL. Without an asset manager, returns $path unchanged.
     *
     * @param string $path The asset path relative to the public directory.
     */
    function asset(string $path): string
    {
        $assetManager = App::getAsset();
        if ($assetManager === null) {
            return $path;
        }
        return $assetManager->url($path);
    }
}

if (!function_exists('route')) {
    /**
     * Generate the URL of a named route.
     *
     * Absolute links (emails): after ApplicationBuilder::build(),
     * App::setUrlGenerator(new RouteUrlGenerator($router->routes(), 'https://example.com'))
     * makes every later route() call in the process absolute.
     *
     * @param string                              $name       The route name.
     * @param array<string, scalar>               $parameters Path parameters, keyed by placeholder name.
     * @param array<string, scalar|array<scalar>> $query      Query string values.
     * @param string|null                         $fragment   The URL fragment, with or without its leading "#".
     * @throws \LogicException when no URL generator is installed.
     * @throws \Zephyrus\Routing\Exception\RouteUrlGenerationException for an unknown name, a missing or unexpected parameter, or a value violating its constraint.
     */
    function route(string $name, array $parameters = [], array $query = [], ?string $fragment = null): string
    {
        $generator = App::getUrlGenerator();
        if ($generator === null) {
            throw new \LogicException('route() needs the application routes: build the application with ApplicationBuilder::withRouter(), or call App::setUrlGenerator().');
        }

        return $generator->generate($name, $parameters, $query, $fragment);
    }
}

if (!function_exists('embed')) {
    /**
     * Inline an asset's file contents (e.g. SVG). Without an asset manager, returns ''.
     *
     * @param string $path The asset path relative to the public directory.
     */
    function embed(string $path): string
    {
        $assetManager = App::getAsset();
        if ($assetManager === null) {
            return '';
        }
        return $assetManager->embed($path);
    }
}

if (!function_exists('nonce')) {
    /**
     * Return the CSP nonce of the current request, shared by all script and style tags.
     */
    function nonce(): string
    {
        return App::nonce();
    }
}
