<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

final readonly class ApplicationConfig
{
    public function __construct(
        public Environment $environment,
        public bool $debug,
    ) {
    }

    /**
     * @throws ConfigurationException when the debug value is not a recognisable boolean.
     */
    public static function fromArray(array $values): self
    {
        $environment = isset($values['environment'])
            ? Environment::fromString((string) $values['environment'])
            : Environment::Production;

        $debug = $values['debug'] ?? !$environment->isProductionLike();

        return new self(
            environment: $environment,
            debug: ConfigBoolean::parse('application', 'debug', $debug),
        );
    }
}
