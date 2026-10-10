<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PDOException;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Data\DatabaseException;
use Zephyrus\Exceptions\ZephyrusException;
use Zephyrus\FileSystem\FileSystemException;
use Zephyrus\Formatting\FormatterException;
use Zephyrus\Localization\LocalizationException;
use Zephyrus\Mailer\MailerException;
use Zephyrus\Rendering\RenderException;
use Zephyrus\Routing\Exception\RouteCacheException;
use Zephyrus\Security\CryptographyException;
use Zephyrus\Session\SessionException;
use Zephyrus\Upload\UploadException;
use Zephyrus\Validation\ErrorBag;
use Zephyrus\Validation\ValidationException;

final class ThrowSiteTest extends TestCase
{
    /**
     * @return iterable<string, array{0: \Closure(): \Throwable, 1: int}>
     */
    public static function factoryCalls(): iterable
    {
        yield 'database connection' => [static fn (): \Throwable => DatabaseException::connectionFailed('dsn', 'refused'), __LINE__];
        yield 'database query' => [static fn (): \Throwable => DatabaseException::queryFailed('SELECT 1', 'boom'), __LINE__];
        yield 'database nested factory' => [static fn (): \Throwable => DatabaseException::queryExecutionFailed('SELECT 1', new PDOException('boom')), __LINE__];
        yield 'upload' => [static fn (): \Throwable => UploadException::invalidArrayShape(), __LINE__];
        yield 'route cache' => [static fn (): \Throwable => RouteCacheException::staleCache('older than sources'), __LINE__];
        yield 'configuration' => [static fn (): \Throwable => ConfigurationException::missingRequired('database', 'host'), __LINE__];
        yield 'configuration nested factory' => [static fn (): \Throwable => ConfigurationException::fileNotFound('/etc/app.php'), __LINE__];
        yield 'mailer' => [static fn (): \Throwable => MailerException::invalidFromAddress(), __LINE__];
        yield 'validation' => [static fn (): \Throwable => ValidationException::fromErrorBag(new ErrorBag()), __LINE__];
        yield 'session' => [static fn (): \Throwable => SessionException::invalidKey('a key'), __LINE__];
        yield 'cryptography' => [static fn (): \Throwable => CryptographyException::invalidArgument('bad'), __LINE__];
        yield 'localization' => [static fn (): \Throwable => LocalizationException::formatterRequired('money', 'price'), __LINE__];
        yield 'formatting' => [static fn (): \Throwable => FormatterException::invalidGroupingSeparator('must not be empty'), __LINE__];
        yield 'filesystem' => [static fn (): \Throwable => FileSystemException::notFound('/var/data'), __LINE__];
        yield 'rendering' => [static fn (): \Throwable => RenderException::engineError('template failed'), __LINE__];
    }

    /**
     * @param \Closure(): \Throwable $factory
     */
    #[DataProvider('factoryCalls')]
    public function testFactoryReportsTheLineThatCalledIt(\Closure $factory, int $line): void
    {
        $exception = $factory();

        self::assertSame(__FILE__, $exception->getFile());
        self::assertSame($line, $exception->getLine());
    }

    public function testDirectConstructionReportsTheConstructionLine(): void
    {
        $exception = new ZephyrusException('x');
        $line = __LINE__ - 1;

        self::assertSame(__FILE__, $exception->getFile());
        self::assertSame($line, $exception->getLine());
    }

    public function testApplicationSubclassFactoryReportsTheLineThatCalledIt(): void
    {
        $line = __LINE__ + 1;
        $exception = ThrowSiteAppException::forUser();

        self::assertSame(__FILE__, $exception->getFile());
        self::assertSame($line, $exception->getLine());
    }

    public function testExceptionBuiltInsideNonExceptionHelperReportsTheHelperLine(): void
    {
        [$exception, $line] = (new ThrowSiteBuilder())->build();

        self::assertSame(__FILE__, $exception->getFile());
        self::assertSame($line, $exception->getLine());
    }

    public function testFactoryCalledByHelperInsideFactoryReportsTheHelperLine(): void
    {
        $helperLine = 0;
        $exception = ThrowSiteAppException::forHelper($helperLine);

        self::assertSame(__FILE__, $exception->getFile());
        self::assertSame($helperLine, $exception->getLine());
    }

    public function testConstructorKeepsMessageCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('cause');
        $exception = new ZephyrusException('message', 7, $previous);

        self::assertSame('message', $exception->getMessage());
        self::assertSame(7, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

final class ThrowSiteAppException extends ZephyrusException
{
    public static function forUser(): self
    {
        return new self('application failure');
    }

    public static function forHelper(int &$helperLine): ZephyrusException
    {
        [$exception, $helperLine] = (new ThrowSiteBuilder())->buildThroughFactory();

        return $exception;
    }
}

final class ThrowSiteBuilder
{
    /**
     * @return array{ZephyrusException, int}
     */
    public function build(): array
    {
        return [new ZephyrusException('built by a helper'), __LINE__];
    }

    /**
     * @return array{ZephyrusException, int}
     */
    public function buildThroughFactory(): array
    {
        return [ThrowSiteAppException::forUser(), __LINE__];
    }
}
