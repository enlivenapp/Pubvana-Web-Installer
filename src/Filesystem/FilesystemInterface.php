<?php

namespace CI4Installer\Filesystem;

use CI4Installer\Result;

interface FilesystemInterface
{
    public function write(string $path, string $content): Result;
    public function read(string $path): Result;
    public function mkdir(string $path, bool $recursive = true): Result;
    public function delete(string $path): Result;
    public function exists(string $path): bool;
    public function isWritable(string $path): bool;
    public function chmod(string $path, int $mode): Result;
    public function copy(string $source, string $dest): Result;
    public function move(string $source, string $dest): Result;
    public function listDir(string $path): Result;
    public function extractZip(string $zipPath, string $destPath): Result;
}
