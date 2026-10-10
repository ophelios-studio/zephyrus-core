<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Support;

/** Points session.save_path at a private directory for the duration of one test. */
trait IsolatedSessionSavePath
{
    private string $sessionSavePath;

    private string $previousSessionSavePath;

    private function useIsolatedSessionSavePath(): void
    {
        $this->sessionSavePath = sys_get_temp_dir() . '/zephyrus-session-' . bin2hex(random_bytes(8));
        mkdir($this->sessionSavePath, 0700);
        $this->previousSessionSavePath = (string) ini_get('session.save_path');
        ini_set('session.save_path', $this->sessionSavePath);
    }

    private function removeIsolatedSessionSavePath(): void
    {
        session_write_close();
        // Output may already have been sent in a separate process, which makes ini_set() warn.
        @ini_set('session.save_path', $this->previousSessionSavePath);

        foreach (glob($this->sessionSavePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->sessionSavePath);
    }
}
