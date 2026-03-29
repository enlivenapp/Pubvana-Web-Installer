<?php

namespace CI4Installer\Source;

use CI4Installer\Result;

/**
 * Downloads a zip archive via the cURL PHP extension, then extracts it.
 *
 * Supports chunked/resume downloads: if a previous partial download was
 * recorded in the session, a Range header is added so the server can pick up
 * where it left off.
 */
class CurlSource implements SourceInterface
{
    use ZipExtractorTrait;

    /** Timeout in seconds for the cURL transfer. */
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
        return extension_loaded('curl');
    }

    public function download(string $targetDir): Result
    {
        if (!$this->canExecute()) {
            return Result::fail('The cURL PHP extension is not available.');
        }

        // --- 1. Prepare temp paths ---
        $tmpDir  = sys_get_temp_dir();
        $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'ci4installer_' . md5($this->zipUrl) . '.zip';

        // --- 2. Determine resume offset from session ---
        $resumeFrom = 0;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionKey = 'ci4installer_dl_bytes_' . md5($this->zipUrl);
            $resumeFrom = isset($_SESSION[$sessionKey]) ? (int) $_SESSION[$sessionKey] : 0;
        }

        // --- 3. Open temp file for writing (append if resuming) ---
        $fileMode = ($resumeFrom > 0 && file_exists($zipPath)) ? 'ab' : 'wb';
        $fh = fopen($zipPath, $fileMode);

        if ($fh === false) {
            return Result::fail('Unable to open temp file for writing: ' . $zipPath);
        }

        // --- 4. Init cURL ---
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->zipUrl,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FILE           => $fh,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_USERAGENT      => 'CI4-Installer/1.0',
        ]);

        if ($resumeFrom > 0) {
            curl_setopt($ch, CURLOPT_RANGE, "{$resumeFrom}-");
        }

        $ok      = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if ($ok === false) {
            return Result::fail("cURL download failed (HTTP {$httpCode}): {$curlErr}");
        }

        // --- 5. Verify zip magic bytes (PK) ---
        $magicResult = $this->verifyZipMagicBytes($zipPath);
        if (!$magicResult->success) {
            @unlink($zipPath);
            return $magicResult;
        }

        // --- 6. Extract ---
        $extractDir = $tmpDir . DIRECTORY_SEPARATOR . 'ci4installer_extract_' . md5($this->zipUrl);

        if (is_dir($extractDir)) {
            $this->removeDirectory($extractDir);
        }

        $extractResult = $this->extractZip($zipPath, $extractDir);

        if (!$extractResult->success) {
            @unlink($zipPath);
            return $extractResult;
        }

        // --- 7. Normalize (flatten single top-level dir) ---
        $this->normalizeDirectory($extractDir);

        // --- 8. Move extracted contents to target dir ---
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $moveResult = $this->moveContents($extractDir, $targetDir);

        // --- 9. Clean up temp files ---
        @unlink($zipPath);
        $this->removeDirectory($extractDir);

        // Clear session resume key on success.
        if ($moveResult->success && session_status() === PHP_SESSION_ACTIVE) {
            $sessionKey = 'ci4installer_dl_bytes_' . md5($this->zipUrl);
            unset($_SESSION[$sessionKey]);
        }

        return $moveResult;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function verifyZipMagicBytes(string $zipPath): Result
    {
        $fh = fopen($zipPath, 'rb');

        if ($fh === false) {
            return Result::fail('Cannot open downloaded file for verification: ' . $zipPath);
        }

        $magic = fread($fh, 2);
        fclose($fh);

        if ($magic !== 'PK') {
            return Result::fail(
                'Downloaded file does not appear to be a valid zip archive '
                . '(missing PK magic bytes). The server may have returned an error page.'
            );
        }

        return Result::ok();
    }

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
