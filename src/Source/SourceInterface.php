<?php

namespace CI4Installer\Source;

use CI4Installer\Result;

interface SourceInterface
{
    public function canExecute(): bool;
    public function download(string $targetDir): Result;
    public function getLabel(): string;
}
