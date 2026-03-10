<?php

declare(strict_types=1);

/**
 * Zephyrus global helper functions.
 *
 * These functions provide convenient shorthand access to commonly used
 * framework features. They are autoloaded via composer.json autoload.files.
 */

use Zephyrus\Core\App;

if (!function_exists('env')) {
    /**
     * Read an environment variable from $_ENV or $_SERVER.
     *
     * @param string $key     The environment variable name.
     * @param mixed  $default Default value when the variable is not set.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        // Cast common string representations to their native types.
        return match (strtolower((string) $value)) {
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
     * Read a configuration value.
     *
     * When called with only a section name, returns the matching ConfigSection
     * (or built-in config property). When called with a property, returns the
     * value from that section using dot-notation.
     *
     * Built-in sections: application, session, security, localization, database.
     * Custom sections registered via Configuration::withSection() are also
     * accessible.
     *
     * @param string      $section  Section name (e.g. 'application', 'database', or custom).
     * @param string|null $property Dot-notation property within the section.
     * @param mixed       $default  Default value when the property is not found.
     */
    function config(string $section, ?string $property = null, mixed $default = null): mixed
    {
        $configuration = App::getConfiguration();
        if ($configuration === null) {
            return $default;
        }

        // Try built-in sections first (public readonly properties on Configuration).
        $builtIn = match ($section) {
            'application' => $configuration->application,
            'session' => $configuration->session,
            'security' => $configuration->security,
            'localization' => $configuration->localization,
            'database' => $configuration->database,
            default => null,
        };

        // Fall back to custom sections.
        $configSection = $builtIn ?? $configuration->section($section);
        if ($configSection === null) {
            return $default;
        }

        if ($property === null) {
            return $configSection;
        }

        // ConfigSection subclasses have a generic get() method with
        // dot-notation support.
        if ($configSection instanceof \Zephyrus\Core\Config\ConfigSection) {
            return $configSection->get($property, $default);
        }

        // Built-in config classes are plain readonly objects — use direct
        // property access.
        if (property_exists($configSection, $property)) {
            return $configSection->$property;
        }

        return $default;
    }
}

if (!function_exists('session')) {
    /**
     * Read or write session values.
     *
     * When called with a string key, returns the session value (or default).
     * When called with an associative array, sets multiple session values at
     * once and returns null.
     *
     * @param string|array<string, mixed> $key     Session key or array of key-value pairs.
     * @param mixed                       $default Default when reading a missing key.
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
     * Translate a localization key with optional parameter interpolation.
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
     *
     * @see localize()
     */
    function i18n(string $key, array $parameters = [], ?string $locale = null): string
    {
        return localize($key, $parameters, $locale);
    }
}

if (!function_exists('format')) {
    /**
     * Format a value using the Formatter service.
     *
     * The first argument is the format type (method name on Formatter), followed
     * by the arguments to pass to that method.
     *
     * Examples:
     *   format('money', 19.99)           => "$19.99"
     *   format('date', new DateTime())   => "Mar 9, 2026"
     *   format('filesize', 1048576)      => "1.0 MB"
     *
     * @param string $type The formatter method name.
     * @param mixed  ...$args Arguments to pass to the formatter method.
     */
    function format(string $type, mixed ...$args): string
    {
        $formatter = App::getFormatter();
        if ($formatter === null) {
            return (string) ($args[0] ?? '');
        }
        return $formatter->$type(...$args);
    }
}

if (!function_exists('asset')) {
    /**
     * Generate a cache-busted asset URL.
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

if (!function_exists('embed')) {
    /**
     * Inline-embed an asset's file contents (e.g. SVG).
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
     * Get the CSP nonce for the current request.
     *
     * The nonce is generated once per request and reused for consistency
     * across all script/style tags.
     */
    function nonce(): string
    {
        return App::nonce();
    }
}
