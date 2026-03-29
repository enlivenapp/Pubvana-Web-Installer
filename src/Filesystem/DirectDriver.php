<?php

namespace CI4Installer\Filesystem;

use CI4Installer\Result;
use ZipArchive;
use PharData;
use Throwable;

class DirectDriver implements FilesystemInterface
{
    public function write(string $path, string $content): Result
    {
        try {
            $dir = dirname($path);
            if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
                $mkResult = $this->mkdir($dir, true);
                if (! $mkResult->success) {
                    return $mkResult;
                }
            }

            $bytes = file_put_contents($path, $content);
            if ($bytes === false) {
                return Result::fail("Failed to write file: {$path}");
            }

            return Result::ok($bytes);
        } catch (Throwable $e) {
            return Result::fail("Write error for {$path}: " . $e->getMessage());
        }
    }

    public function read(string $path): Result
    {
        try {
            if (! file_exists($path)) {
                return Result::fail("File does not exist: {$path}");
            }

            $content = file_get_contents($path);
            if ($content === false) {
                return Result::fail("Failed to read file: {$path}");
            }

            return Result::ok($content);
        } catch (Throwable $e) {
            return Result::fail("Read error for {$path}: " . $e->getMessage());
        }
    }

    public function mkdir(string $path, bool $recursive = true): Result
    {
        try {
            if (is_dir($path)) {
                return Result::ok();
            }

            $result = mkdir($path, 0755, $recursive);
            if (! $result) {
                return Result::fail("Failed to create directory: {$path}");
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("Mkdir error for {$path}: " . $e->getMessage());
        }
    }

    public function delete(string $path): Result
    {
        try {
            if (! file_exists($path) && ! is_link($path)) {
                return Result::ok();
            }

            if (is_dir($path) && ! is_link($path)) {
                return $this->deleteDirectory($path);
            }

            if (! unlink($path)) {
                return Result::fail("Failed to delete file: {$path}");
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("Delete error for {$path}: " . $e->getMessage());
        }
    }

    private function deleteDirectory(string $path): Result
    {
        $entries = scandir($path);
        if ($entries === false) {
            return Result::fail("Failed to scan directory for deletion: {$path}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $entry;
            $result   = $this->delete($fullPath);
            if (! $result->success) {
                return $result;
            }
        }

        if (! rmdir($path)) {
            return Result::fail("Failed to remove directory: {$path}");
        }

        return Result::ok();
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function isWritable(string $path): bool
    {
        try {
            // Determine the directory to test against
            $testDir = is_dir($path) ? $path : dirname($path);

            if (! is_dir($testDir)) {
                return false;
            }

            // Actually try writing a temp file — don't trust is_writable()
            $tempFile = $testDir . DIRECTORY_SEPARATOR . '.write_test_' . uniqid('', true);
            $result   = @file_put_contents($tempFile, 'test');

            if ($result === false) {
                return false;
            }

            @unlink($tempFile);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function chmod(string $path, int $mode): Result
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                return Result::ok();
            }

            if (! file_exists($path)) {
                return Result::fail("Path does not exist: {$path}");
            }

            if (! chmod($path, $mode)) {
                return Result::fail("Failed to chmod {$path} to " . decoct($mode));
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("Chmod error for {$path}: " . $e->getMessage());
        }
    }

    public function copy(string $source, string $dest): Result
    {
        try {
            if (! file_exists($source)) {
                return Result::fail("Source does not exist: {$source}");
            }

            $destDir = dirname($dest);
            if ($destDir !== '' && $destDir !== '.' && ! is_dir($destDir)) {
                $mkResult = $this->mkdir($destDir, true);
                if (! $mkResult->success) {
                    return $mkResult;
                }
            }

            if (! copy($source, $dest)) {
                return Result::fail("Failed to copy {$source} to {$dest}");
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("Copy error from {$source} to {$dest}: " . $e->getMessage());
        }
    }

    public function move(string $source, string $dest): Result
    {
        try {
            if (! file_exists($source) && ! is_link($source)) {
                return Result::fail("Source does not exist: {$source}");
            }

            $destDir = dirname($dest);
            if ($destDir !== '' && $destDir !== '.' && ! is_dir($destDir)) {
                $mkResult = $this->mkdir($destDir, true);
                if (! $mkResult->success) {
                    return $mkResult;
                }
            }

            if (! rename($source, $dest)) {
                return Result::fail("Failed to move {$source} to {$dest}");
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("Move error from {$source} to {$dest}: " . $e->getMessage());
        }
    }

    public function listDir(string $path): Result
    {
        try {
            if (! is_dir($path)) {
                return Result::fail("Not a directory: {$path}");
            }

            $entries = scandir($path);
            if ($entries === false) {
                return Result::fail("Failed to list directory: {$path}");
            }

            $filtered = array_values(array_filter($entries, static fn($e) => $e !== '.' && $e !== '..'));

            return Result::ok($filtered);
        } catch (Throwable $e) {
            return Result::fail("ListDir error for {$path}: " . $e->getMessage());
        }
    }

    public function extractZip(string $zipPath, string $destPath): Result
    {
        try {
            if (! file_exists($zipPath)) {
                return Result::fail("Zip file does not exist: {$zipPath}");
            }

            $mkResult = $this->mkdir($destPath, true);
            if (! $mkResult->success) {
                return $mkResult;
            }

            // Try ZipArchive first
            if (class_exists(ZipArchive::class)) {
                $zip = new ZipArchive();
                $opened = $zip->open($zipPath);

                if ($opened === true) {
                    $extracted = $zip->extractTo($destPath);
                    $zip->close();

                    if ($extracted) {
                        return Result::ok();
                    }
                }
            }

            // Fall back to PharData
            if (class_exists(PharData::class)) {
                $phar = new PharData($zipPath);
                $phar->extractTo($destPath, null, true);

                return Result::ok();
            }

            return Result::fail("No ZIP extraction method available (ZipArchive and PharData both unavailable)");
        } catch (Throwable $e) {
            return Result::fail("ExtractZip error for {$zipPath}: " . $e->getMessage());
        }
    }
}
