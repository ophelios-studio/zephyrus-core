<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Routing\Router;

final class FormatterGroupingWiringTest extends TestCase
{
    public function testLocalizationGroupingSeparatorReachesTheFormatter(): void
    {
        ApplicationBuilder::create()
            ->withConfigurationArray([
                'localization' => ['locale' => 'fr_CA', 'grouping_separator' => "\u{202F}"],
            ])
            ->withRouter(new Router())
            ->build();

        $formatter = App::getFormatter();
        self::assertNotNull($formatter);
        self::assertSame("1\u{202F}234\u{202F}567,89", $formatter->decimal(1234567.89));
    }
}
