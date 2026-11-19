<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Support;

/** Sends error_log() to a private file for the duration of one test. */
trait CapturedErrorLog
{
    private string $errorLogFile;

    private string $previousErrorLog;

    private function captureErrorLog(): void
    {
        $this->errorLogFile = sys_get_temp_dir() . '/zephyrus-error-log-' . bin2hex(random_bytes(8));
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->errorLogFile);
    }

    private function capturedErrorLog(): string
    {
        return is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
    }

    /**
     * The captured lines, without the timestamp error_log() puts before each entry.
     *
     * @return list<string>
     */
    private function capturedErrorLogLines(): array
    {
        $log = rtrim($this->capturedErrorLog(), "\n");

        return $log === '' ? [] : explode("\n", (string) preg_replace('/^\[[^\]]*\] /m', '', $log));
    }

    private function releaseErrorLog(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }
    }
}
