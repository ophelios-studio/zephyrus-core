<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;
use Zephyrus\Core\Config\ConfigBoolean;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\SecurityConfig;

use function is_array;
use function is_string;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * Immutable configuration for CsrfMiddleware.
 *
 * ## excludedPathPatterns
 *
 * PCRE patterns matched against the request path (including the leading "/").
 * A path matching any of them skips CSRF validation.
 *
 * Every pattern must start with "^" or "\A", after an optional inline modifier such as "(?i)",
 * and end with "/", "$", "\z" or "\Z", or the constructor refuses it. A loose pattern is a hole:
 * "#^/api/public#" also exempts "/api/publicity/42/delete". Constructing with named arguments
 * is validated the same way as fromArray().
 *
 * To exempt a route and everything under it, end with "/" ("#^/webhooks/#"). To exempt the route
 * with and without children, list both "#^/webhooks$#" and "#^/webhooks/#".
 * To exempt exactly one path, end with "$" ("#^/logout$#").
 *
 * ## Forms
 *
 * Token injection is not supported: every state-changing form carries a hidden
 * input named after bodyField (`<input type="hidden" name="_csrf_token" value="...">`).
 *
 * Example:
 *
 *   $config = CsrfConfig::fromArray([
 *       'enabled'                  => true,
 *       'body_field'               => '_token',
 *       'header_name'              => 'X-XSRF-TOKEN',
 *       'excluded_path_patterns'   => [
 *           '#^/webhooks/#',
 *           '#^/api/v\d+/public/#',
 *       ],
 *   ]);
 *
 *   $mw = new CsrfMiddleware($tokenManager, $config);
 */
final class CsrfConfig
{
    /**
     * Refusal message for injectToken, without its final period. %s is the body field name.
     */
    public const string INJECTION_REFUSAL = 'Automatic token injection is not supported because it cannot follow '
        . 'the browser\'s HTML parsing and could send the token to another site. Add a hidden "%s" field to your '
        . 'form templates, filled with the escaped value of CsrfTokenManagerInterface::getToken()';

    private const string EXAMPLE_PATTERN = '#^/webhooks/#';

    /**
     * PCRE patterns for paths that skip CSRF validation, each one validated.
     *
     * @var list<string>
     */
    public readonly array $excludedPathPatterns;

    /**
     * @param string $bodyField            Name of the HTML hidden-field / POST body key.
     * @param string $headerName           HTTP header accepted as an alternative token source.
     * @param bool   $injectToken          Must be false: true is refused.
     * @param array<mixed> $excludedPathPatterns Anchored PCRE patterns; see the class docblock.
     * @param bool   $enabled              Enable CSRF token validation on mutating requests.
     * @throws InvalidArgumentException when $injectToken is true or an exclusion pattern is refused.
     */
    public function __construct(
        public readonly string $bodyField            = '_csrf_token',
        public readonly string $headerName           = 'X-CSRF-Token',
        /** @deprecated since 0.14, will be removed in 0.15. Leave it unset: true is refused. */
        public readonly bool   $injectToken          = false,
        array                  $excludedPathPatterns = [],
        public readonly bool   $enabled              = true,
    ) {
        if ($injectToken) {
            throw new InvalidArgumentException(sprintf(self::INJECTION_REFUSAL, $bodyField) . '.');
        }

        $this->excludedPathPatterns = self::normalizeExcludedPathPatterns($excludedPathPatterns);
    }

    /** Returns a config with all defaults (no exclusions). */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Build from the application's security configuration section.
     *
     * @throws InvalidArgumentException when an exclusion pattern is refused.
     */
    public static function fromSecurityConfig(SecurityConfig $security): self
    {
        return new self(
            enabled: $security->csrfEnabled,
            excludedPathPatterns: self::normalizeExcludedPathPatterns($security->csrfExceptions, 'security.csrf.exceptions[%s]'),
        );
    }

    /**
     * Build from a plain associative array.
     *
     * Accepted keys, in priority order when several are set (first non-null wins):
     * enabled | csrf_enabled | csrfEnabled (default true);
     * bodyField | body_field (default "_csrf_token");
     * headerName | header_name (default "X-CSRF-Token");
     * injectToken | inject_token | csrf_auto_html | csrfAutoHtml (must be false);
     * excludedPathPatterns | excluded_path_patterns | csrf_exceptions | csrfExceptions.
     *
     * @param array<string, mixed> $config
     * @throws InvalidArgumentException when injectToken is true or an exclusion pattern is refused.
     * @throws ConfigurationException when enabled or injectToken is not a recognisable boolean.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: ConfigBoolean::firstSet('csrf', $config, ['enabled', 'csrf_enabled', 'csrfEnabled'], true),
            bodyField: (string) ($config['bodyField'] ?? $config['body_field'] ?? '_csrf_token'),
            headerName: (string) ($config['headerName'] ?? $config['header_name'] ?? 'X-CSRF-Token'),
            injectToken: ConfigBoolean::firstSet(
                'csrf',
                $config,
                ['injectToken', 'inject_token', 'csrf_auto_html', 'csrfAutoHtml'],
                false,
            ),
            excludedPathPatterns: self::normalizeExcludedPathPatterns(
                $config['excludedPathPatterns']
                ?? $config['excluded_path_patterns']
                ?? $config['csrf_exceptions']
                ?? $config['csrfExceptions']
                ?? []
            ),
        );
    }

    /**
     * @param mixed  $patterns
     * @param string $label    Error label; %s is replaced by the pattern index.
     * @return list<string>
     */
    private static function normalizeExcludedPathPatterns(mixed $patterns, string $label = 'CSRF excluded path pattern at index %s'): array
    {
        if (!is_array($patterns)) {
            throw new InvalidArgumentException('CSRF excluded path patterns must be an array of regex strings.');
        }

        $normalized = [];
        foreach ($patterns as $index => $pattern) {
            $where = sprintf($label, (string) $index);

            if (!is_string($pattern) || trim($pattern) === '') {
                throw new InvalidArgumentException(sprintf('%s must be a non-empty string such as %s.', $where, self::EXAMPLE_PATTERN));
            }

            if (@preg_match($pattern, '') === false) {
                throw new InvalidArgumentException(sprintf('%s is not a valid regex: %s (expected a shape such as %s).', $where, $pattern, self::EXAMPLE_PATTERN));
            }

            self::assertPatternIsAnchored($pattern, $where);

            $normalized[] = $pattern;
        }

        return $normalized;
    }

    /**
     * Refuses a pattern that can match beyond the routes it names (see the class docblock).
     *
     * Syntactic on purpose: a shape a reader can verify beats an analysis that could be quietly wrong.
     *
     * @param string $where Configuration key or index of the pattern, used in the error message.
     */
    private static function assertPatternIsAnchored(string $pattern, string $where): void
    {
        $body = self::patternBody($pattern);

        if ($body === null) {
            throw new InvalidArgumentException(sprintf(
                '%s is not a delimited regex: %s (expected a shape such as %s).',
                $where,
                $pattern,
                self::EXAMPLE_PATTERN,
            ));
        }

        $anchorable = preg_replace('/^\(\?[a-zA-Z]+\)/', '', $body) ?? $body;

        if (!str_starts_with($anchorable, '^') && !str_starts_with($anchorable, '\A')) {
            throw new InvalidArgumentException(sprintf(
                '%s must start with "^" so it cannot match in the middle of a path: %s (expected a shape such as %s).',
                $where,
                $pattern,
                self::EXAMPLE_PATTERN,
            ));
        }

        $endsAtBoundary = str_ends_with($body, '/')
            || str_ends_with($body, '$')
            || str_ends_with($body, '\z')
            || str_ends_with($body, '\Z');

        if (!$endsAtBoundary) {
            throw new InvalidArgumentException(sprintf(
                '%s must end with "/" (the route and everything under it) or "$" (that exact path), otherwise '
                . 'it also exempts every sibling route sharing the prefix: %s (expected a shape such as %s).',
                $where,
                $pattern,
                self::EXAMPLE_PATTERN,
            ));
        }
    }

    /**
     * The expression between a PCRE string's delimiters, or null when the
     * string is not delimited at all.
     */
    private static function patternBody(string $pattern): ?string
    {
        if (strlen($pattern) < 2) {
            return null;
        }

        $opening = $pattern[0];
        $closing = match ($opening) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $opening,
        };

        $end = strrpos($pattern, $closing);

        if ($end === false || $end < 1) {
            return null;
        }

        return substr($pattern, 1, $end - 1);
    }
}
