<?php

declare(strict_types=1);

namespace Zephyrus2\Core\Config;

final readonly class ApplicationConfig
{
    public function __construct(
        public Environment $environment,
        public bool $debug,
    ) {
    }

    public static function fromArray(array $values): self
    {
        $environment = isset($values['environment'])
            ? Environment::fromString((string) $values['environment'])
            : Environment::Production;

        $debug = $values['debug'] ?? !$environment->isProductionLike();

        return new self(
            environment: $environment,
            debug: filter_var($debug, FILTER_VALIDATE_BOOL),
        );
    }
}
