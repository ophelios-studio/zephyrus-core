<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

/**
 * Immutable result of a static site build.
 */
final readonly class StaticBuildResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public int $pagesBuilt,
        public int $totalPaths,
        public float $elapsedMs,
        public array $errors,
        public string $outputDirectory,
    ) {
    }

    /**
     * Whether the build completed without errors.
     */
    public function isSuccessful(): bool
    {
        return $this->errors === [];
    }

    /**
     * Human-readable build summary suitable for CLI output.
     */
    public function summary(): string
    {
        $status = $this->isSuccessful() ? 'OK' : sprintf('%d error(s)', count($this->errors));
        return sprintf(
            'Built %d/%d pages in %.1fms [%s] -> %s',
            $this->pagesBuilt,
            $this->totalPaths,
            $this->elapsedMs,
            $status,
            $this->outputDirectory,
        );
    }
}
