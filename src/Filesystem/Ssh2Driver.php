<?php

namespace CI4Installer\Filesystem;

use CI4Installer\Result;
use Throwable;
use ZipArchive;
use PharData;

class Ssh2Driver implements FilesystemInterface
{
    /** @var resource|false */
    private mixed $session = false;

    /** @var resource|false */
    private mixed $sftp = false;

    public function __construct(
        private string $hostname,
        private string $username,
        private string $password,
        private int    $port = 22,
    ) {
        $this->connect();
    }

    private function connect(): void
    {
        if (! function_exists('ssh2_connect')) {
            $this->session = false;
            return;
        }

        $session = @ssh2_connect($this->hostname, $this->port);
        if ($session === false) {
            $this->session = false;
            return;
        }

        $authed = @ssh2_auth_password($session, $this->username, $this->password);
        if (! $authed) {
            $this->session = false;
            return;
        }

        $sftp = @ssh2_sftp($session);
        if ($sftp === false) {
            $this->session = false;
            return;
        }

        $this->session = $session;
        $this->sftp    = $sftp;
    }

    private function assertConnected(): ?Result
    {
        if ($this->session === false || $this->sftp === false) {
            return Result::fail("SSH2 connection not established to {$this->hostname}:{$this->port}");
        }

        return null;
    }

    /**
     * Build an ssh2.sftp:// stream URL for the given remote path.
     */
    private function sftpUrl(string $path): string
    {
        // intval() on an sftp resource gives the resource id needed for the stream wrapper
        return 'ssh2.sftp://' . intval($this->sftp) . $path;
    }

    public function write(string $path, string $content): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            $dir = dirname($path);
            if ($dir !== '' && $dir !== '.' && ! $this->sftpDirExists($dir)) {
                $mkResult = $this->mkdir($dir, true);
                if (! $mkResult->success) {
                    return $mkResult;
                }
            }

            $stream = @fopen($this->sftpUrl($path), 'wb');
            if ($stream === false) {
                return Result::fail("SSH2 SFTP failed to open file for writing: {$path}");
            }

            $written = fwrite($stream, $content);
            fclose($stream);

            if ($written === false) {
                return Result::fail("SSH2 SFTP write failed for: {$path}");
            }

            return Result::ok($written);
        } catch (Throwable $e) {
            return Result::fail("SSH2 write error for {$path}: " . $e->getMessage());
        }
    }

    public function read(string $path): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            $stream = @fopen($this->sftpUrl($path), 'rb');
            if ($stream === false) {
                return Result::fail("SSH2 SFTP failed to open file for reading: {$path}");
            }

            $content = stream_get_contents($stream);
            fclose($stream);

            if ($content === false) {
                return Result::fail("SSH2 SFTP read failed for: {$path}");
            }

            return Result::ok($content);
        } catch (Throwable $e) {
            return Result::fail("SSH2 read error for {$path}: " . $e->getMessage());
        }
    }

    public function mkdir(string $path, bool $recursive = true): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            if ($this->sftpDirExists($path)) {
                return Result::ok();
            }

            if ($recursive) {
                return $this->mkdirRecursive($path);
            }

            $result = @ssh2_sftp_mkdir($this->sftp, $path, 0755, false);
            if (! $result) {
                return Result::fail("SSH2 SFTP mkdir failed for: {$path}");
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("SSH2 mkdir error for {$path}: " . $e->getMessage());
        }
    }

    private function mkdirRecursive(string $path): Result
    {
        $parts       = explode('/', ltrim($path, '/'));
        $currentPath = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $currentPath .= '/' . $part;

            if ($this->sftpDirExists($currentPath)) {
                continue;
            }

            $result = @ssh2_sftp_mkdir($this->sftp, $currentPath, 0755, false);
            if (! $result && ! $this->sftpDirExists($currentPath)) {
                return Result::fail("SSH2 SFTP mkdir failed for segment: {$currentPath}");
            }
        }

        return Result::ok();
    }

    private function sftpDirExists(string $path): bool
    {
        if ($this->sftp === false) {
            return false;
        }

        $stat = @ssh2_sftp_stat($this->sftp, $path);
        return $stat !== false;
    }

    public function delete(string $path): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            // Try file delete first
            if (@ssh2_sftp_unlink($this->sftp, $path)) {
                return Result::ok();
            }

            // Try directory delete (recursive)
            return $this->deleteDirectory($path);
        } catch (Throwable $e) {
            return Result::fail("SSH2 delete error for {$path}: " . $e->getMessage());
        }
    }

    private function deleteDirectory(string $path): Result
    {
        $handle = @opendir($this->sftpUrl($path));
        if ($handle === false) {
            // Doesn't exist — treat as success
            return Result::ok();
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = rtrim($path, '/') . '/' . $entry;
            $result   = $this->delete($fullPath);
            if (! $result->success) {
                closedir($handle);
                return $result;
            }
        }

        closedir($handle);

        if (! @ssh2_sftp_rmdir($this->sftp, $path)) {
            return Result::fail("SSH2 SFTP rmdir failed for: {$path}");
        }

        return Result::ok();
    }

    public function exists(string $path): bool
    {
        if ($this->sftp === false) {
            return false;
        }

        $stat = @ssh2_sftp_stat($this->sftp, $path);
        return $stat !== false;
    }

    public function isWritable(string $path): bool
    {
        if ($this->session === false || $this->sftp === false) {
            return false;
        }

        try {
            $testPath = rtrim($path, '/') . '/.write_test_' . uniqid('', true);
            $stream   = @fopen($this->sftpUrl($testPath), 'wb');

            if ($stream === false) {
                return false;
            }

            fwrite($stream, 'test');
            fclose($stream);

            @ssh2_sftp_unlink($this->sftp, $testPath);

            return true;
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

            $result = @ssh2_sftp_chmod($this->sftp, $path, $mode);
            if (! $result) {
                return Result::fail("SSH2 SFTP chmod failed for {$path} to " . decoct($mode));
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("SSH2 chmod error for {$path}: " . $e->getMessage());
        }
    }

    public function copy(string $source, string $dest): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            $readResult = $this->read($source);
            if (! $readResult->success) {
                return $readResult;
            }

            return $this->write($dest, $readResult->content);
        } catch (Throwable $e) {
            return Result::fail("SSH2 copy error from {$source} to {$dest}: " . $e->getMessage());
        }
    }

    public function move(string $source, string $dest): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            $result = @ssh2_sftp_rename($this->sftp, $source, $dest);
            if (! $result) {
                return Result::fail("SSH2 SFTP rename failed from {$source} to {$dest}");
            }

            return Result::ok();
        } catch (Throwable $e) {
            return Result::fail("SSH2 move error from {$source} to {$dest}: " . $e->getMessage());
        }
    }

    public function listDir(string $path): Result
    {
        try {
            $err = $this->assertConnected();
            if ($err !== null) {
                return $err;
            }

            $handle = @opendir($this->sftpUrl($path));
            if ($handle === false) {
                return Result::fail("SSH2 SFTP failed to open directory: {$path}");
            }

            $entries = [];
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $entries[] = $entry;
            }

            closedir($handle);

            return Result::ok($entries);
        } catch (Throwable $e) {
            return Result::fail("SSH2 listDir error for {$path}: " . $e->getMessage());
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
            $localZip = tempnam(sys_get_temp_dir(), 'ci4ssh_zip_');
            if ($localZip === false) {
                return Result::fail("Failed to create local temp file for zip download");
            }

            $readResult = $this->read($zipPath);
            if (! $readResult->success) {
                @unlink($localZip);
                return $readResult;
            }

            file_put_contents($localZip, $readResult->content);

            // Extract locally to a temp directory
            $localDest = sys_get_temp_dir() . '/ci4ssh_extract_' . uniqid('', true);
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

            // Upload extracted files via SSH2/SFTP
            $uploadResult = $this->uploadDirectory($localDest, $destPath);
            $this->deleteLocalDir($localDest);

            return $uploadResult;
        } catch (Throwable $e) {
            return Result::fail("SSH2 extractZip error for {$zipPath}: " . $e->getMessage());
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
