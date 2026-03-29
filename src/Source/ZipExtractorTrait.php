<?php

namespace CI4Installer\Source;

use CI4Installer\Result;

/**
 * Shared zip extraction and directory normalization logic for sources that
 * download a zip archive and need to extract it into a target directory.
 */
trait ZipExtractorTrait
{
    /**
     * Extract a zip archive to the given directory.
     *
     * Tries three methods in order:
     *  1. ZipArchive (PHP extension)
     *  2. exec('unzip …')   (system binary)
     *  3. PharData          (Phar extension)
     *
     * Returns Result::fail() if every method is unavailable or fails.
     */
    private function extractZip(string $zipPath, string $extractDir): Result
    {
        // --- 1. ZipArchive ---
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $openResult = $zip->open($zipPath);

            if ($openResult === true) {
                if (!is_dir($extractDir)) {
                    mkdir($extractDir, 0755, true);
                }

                $extracted = $zip->extractTo($extractDir);
                $zip->close();

                if ($extracted) {
                    return Result::ok();
                }

                return Result::fail('ZipArchive::extractTo() returned false for: ' . $zipPath);
            }

            // ZipArchive is available but could not open the file; fall through to next method.
        }

        // --- 2. exec('unzip …') ---
        if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            if (!is_dir($extractDir)) {
                mkdir($extractDir, 0755, true);
            }

            $safeZip    = escapeshellarg($zipPath);
            $safeTarget = escapeshellarg($extractDir);
            $output     = [];
            $exitCode   = 0;

            exec("unzip -o {$safeZip} -d {$safeTarget} 2>&1", $output, $exitCode);

            if ($exitCode === 0) {
                return Result::ok();
            }

            // unzip failed; fall through to PharData.
        }

        // --- 3. PharData ---
        if (extension_loaded('phar')) {
            try {
                if (!is_dir($extractDir)) {
                    mkdir($extractDir, 0755, true);
                }

                $phar = new \PharData($zipPath);
                $phar->extractTo($extractDir, null, true);

                return Result::ok();
            } catch (\Exception $e) {
                return Result::fail('PharData extraction failed: ' . $e->getMessage());
            }
        }

        return Result::fail(
            'No zip extraction method is available. '
            . 'Install the zip PHP extension, the unzip binary, or the phar extension.'
        );
    }

    /**
     * If the extraction produced exactly one top-level subdirectory, move its
     * contents up one level so $dir becomes the root of the project.
     */
    private function normalizeDirectory(string $dir): void
    {
        $entries = array_values(array_filter(
            (array) scandir($dir),
            static fn(string $e): bool => $e !== '.' && $e !== '..'
        ));

        if (count($entries) !== 1) {
            return;
        }

        $subPath = $dir . DIRECTORY_SEPARATOR . $entries[0];

        if (!is_dir($subPath)) {
            return;
        }

        // Move every item inside $subPath up to $dir.
        $children = array_filter(
            (array) scandir($subPath),
            static fn(string $e): bool => $e !== '.' && $e !== '..'
        );

        foreach ($children as $child) {
            rename(
                $subPath . DIRECTORY_SEPARATOR . $child,
                $dir    . DIRECTORY_SEPARATOR . $child
            );
        }

        // Remove the now-empty intermediate directory.
        rmdir($subPath);
    }
}
