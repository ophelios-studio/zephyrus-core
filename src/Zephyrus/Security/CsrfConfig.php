<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Immutable configuration for CsrfMiddleware.
 *
 * Bundles tuneable knobs in one place so that CsrfMiddleware has a single,
 * stable constructor signature and application config arrays can be forwarded
 * with fromArray().
 *
 * excludedPathPatterns
 * --------------------
 * A list of PCRE regex patterns matched against the request path
 * (everything after the host, including the leading "/").  Any path that
 * matches at least one pattern is exempt from CSRF validation entirely —
 * useful for webhook endpoints, API routes protected by other means, or
 * health-check URLs.
 *
 * injectToken
 * -----------
 * When true, the middleware automatically injects a hidden CSRF input field
 * immediately after every <form…> opening tag in text/html responses.  This
 * removes the need to add the token manually in every template.
 *
 *   <!-- before injection -->
 *   <form method="post" action="/login">
 *
 *   <!-- after injection -->
 *   <form method="post" action="/login">
 *   <input type="hidden" name="_csrf_token" value="…">
 *
 * Only responses with a Content-Type of text/html are modified; JSON, plain
 * text, and other types pass through unchanged.
 *
 * Example:
 *
 *   $config = CsrfConfig::fromArray([
 *       'enabled'                  => true,
 *       'body_field'               => '_token',
 *       'header_name'              => 'X-XSRF-TOKEN',
 *       'inject_token'             => true,
 *       'excluded_path_patterns'   => [
 *           '#^/webhooks/#',
 *           '#^/api/v\d+/public#',
 *       ],
 *   ]);
 *
 *   $mw = new CsrfMiddleware($tokenManager, $config);
 */
final class CsrfConfig
{
    /**
     * @param string       $bodyField              Name of the HTML hidden-field / POST body key.
     * @param string       $headerName             HTTP header accepted as an alternative token source.
     * @param bool         $injectToken            Auto-inject a hidden field into HTML form responses.
     * @param list<string> $excludedPathPatterns   PCRE patterns for paths that skip CSRF validation.
     * @param bool         $enabled                Enable CSRF token validation on mutating requests.
     */
    public function __construct(
        public readonly string $bodyField            = '_csrf_token',
        public readonly string $headerName           = 'X-CSRF-Token',
        public readonly bool   $injectToken          = false,
        public readonly array  $excludedPathPatterns = [],
        public readonly bool   $enabled              = true,
    ) {
    }

    /** Returns a config with all defaults (no exclusions, injection disabled). */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Build from a plain associative array.
     *
     * Accepts both camelCase and snake_case keys; camelCase takes priority
     * when both are present to match typed config conventions elsewhere.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) (
                $config['enabled']
                ?? $config['csrf_enabled']
                ?? $config['csrfEnabled']
                ?? true
            ),
            bodyField: (string) ($config['bodyField'] ?? $config['body_field'] ?? '_csrf_token'),
            headerName: (string) ($config['headerName'] ?? $config['header_name'] ?? 'X-CSRF-Token'),
            injectToken: (bool) (
                $config['injectToken']
                ?? $config['inject_token']
                ?? $config['csrf_auto_html']
                ?? $config['csrfAutoHtml']
                ?? false
            ),
            excludedPathPatterns: (array) (
                $config['excludedPathPatterns']
                ?? $config['excluded_path_patterns']
                ?? $config['csrf_exceptions']
                ?? $config['csrfExceptions']
                ?? []
            ),
        );
    }
}
