<?php

namespace CI4Installer\Source;

use CI4Installer\Result;

/**
 * Downloads a project via `git clone`.
 */
class GitSource implements SourceInterface
{
    public function __construct(
        private readonly string $repoUrl,
    ) {}

    public function getLabel(): string
    {
        return 'Git';
    }

    /**
     * Returns true when exec() is callable AND git is reachable on PATH.
     */
    public function canExecute(): bool
    {
        if (!$this->execEnabled()) {
            return false;
        }

        $output   = [];
        $exitCode = 0;

        // On Windows `where` is the equivalent of `which`.
        if (PHP_OS_FAMILY === 'Windows') {
            exec('where git 2>&1', $output, $exitCode);
        } else {
            exec('which git 2>&1', $output, $exitCode);
        }

        return $exitCode === 0;
    }

    /**
     * Runs `git clone {repoUrl} {targetDir}`.
     */
    public function download(string $targetDir): Result
    {
        if (!$this->canExecute()) {
            return Result::fail('Git is not available in this environment.');
        }

        $safeUrl    = escapeshellarg($this->repoUrl);
        $safeTarget = escapeshellarg($targetDir);

        $command = "git clone {$safeUrl} {$safeTarget} 2>&1";

        $output   = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $outputText = implode("\n", $output);

        if ($exitCode !== 0) {
            return Result::fail(
                "git clone failed (exit {$exitCode}):\n{$outputText}"
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
