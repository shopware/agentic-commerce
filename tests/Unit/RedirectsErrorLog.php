<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

/**
 * Keeps the output of `error_log()` out of the test run and lets a test read it.
 *
 * @internal
 */
trait RedirectsErrorLog
{
    private string $errorLogFile = '';

    private string $errorLogBeforeRedirect = '';

    private function redirectErrorLog(): void
    {
        $this->errorLogFile = sys_get_temp_dir().'/swag-agentic-error-log-'.bin2hex(random_bytes(6));
        $this->errorLogBeforeRedirect = (string) ini_set('error_log', $this->errorLogFile);
    }

    private function restoreErrorLog(): void
    {
        ini_set('error_log', $this->errorLogBeforeRedirect);

        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }
    }

    private function loggedErrors(): string
    {
        return is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
    }
}
