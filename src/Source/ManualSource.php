<?php

namespace CI4Installer\Source;

use CI4Installer\Result;

/**
 * Last-resort source — always available, always fails with a message
 * prompting the user to upload the zip manually via the UI.
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

    public function download(string $targetDir): Result
    {
        return Result::fail(
            'Automatic download failed. Please upload the release zip file using the form below.'
        );
    }

    public function getZipUrl(): string
    {
        return $this->zipUrl;
    }
}
