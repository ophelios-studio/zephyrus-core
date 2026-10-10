<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Application-wide settings: the environment and whether debug output is enabled.
 */
final readonly class ApplicationConfig
{
    /** Accepted keys per property; any other key is refused. */
    private const array SPELLINGS = [
        'environment' => ['environment'],
        'debug' => ['debug'],
    ];

    public function __construct(
        public Environment $environment,
        public bool $debug,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     * @throws ConfigurationException when a key is unknown, or the debug value is not a recognisable boolean.
     */
    public static function fromArray(array $values): self
    {
        $keys = ConfigKeys::read('application', $values, self::SPELLINGS);

        $environment = $keys->value('environment') !== null
            ? Environment::fromString((string) $keys->value('environment'))
            : Environment::Production;

        $debug = $keys->value('debug') ?? !$environment->isProductionLike();

        return new self(
            environment: $environment,
            debug: ConfigBoolean::parse('application', 'debug', $debug),
        );
    }
}
