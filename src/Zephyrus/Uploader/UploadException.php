<?php

declare(strict_types=1);

namespace Zephyrus\Uploader;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class UploadException extends ZephyrusRuntimeException
{
    public static function invalidUploadArrayShape(string $field): self
    {
        return new self(sprintf('Invalid upload payload for field "%s".', $field));
    }
}
