<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Immutable configuration for CsrfMiddleware.
 *
 * Bundles the three tuneable knobs in one place so that CsrfMiddleware
 * has a single, stable constructor signature and application config
 * arrays can be forwarded with fromArray().
 *
 * excludedPathPatterns
 * --------------------
 * A list of PCRE regex patterns matched against the request path
 * (everything after the host, including the leading "/").  Any path that
 * matches at least one pattern is exempt from CSRF validation entirely —
 * useful for webhook endpoints, API routes protected by other means, or
 * health-check URLs.
 *
 * Example:
 *
 *   $config = CsrfConfig::fromArray([
 *       'body_field'               => '_token',
 *       'header_name'              => 'X-XSRF-TOKEN',
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
     * @param list<string> $excludedPathPatterns   PCRE patterns for paths that skip CSRF validation.
     */
    public function __construct(
        public readonly string $bodyField            = '_csrf_token',
        public readonly string $headerName           = 'X-CSRF-Token',
        public readonly array  $excludedPathPatterns = [],
    ) {
    }

    /** Returns a config with all defaults (no exclusions). */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Build from a plain associative array.
     *
     * Accepts both camelCase and snake_case keys; snake_case takes priority
     * when both are present so that framework config files feel natural.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            bodyField: (string) ($config['body_field'] ?? $config['bodyField'] ?? '_csrf_token'),
            headerName: (string) ($config['header_name'] ?? $config['headerName'] ?? 'X-CSRF-Token'),
            excludedPathPatterns: (array) ($config['excluded_path_patterns'] ?? $config['excludedPathPatterns'] ?? []),
        );
    }
}
