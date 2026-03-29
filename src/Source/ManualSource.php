<?php

namespace CI4Installer\Source;

use CI4Installer\Result;

/**
 * Last-resort source that instructs the user to upload the zip manually.
 *
 * canExecute() always returns true so there is always at least one source
 * available. download() returns a structured Result::fail() that the UI layer
 * can detect to show the manual upload form.
 */
class ManualSource implements SourceInterface
{
    public function __construct(
        private readonly string $zipUrl,
    ) {}

    public function getLabel(): string
    {
        return 'Manual Upload';
    }

    public function canExecute(): bool
    {
        return true;
    }

    /**
     * Returns Result::fail() with a structured JSON payload so the UI layer
     * can detect this specific case and render the manual upload form.
     *
     * The content key 'manual_upload' is set to true to make detection easy.
     */
    public function download(string $targetDir): Result
    {
        $message = json_encode([
            'manual_upload' => true,
            'zip_url'       => $this->zipUrl,
            'instructions'  => [
                'Download the zip archive from the URL above.',
                'Upload the zip file using the form below.',
                'The installer will extract and install it for you.',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return Result::fail($message);
    }
}
