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
     * Read an environment variable.
     *
     * Sources are consulted in this order, and the order is the whole point:
     *
     *   1. $_ENV        the process environment as PHP imported it.
     *   2. getenv()     the REAL process environment. Under php-fpm the default
     *                   variables_order is "GPCS", with no E, so $_ENV is EMPTY
     *                   and every value set by the platform lives only here.
     *   3. $_SERVER     but ONLY for a name that does not start with HTTP_.
     *
     * ## Why the two rules exist
     *
     * Without (2) this helper failed OPEN and silently. `env('WEBHOOK_SECRET')`
     * came back NULL on a php-fpm tier where getenv() had the real value, so a
     * verification that read its secret through here verified nothing; and
     * `env('REQUIRE_MFA', false)` resolved to the DEFAULT on production while
     * resolving correctly on a developer's CLI, which is the worst possible
     * split.
     *
     * Without (3)'s HTTP_ exclusion it fails OPEN in the other direction. PHP
     * writes every request header into $_SERVER as HTTP_<NAME>, so a caller
     * sending `Proxy: http://attacker/` makes $_SERVER['HTTP_PROXY'] exist and
     * `env('HTTP_PROXY')` return the attacker's value. That is httpoxy,
     * CVE-2016-5385. The rest of $_SERVER is KEPT, because configuring an
     * application through fastcgi_param or SetEnv is a documented deployment
     * pattern and dropping it would swap live values for defaults.
     *
     * This is the same resolution order as ConfigurationFile::resolveEnvTag();
     * the two are meant to agree, and they used not to.
     *
     * @param string $key     The environment variable name.
     * @param mixed  $default Default value when the variable is not set.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = null;

        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } else {
            $fromProcessEnvironment = getenv($key);
            if ($fromProcessEnvironment !== false) {
                $value = $fromProcessEnvironment;
            } elseif (!str_starts_with($key, 'HTTP_') && array_key_exists($key, $_SERVER)) {
                $value = $_SERVER[$key];
            }
        }

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

if (!defined('ZEPHYRUS_FORMAT_METHODS')) {
    /**
     * The Formatter methods format() is allowed to reach.
     *
     * $formatter->$type(...) was an unrestricted dynamic method call on a
     * process-wide singleton, so $type decided which method ran. Anything
     * public was reachable, including the constructor:
     * `format('__construct', 'de_DE')` re-initialised the shared Formatter for
     * the rest of the request, changing every subsequent locale, currency and
     * date pattern in the application. Accessors were reachable too, which made
     * the helper a readback channel for whatever the singleton holds.
     *
     * Custom formatters are NOT affected: they are resolved before this list,
     * through Formatter::hasCustomFormatter(), so a name registered with
     * Formatter::register() still works exactly as before.
     */
    define('ZEPHYRUS_FORMAT_METHODS', [
        'money',
        'decimal',
        'percent',
        'ordinal',
        'spellOut',
        'date',
        'time',
        'datetime',
        'timeago',
        'duration',
        'filesize',
        'list',
        'truncate',
    ]);
}

if (!function_exists('format')) {
    /**
     * Format a value using the Formatter service.
     *
     * The first argument is the format type (a custom formatter name, or one of
     * ZEPHYRUS_FORMAT_METHODS), followed by the arguments to pass to it.
     *
     * Examples:
     *   format('money', 19.99)           => "$19.99"
     *   format('date', new DateTime())   => "Mar 9, 2026"
     *   format('filesize', 1048576)      => "1.0 MB"
     *
     * @param string $type    The formatter name.
     * @param mixed  ...$args Arguments to pass to the formatter method.
     * @throws InvalidArgumentException when $type is neither a registered custom
     *         formatter nor one of the built-in formatting methods.
     */
    function format(string $type, mixed ...$args): string
    {
        $formatter = App::getFormatter();
        if ($formatter === null) {
            return (string) ($args[0] ?? '');
        }
        if ($formatter->hasCustomFormatter($type)) {
            return $formatter->format($type, ...$args);
        }
        if (!in_array($type, ZEPHYRUS_FORMAT_METHODS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown format type "%s". Use one of: %s, or register a custom formatter.',
                $type,
                implode(', ', ZEPHYRUS_FORMAT_METHODS),
            ));
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
