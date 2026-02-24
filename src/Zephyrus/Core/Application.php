<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Localization\Translator;

final readonly class Application
{
    public function __construct(
        public HttpKernel $kernel,
        public Translator $translator,
    ) {
    }

    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function trans(string $key, array $parameters = [], ?string $locale = null): string
    {
        return $this->translator->trans($key, $parameters, $locale);
    }
}
