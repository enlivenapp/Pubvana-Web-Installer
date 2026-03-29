<?php

namespace CI4Installer\Filesystem;

class FtpsDriver extends FtpDriver
{
    /**
     * Override the connect logic to use ftp_ssl_connect() instead of ftp_connect().
     */
    protected function connect(): void
    {
        if (! function_exists('ftp_ssl_connect')) {
            $this->connection = false;
            return;
        }

        $conn = @ftp_ssl_connect($this->hostname, $this->port, 30);
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
}
