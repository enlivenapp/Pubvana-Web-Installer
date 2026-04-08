<?php

namespace CI4Installer\Source;

use CI4Installer\Result;

/**
 * Downloads a zip archive via the cURL PHP extension, then extracts it.
 *
 * All work (download, extraction) happens inside the target directory
 * to avoid cross-device rename failures.
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

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // --- 1. Download zip into target dir ---
        $zipPath = $targetDir . DIRECTORY_SEPARATOR . '_download.zip';

        $fh = fopen($zipPath, 'wb');

        if ($fh === false) {
            return Result::fail('Unable to open file for writing: ' . $zipPath);
        }

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

        $ok       = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if ($ok === false) {
            @unlink($zipPath);
            return Result::fail("cURL download failed (HTTP {$httpCode}): {$curlErr}");
        }

        // --- 2. Verify zip magic bytes (PK) ---
        $fh = fopen($zipPath, 'rb');
        if ($fh === false) {
            @unlink($zipPath);
            return Result::fail('Cannot open downloaded file for verification.');
        }
        $magic = fread($fh, 2);
        fclose($fh);

        if ($magic !== 'PK') {
            @unlink($zipPath);
            return Result::fail(
                'Downloaded file does not appear to be a valid zip archive '
                . '(missing PK magic bytes). The server may have returned an error page.'
            );
        }

        // --- 3. Extract into target dir ---
        $extractResult = $this->extractZip($zipPath, $targetDir);
        @unlink($zipPath);

        if (!$extractResult->success) {
            return $extractResult;
        }

        // --- 4. Normalize (flatten single top-level dir) ---
        $this->normalizeDirectory($targetDir);

        return Result::ok();
    }
}
