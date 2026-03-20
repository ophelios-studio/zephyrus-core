<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Session\Flash;
use Zephyrus\Session\SessionManager;

final class FlashTest extends TestCase
{
    protected function setUp(): void
    {
        App::setSession(new SessionManager([]));
    }

    protected function tearDown(): void
    {
        App::reset();
    }

    public function testSuccessMessage(): void
    {
        Flash::success('It worked!');
        $flash = Flash::readAll();

        $this->assertSame(['It worked!'], $flash->success);
        $this->assertSame([], $flash->error);
        $this->assertSame([], $flash->warning);
        $this->assertSame([], $flash->info);
    }

    public function testErrorMessage(): void
    {
        Flash::error('Something failed.');
        $flash = Flash::readAll();

        $this->assertSame(['Something failed.'], $flash->error);
    }

    public function testWarningMessage(): void
    {
        Flash::warning('Watch out!');
        $flash = Flash::readAll();

        $this->assertSame(['Watch out!'], $flash->warning);
    }

    public function testInfoMessage(): void
    {
        Flash::info('FYI.');
        $flash = Flash::readAll();

        $this->assertSame(['FYI.'], $flash->info);
    }

    public function testArrayMessages(): void
    {
        Flash::error(['Field required.', 'Email invalid.']);
        $flash = Flash::readAll();

        $this->assertSame(['Field required.', 'Email invalid.'], $flash->error);
    }

    public function testMultipleCallsAppend(): void
    {
        Flash::success('First');
        Flash::success('Second');
        $flash = Flash::readAll();

        $this->assertSame(['First', 'Second'], $flash->success);
    }

    public function testReadAllClearsMessages(): void
    {
        Flash::success('Gone after read');
        Flash::readAll();

        $flash = Flash::readAll();
        $this->assertSame([], $flash->success);
    }

    public function testClearAll(): void
    {
        Flash::success('Will be cleared');
        Flash::error('Also cleared');
        Flash::clearAll();

        $flash = Flash::readAll();
        $this->assertSame([], $flash->success);
        $this->assertSame([], $flash->error);
    }

    public function testEmptyState(): void
    {
        $flash = Flash::readAll();

        $this->assertSame([], $flash->success);
        $this->assertSame([], $flash->error);
        $this->assertSame([], $flash->warning);
        $this->assertSame([], $flash->info);
    }

    public function testMixedTypes(): void
    {
        Flash::success('Good');
        Flash::error('Bad');
        Flash::warning('Careful');
        Flash::info('Note');

        $flash = Flash::readAll();

        $this->assertSame(['Good'], $flash->success);
        $this->assertSame(['Bad'], $flash->error);
        $this->assertSame(['Careful'], $flash->warning);
        $this->assertSame(['Note'], $flash->info);
    }

    public function testNoSessionGracefulDegradation(): void
    {
        App::reset();

        // Should not throw — just returns empty.
        Flash::success('ignored');
        $flash = Flash::readAll();

        $this->assertSame([], $flash->success);
    }
}
