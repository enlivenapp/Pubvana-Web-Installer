<?php

namespace CI4Installer\Source;

use CI4Installer\Result;

/**
 * Downloads a zip archive via file_get_contents() (stream wrappers), then extracts it.
 *
 * This is used as a fallback when the cURL extension is unavailable but
 * allow_url_fopen is enabled in php.ini.
 *
 * No resume support (file_get_contents does not support Range headers
 * natively in a simple way). Extraction and normalization are shared with
 * CurlSource via ZipExtractorTrait.
 */
class StreamSource implements SourceInterface
{
    use ZipExtractorTrait;

    /** Timeout in seconds for the HTTP stream context. */
    private const TIMEOUT = 300;

    public function __construct(
        private readonly string $zipUrl,
    ) {}

    public function getLabel(): string
    {
        return 'Direct Download';
    }

    public function canExecute(): bool
    {
        return (bool) ini_get('allow_url_fopen');
    }

    public function download(string $targetDir): Result
    {
        if (!$this->canExecute()) {
            return Result::fail('allow_url_fopen is disabled; cannot use stream-based download.');
        }

        // --- 1. Download via file_get_contents with a stream context ---
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'follow_location' => 1,
                'max_redirects'   => 10,
                'timeout'         => self::TIMEOUT,
                'user_agent'      => 'CI4-Installer/1.0',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($this->zipUrl, false, $context);

        if ($data === false) {
            return Result::fail(
                'file_get_contents() failed to download: ' . $this->zipUrl
                . '. Check that the URL is reachable and allow_url_fopen is enabled.'
            );
        }

        // --- 2. Verify zip magic bytes (PK) ---
        if (substr($data, 0, 2) !== 'PK') {
            return Result::fail(
                'Downloaded content does not appear to be a valid zip archive '
                . '(missing PK magic bytes). The server may have returned an error page.'
            );
        }

        // --- 3. Write to temp file ---
        $tmpDir  = sys_get_temp_dir();
        $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'ci4installer_stream_' . md5($this->zipUrl) . '.zip';

        if (file_put_contents($zipPath, $data) === false) {
            return Result::fail('Unable to write downloaded zip to temp file: ' . $zipPath);
        }

        // Free memory — the zip can be large.
        unset($data);

        // --- 4. Extract ---
        $extractDir = $tmpDir . DIRECTORY_SEPARATOR . 'ci4installer_stream_extract_' . md5($this->zipUrl);

        if (is_dir($extractDir)) {
            $this->removeDirectory($extractDir);
        }

        $extractResult = $this->extractZip($zipPath, $extractDir);

        if (!$extractResult->success) {
            @unlink($zipPath);
            return $extractResult;
        }

        // --- 5. Normalize (flatten single top-level dir) ---
        $this->normalizeDirectory($extractDir);

        // --- 6. Move extracted contents to target dir ---
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $moveResult = $this->moveContents($extractDir, $targetDir);

        // --- 7. Clean up temp files ---
        @unlink($zipPath);
        $this->removeDirectory($extractDir);

        return $moveResult;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Move all files and directories from $src into $dst.
     */
    private function moveContents(string $src, string $dst): Result
    {
        $entries = array_filter(
            (array) scandir($src),
            static fn(string $e): bool => $e !== '.' && $e !== '..'
        );

        foreach ($entries as $entry) {
            $srcPath = $src . DIRECTORY_SEPARATOR . $entry;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $entry;

            if (!rename($srcPath, $dstPath)) {
                return Result::fail("Failed to move '{$srcPath}' to '{$dstPath}'.");
            }
        }

        return Result::ok();
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = array_filter(
            (array) scandir($dir),
            static fn(string $e): bool => $e !== '.' && $e !== '..'
        );

        foreach ($entries as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        rmdir($dir);
    }
}
