<?php

namespace CI4Installer\Filesystem;

use CI4Installer\Result;
use Throwable;
use ZipArchive;
use PharData;

class FtpDriver implements FilesystemInterface
{
    /** @var resource|false */
    protected mixed $connection = false;

    public function __construct(
        protected string $hostname,
        protected string $username,
        protected string $password,
        protected int    $port    = 21,
        protected bool   $passive = true,
    ) {
        $this->connect();
    }

    public function __destruct()
    {
        if ($this->connection !== false) {
            @ftp_close($this->connection);
            $this->connection = false;
        }
    }

    protected function connect(): void
    {
        $conn = @ftp_connect($this->hostname, $this->port, 30);
        if ($conn === false) {
            $this->connection = false;
            return;
        }

        $loggedIn = @ftp_login($conn, $this->username, $this->password);
        if (! $loggedIn) {
            @ftp_close($conn);
            $this->connection = false;
            return;
        }

        ftp_pasv($conn, $this->passive);

        $this->connection = $conn;
    }

    protected function assertConnected(): ?Result
    {
        if ($this->connection === false) {
            return Result::fail("FTP connection not established to {$this->hostname}:{$this->port}");
        }

        return null;
    }

    public function write(string $path, string $content): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            // Write content to a local temp file, then upload via FTP
            $tmp = tempnam(sys_get_temp_dir(), 'ci4ftp_');
            if ($tmp === false) {
                return Result::fail("Failed to create temp file for FTP upload");
            }

            file_put_contents($tmp, $content);

            $result = @ftp_put($this->connection, $path, $tmp, FTP_BINARY);
            @unlink($tmp);

            if (! $result) {
                return Result::fail("FTP put failed for: {$path}");
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("FTP write error for {$path}: " . $e->getMessage());
        }
    }

    public function read(string $path): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'ci4ftp_');
            if ($tmp === false) {
                return Result::fail("Failed to create temp file for FTP download");
            }

            $result = @ftp_get($this->connection, $tmp, $path, FTP_BINARY);
            if (! $result) {
                @unlink($tmp);
                return Result::fail("FTP get failed for: {$path}");
            }

            $content = file_get_contents($tmp);
            @unlink($tmp);

            if ($content === false) {
                return Result::fail("Failed to read downloaded temp file for: {$path}");
            }

            return Result::ok($content);
        } catch (Throwable $e) {
            return Result::fail("FTP read error for {$path}: " . $e->getMessage());
        }
    }

    public function mkdir(string $path, bool $recursive = true): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            if ($recursive) {
                return $this->mkdirRecursive($path);
            }

            $created = @ftp_mkdir($this->connection, $path);
            if ($created === false) {
                return Result::fail("FTP mkdir failed for: {$path}");
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("FTP mkdir error for {$path}: " . $e->getMessage());
        }
    }

    private function mkdirRecursive(string $path): Result
    {
        $parts     = explode('/', ltrim($path, '/'));
        $currentPath = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $currentPath .= '/' . $part;

            // Try to change into directory first — if it succeeds it already exists
            $cwd = @ftp_pwd($this->connection);
            if (@ftp_chdir($this->connection, $currentPath)) {
                @ftp_chdir($this->connection, $cwd);
                continue;
            }

            $created = @ftp_mkdir($this->connection, $currentPath);
            if ($created === false) {
                // Directory might have been created by a race — try chdir again
                if (! @ftp_chdir($this->connection, $currentPath)) {
                    return Result::fail("FTP mkdir failed for segment: {$currentPath}");
                }
                @ftp_chdir($this->connection, $cwd);
            }
        }

        return Result::ok();
    }

    public function delete(string $path): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            // Try file delete first
            if (@ftp_delete($this->connection, $path)) {
                return Result::ok();
            }

            // Try directory delete (recursive)
            return $this->deleteDirectory($path);
        } catch (Throwable $e) {
            return Result::fail("FTP delete error for {$path}: " . $e->getMessage());
        }
    }

    private function deleteDirectory(string $path): Result
    {
        $list = @ftp_nlist($this->connection, $path);
        if ($list === false) {
            // Directory might not exist — treat as success
            return Result::ok();
        }

        foreach ($list as $entry) {
            // Exclude . and ..
            $basename = basename($entry);
            if ($basename === '.' || $basename === '..') {
                continue;
            }

            $result = $this->delete($entry);
            if (! $result->success) {
                return $result;
            }
        }

        if (! @ftp_rmdir($this->connection, $path)) {
            return Result::fail("FTP rmdir failed for: {$path}");
        }

        return Result::ok();
    }

    public function exists(string $path): bool
    {
        if ($this->connection === false) {
            return false;
        }

        // ftp_size returns -1 for directories or non-existent files
        $size = @ftp_size($this->connection, $path);
        if ($size >= 0) {
            return true;
        }

        // Try changing into the path to detect directories
        $cwd = @ftp_pwd($this->connection);
        if (@ftp_chdir($this->connection, $path)) {
            @ftp_chdir($this->connection, $cwd);
            return true;
        }

        return false;
    }

    public function isWritable(string $path): bool
    {
        if ($this->connection === false) {
            return false;
        }

        try {
            // Actually try writing a temp file
            $testPath = rtrim($path, '/') . '/.write_test_' . uniqid('', true);
            $tmp      = tempnam(sys_get_temp_dir(), 'ci4ftp_');
            if ($tmp === false) {
                return false;
            }

            file_put_contents($tmp, 'test');
            $result = @ftp_put($this->connection, $testPath, $tmp, FTP_BINARY);
            @unlink($tmp);

            if ($result) {
                @ftp_delete($this->connection, $testPath);
                return true;
            }

            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function chmod(string $path, int $mode): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            $result = @ftp_chmod($this->connection, $mode, $path);
            if ($result === false) {
                return Result::fail("FTP chmod failed for {$path} to " . decoct($mode));
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("FTP chmod error for {$path}: " . $e->getMessage());
        }
    }

    public function copy(string $source, string $dest): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            // Download then re-upload
            $readResult = $this->read($source);
            if (! $readResult->success) {
                return $readResult;
            }

            return $this->write($dest, $readResult->content);
        } catch (Throwable $e) {
            return Result::fail("FTP copy error from {$source} to {$dest}: " . $e->getMessage());
        }
    }

    public function move(string $source, string $dest): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            if (! @ftp_rename($this->connection, $source, $dest)) {
                return Result::fail("FTP rename failed from {$source} to {$dest}");
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("FTP move error from {$source} to {$dest}: " . $e->getMessage());
        }
    }

    public function listDir(string $path): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            $list = @ftp_nlist($this->connection, $path);
            if ($list === false) {
                return Result::fail("FTP nlist failed for: {$path}");
            }

            $entries = array_values(
                array_filter(
                    array_map('basename', $list),
                    static fn($e) => $e !== '.' && $e !== '..'
                )
            );

            return Result::ok($entries);
        } catch (Throwable $e) {
            return Result::fail("FTP listDir error for {$path}: " . $e->getMessage());
        }
    }

    public function extractZip(string $zipPath, string $destPath): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            // Download the remote zip to a local temp file
            $localZip = tempnam(sys_get_temp_dir(), 'ci4ftp_zip_');
            if ($localZip === false) {
                return Result::fail("Failed to create local temp file for zip download");
            }

            $downloaded = @ftp_get($this->connection, $localZip, $zipPath, FTP_BINARY);
            if (! $downloaded) {
                @unlink($localZip);
                return Result::fail("Failed to download zip from FTP: {$zipPath}");
            }

            // Extract locally to a temp directory
            $localDest = sys_get_temp_dir() . '/ci4ftp_extract_' . uniqid('', true);
            if (! mkdir($localDest, 0755, true)) {
                @unlink($localZip);
                return Result::fail("Failed to create local temp extract directory");
            }

            $extracted = $this->extractZipLocally($localZip, $localDest);
            @unlink($localZip);

            if (! $extracted->success) {
                $this->deleteLocalDir($localDest);
                return $extracted;
            }

            // Upload extracted files via FTP
            $uploadResult = $this->uploadDirectory($localDest, $destPath);
            $this->deleteLocalDir($localDest);

            return $uploadResult;
        } catch (Throwable $e) {
            return Result::fail("FTP extractZip error for {$zipPath}: " . $e->getMessage());
        }
    }

    private function extractZipLocally(string $zipPath, string $destPath): Result
    {
        if (class_exists(ZipArchive::class)) {
            $zip    = new ZipArchive();
            $opened = $zip->open($zipPath);

            if ($opened === true) {
                $ok = $zip->extractTo($destPath);
                $zip->close();

                if ($ok) {
                    return Result::ok();
                }
            }
        }

        if (class_exists(PharData::class)) {
            try {
                $phar = new PharData($zipPath);
                $phar->extractTo($destPath, null, true);
                return Result::ok();
            } catch (Throwable $e) {
                return Result::fail("PharData extraction failed: " . $e->getMessage());
            }
        }

        return Result::fail("No ZIP extraction method available (ZipArchive and PharData both unavailable)");
    }

    private function uploadDirectory(string $localDir, string $remotePath): Result
    {
        $mkResult = $this->mkdir($remotePath, true);
        if (! $mkResult->success) {
            return $mkResult;
        }

        $entries = scandir($localDir);
        if ($entries === false) {
            return Result::fail("Failed to scan local directory for upload: {$localDir}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $localPath  = $localDir . DIRECTORY_SEPARATOR . $entry;
            $remoteFull = rtrim($remotePath, '/') . '/' . $entry;

            if (is_dir($localPath)) {
                $result = $this->uploadDirectory($localPath, $remoteFull);
            } else {
                $content = file_get_contents($localPath);
                if ($content === false) {
                    return Result::fail("Failed to read local file for upload: {$localPath}");
                }
                $result = $this->write($remoteFull, $content);
            }

            if (! $result->success) {
                return $result;
            }
        }

        return Result::ok();
    }

    private function deleteLocalDir(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);
            return;
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteLocalDir($path . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($path);
    }
}
