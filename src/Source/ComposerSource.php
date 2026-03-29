<?php

namespace CI4Installer\Source;

use CI4Installer\Result;

/**
 * Downloads a project via `composer create-project`.
 */
class ComposerSource implements SourceInterface
{
    public function __construct(
        private readonly string $package,
    ) {}

    public function getLabel(): string
    {
        return 'Composer';
    }

    /**
     * Returns true when exec() is callable AND composer is reachable on PATH.
     */
    public function canExecute(): bool
    {
        if (!$this->execEnabled()) {
            return false;
        }

        // Try the standard `composer` command first.
        $output   = [];
        $exitCode = 0;
        exec('composer --version 2>&1', $output, $exitCode);

        if ($exitCode === 0) {
            return true;
        }

        // On Windows, the wrapper script is composer.bat.
        if (PHP_OS_FAMILY === 'Windows') {
            exec('composer.bat --version 2>&1', $output, $exitCode);
            return $exitCode === 0;
        }

        return false;
    }

    /**
     * Runs `composer create-project {package} {targetDir} --no-dev --prefer-dist`.
     *
     * Composer creates the target directory itself, so the path is passed directly.
     */
    public function download(string $targetDir): Result
    {
        if (!$this->canExecute()) {
            return Result::fail('Composer is not available in this environment.');
        }

        $safePackage = escapeshellarg($this->package);
        $safeTarget  = escapeshellarg($targetDir);

        $command = "composer create-project {$safePackage} {$safeTarget} --no-dev --prefer-dist 2>&1";

        $output   = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $outputText = implode("\n", $output);

        if ($exitCode !== 0) {
            return Result::fail(
                "composer create-project failed (exit {$exitCode}):\n{$outputText}"
            );
        }

        return Result::ok($outputText);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function execEnabled(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }
}
