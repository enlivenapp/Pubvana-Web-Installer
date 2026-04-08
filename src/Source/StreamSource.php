<?php

namespace CI4Installer\Source;

use CI4Installer\Result;

/**
 * Downloads a zip archive via file_get_contents() (stream wrappers), then extracts it.
 *
 * Fallback when the cURL extension is unavailable but allow_url_fopen is enabled.
 * All work happens inside the target directory to avoid cross-device rename failures.
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

        // --- 1. Download via file_get_contents ---
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

        // --- 3. Write zip into target dir ---
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $zipPath = $targetDir . DIRECTORY_SEPARATOR . '_download.zip';

        if (file_put_contents($zipPath, $data) === false) {
            return Result::fail('Unable to write downloaded zip to: ' . $zipPath);
        }

        unset($data);

        // --- 4. Extract into target dir ---
        $extractResult = $this->extractZip($zipPath, $targetDir);
        @unlink($zipPath);

        if (!$extractResult->success) {
            return $extractResult;
        }

        // --- 5. Normalize (flatten single top-level dir) ---
        $this->normalizeDirectory($targetDir);

        return Result::ok();
    }
}
