<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use ArrayIterator;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\ILogger;
use Tracy\Logger;
use Zephyrus\Core\DebugIntegration;

final class StaticFirstArrayIterator extends ArrayIterator
{
    public static int $instances = 0;
    protected string $label = 'visible-label';
}

class ProtectedTitleArrayIterator extends ArrayIterator
{
    protected string $title = 'protected-title';
}

final class PublicTitleArrayIterator extends ProtectedTitleArrayIterator
{
    public string $title = 'public-title';
}

final class DebugIntegrationSecretMaskingTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testALoggerSubclassWithoutADirectoryIsNotReplaced(): void
    {
        $logger = new class extends Logger {
            public function __construct()
            {
            }
        };
        Debugger::setLogger($logger);

        DebugIntegration::initialize(debug: true, serverApi: 'cli-server');

        self::assertSame($logger, Debugger::getLogger());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEachApplicationKeyStaysSensitiveAcrossInstallations(): void
    {
        DebugIntegration::installSecretMasking('first_session');
        DebugIntegration::installSecretMasking('second_session');
        DebugIntegration::installSecretMasking('first_session');
        DebugIntegration::installSecretMasking();

        self::assertTrue(DebugIntegration::isSensitiveKey('first_session'));
        self::assertTrue(DebugIntegration::isSensitiveKey('second_session'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReinstallingKeepsTheApplicationsOwnKeysAndTheHiddenListsFlatAndUnique(): void
    {
        Debugger::$keysToHide = ['custom_dump_key', 'password'];
        $blueScreen = Debugger::getBlueScreen();
        $blueScreen->keysToHide = ['custom_screen_key', 'password'];

        DebugIntegration::installSecretMasking('app_session');
        $dumpKeys = Debugger::$keysToHide;
        $screenKeys = $blueScreen->keysToHide;
        DebugIntegration::installSecretMasking('app_session');

        foreach (['dump' => $dumpKeys, 'bluescreen' => $screenKeys] as $label => $keys) {
            self::assertTrue(array_is_list($keys), "The $label key list has gaps.");
            self::assertSame($keys, array_values(array_unique($keys)), "The $label key list holds duplicates.");
            self::assertContains('app_session', $keys);
            self::assertContains('encryptionKey', $keys);
        }
        self::assertContains('custom_dump_key', $dumpKeys);
        self::assertContains('custom_screen_key', $screenKeys);
        self::assertSame($dumpKeys, Debugger::$keysToHide);
        self::assertSame($screenKeys, $blueScreen->keysToHide);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheBlueScreenDoesNotShowTheEnvironment(): void
    {
        DebugIntegration::installSecretMasking();

        self::assertFalse(Debugger::getBlueScreen()->showEnvironment);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheScrubberMasksAnExactNameWhateverItsValueAndWhateverALeadingDollar(): void
    {
        DebugIntegration::installSecretMasking('app_session');
        $scrubber = Debugger::getBlueScreen()->scrubber;

        self::assertNotNull($scrubber);
        self::assertTrue($scrubber('$pepper', 12, null));
        self::assertTrue($scrubber('$PEPPER', null, null));
        self::assertTrue($scrubber('$app_session', 12, null));
        self::assertTrue($scrubber('$webhookSecret', 'text', null));
        self::assertFalse($scrubber('$webhookSecret', 12, null));
        self::assertFalse($scrubber('$visible', 'text', null));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAClosureDumpShowsItsLocationItsParametersAndItsCapturedVariables(): void
    {
        DebugIntegration::installSecretMasking();
        $shown = 'visible-capture';
        $line = __LINE__ + 1;
        $closure = static function (string $first, int $second) use ($shown): void {
        };

        $text = Dumper::toText($closure, [Dumper::LOCATION => true]);

        self::assertStringStartsWith('Closure($first, $second) #', $text);
        self::assertStringContainsString("file: '" . __FILE__ . ':' . $line . "'", $text);
        self::assertStringContainsString("use: \$shown\n", $text);
        self::assertStringContainsString("\$shown: 'visible-capture'", $text);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAClosureDumpShowsNoLocationUnlessAsked(): void
    {
        DebugIntegration::installSecretMasking();

        $text = Dumper::toText(static fn (): int => 1);

        self::assertStringNotContainsString('file:', $text);
        self::assertStringStartsWith('Closure() #', $text);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAClosureDumpListsEveryCapturedNameInOneCollapsedGroup(): void
    {
        DebugIntegration::installSecretMasking();
        $alpha = 1;
        $beta = 2;
        $closure = static fn (): int => $alpha + $beta;

        $text = Dumper::toText($closure);
        $html = Dumper::toHtml($closure);

        self::assertStringContainsString("use: \$alpha, \$beta\n", $text);
        self::assertStringContainsString('"collapsed":true', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAClosureWithoutCapturedVariablesHasNoUseGroup(): void
    {
        DebugIntegration::installSecretMasking();

        $text = Dumper::toText(static fn (): int => 1);

        self::assertStringNotContainsString('use', $text);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheCapturedVariablesOfAClosureCountOneLevelDeeperThanTheClosure(): void
    {
        DebugIntegration::installSecretMasking();
        $nested = ['first' => ['second' => ['third' => 'bottom']]];
        $closure = static fn (): array => $nested;

        $text = Dumper::toText($closure, [Dumper::DEPTH => 3]);

        self::assertStringContainsString("'first' => array (1)", $text);
        self::assertStringNotContainsString("'second'", $text);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAPhpMailerDumpShowsItsTransportSettings(): void
    {
        DebugIntegration::installSecretMasking();
        $mail = new PHPMailer();
        $mail->Host = 'smtp.example.test';
        $mail->Port = 2525;
        $mail->SMTPSecure = 'starttls';
        $mail->SMTPAuth = true;
        $mail->CharSet = 'iso-8859-1';

        $text = Dumper::toText($mail);

        self::assertStringContainsString("Host: 'smtp.example.test'", $text);
        self::assertStringContainsString('Port: 2525', $text);
        self::assertStringContainsString("SMTPSecure: 'starttls'", $text);
        self::assertStringContainsString('SMTPAuth: true', $text);
        self::assertStringContainsString("CharSet: 'iso-8859-1'", $text);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnArrayIteratorSubclassListsAProtectedPropertyDeclaredAfterAStaticOneAsProtected(): void
    {
        DebugIntegration::installSecretMasking();

        $text = Dumper::toText(new StaticFirstArrayIterator(['a' => 1]));

        self::assertMatchesRegularExpression("/^ +label: 'visible-label'/m", $text);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnArrayIteratorSubclassListsAPropertyMadePublicOnceNotAlsoAsTheProtectedOne(): void
    {
        DebugIntegration::installSecretMasking();

        $text = Dumper::toText(new PublicTitleArrayIterator(['a' => 1]));

        self::assertStringContainsString("title: 'public-title'", $text);
        self::assertSame(1, substr_count($text, 'title:'));
    }
}
