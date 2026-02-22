<?php

declare(strict_types=1);

namespace Zephyrus\Validation;

use Zephyrus\Exceptions\ZephyrusException;

final class ValidationException extends ZephyrusException
{
    public function __construct(
        private readonly ErrorBag $errors,
        string $message = 'Validation failed.',
    ) {
        parent::__construct($message);
    }

    public static function fromErrorBag(ErrorBag $errors, string $message = 'Validation failed.'): self
    {
        return new self($errors, $message);
    }

    public function errors(): ErrorBag
    {
        return $this->errors;
    }
}
