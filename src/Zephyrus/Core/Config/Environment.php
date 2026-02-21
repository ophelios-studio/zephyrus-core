<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

enum Environment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Testing = 'testing';
    case Development = 'development';

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'prod', 'production' => self::Production,
            'stage', 'staging' => self::Staging,
            'test', 'testing' => self::Testing,
            'dev', 'development', 'local' => self::Development,
            default => self::Production,
        };
    }

    public function isProductionLike(): bool
    {
        return $this === self::Production || $this === self::Staging;
    }
}
