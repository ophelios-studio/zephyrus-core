<?php

declare(strict_types=1);

/**
 * Zephyrus global helper functions.
 *
 * These functions provide convenient shorthand access to commonly used
 * framework features. They are autoloaded via composer.json autoload.files.
 */

use Zephyrus\Core\App;
use Zephyrus\Core\Config\EnvironmentVariable;
use Zephyrus\Formatting\FormatterException;

if (!function_exists('env')) {
    /**
     * Read an environment variable, with $_ENV and the process environment as sources.
     *
     * $_SERVER is never consulted: it also carries request data, so a value
     * there is client-controlled. Names starting with HTTP_, REDIRECT_, ORIG_, SSL_ or H2_,
     * exact request names such as QUERY_STRING, and names containing a NUL byte are refused
     * with an InvalidArgumentException.
     *
     * @param string $key     The environment variable name.
     * @param mixed  $default Default value when the variable is not set.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = EnvironmentVariable::read($key);

        if ($value === null) {
            return $default;
        }

        // Cast common string representations to their native types.
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
        $configSection = $builtIn ?? ($configuration->hasSection($section) ? $configuration->section($section) : null);
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

if (!function_exists('e')) {
    /**
     * Escape a value for safe interpolation into HTML.
     *
     * ## Why this exists
     *
     * RenderConfig offers `engine: php` as a first-class option, and
     * PhpEngine::capture() is raw extract() plus include. Nothing sits between a
     * template variable and the response body on that engine, and until this
     * helper landed there was no escaping function anywhere in this file, so the
     * only thing a template author could write was `<?= $value ?>`. A Flash
     * message rendered that way, exactly as the Flash docblock demonstrates, is
     * stored XSS. Latte auto-escapes and does not need this; PhpEngine does.
     *
     * ## The two flags are not decoration
     *
     * ENT_QUOTES also escapes the SINGLE quote, which is the character that
     * breaks out of a single-quoted attribute (`<a title='<?= e($v) ?>'>`). The
     * PHP default leaves it alone.
     *
     * ENT_SUBSTITUTE turns invalid UTF-8 into U+FFFD. Without it,
     * htmlspecialchars() returns an EMPTY STRING for a byte sequence it cannot
     * decode, so the value silently disappears from the page with nothing
     * logged and nothing thrown. A visible replacement character is a bug
     * somebody can see.
     *
     * ## What it accepts, and why
     *
     * NULL is accepted and yields "". `e($row->middleName)` on a nullable
     * column is the most common expression a template author writes, and a
     * strict `string` parameter would make it a TypeError at render time. The
     * realistic reaction to that is not `e($x ?? '')`, it is deleting the
     * `e()`, so a helper that refuses null is a helper that gets removed. "" is
     * also exactly what the unescaped `<?= $x ?>` already printed, so nothing
     * is invented.
     *
     * Stringable is accepted for the same reason: this framework echoes its own
     * value objects (Uri among them) in templates, and a helper less capable
     * than a raw echo gets skipped.
     *
     * An array or a plain object is REFUSED at the signature. (string) [] is
     * the literal 'Array' plus a warning and (string) $plainObject is a fatal,
     * so neither is a value a template meant to print. That is the same posture
     * ConfigSection::getString() takes on a value it cannot read.
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
     * Format a value using the Formatter service.
     *
     * The first argument is the formatter name, followed by the arguments to
     * pass to it. Built-in names are listed in Formatter::BUILT_IN_FORMATTERS
     * (money, date, filesize...); any other unregistered name throws
     * FormatterException.
     *
     * Examples:
     *   format('money', 19.99)           => "$19.99"
     *   format('date', new DateTime())   => "Mar 9, 2026"
     *   format('filesize', 1048576)      => "1.0 MB"
     *
     * @param string $type    The formatter name.
     * @param mixed  ...$args Arguments to pass to the formatter.
     * @throws FormatterException when $type is neither a registered custom
     *         formatter nor a built-in formatter.
     */
    function format(string $type, mixed ...$args): string
    {
        $formatter = App::getFormatter();
        if ($formatter === null) {
            return (string) ($args[0] ?? '');
        }

        return $formatter->format($type, ...$args);
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
