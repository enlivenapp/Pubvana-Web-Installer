<?php

namespace CI4Installer\Database;

use CI4Installer\Result;
use SQLite3;
use Throwable;

/**
 * Tests database connections for all four CI4-supported drivers.
 *
 * Each test() call attempts a real connection and returns a Result with
 * a specific, actionable error message on failure — never a generic one.
 */
class ConnectionTester
{
    // ---------------------------------------------------------------------------
    // Rate limiting
    // ---------------------------------------------------------------------------

    private const RATE_LIMIT_MAX      = 10;
    private const RATE_LIMIT_WINDOW   = 300; // 5 minutes in seconds
    private const RATE_LIMIT_KEY      = 'ci4_installer_db_attempts';

    /**
     * Checks whether the caller has exceeded the connection-attempt rate limit.
     *
     * Attempts are stored in $_SESSION as a list of Unix timestamps.
     * Any timestamps older than the window are pruned before the check.
     *
     * @return bool TRUE if the request is within limits and may proceed.
     */
    private function checkRateLimit(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $now      = time();
        $attempts = $_SESSION[self::RATE_LIMIT_KEY] ?? [];

        // Drop entries outside the rolling window.
        $attempts = array_values(array_filter(
            $attempts,
            static fn(int $ts): bool => ($now - $ts) < self::RATE_LIMIT_WINDOW,
        ));

        if (count($attempts) >= self::RATE_LIMIT_MAX) {
            $_SESSION[self::RATE_LIMIT_KEY] = $attempts;
            return false;
        }

        $attempts[] = $now;
        $_SESSION[self::RATE_LIMIT_KEY] = $attempts;

        return true;
    }

    // ---------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------

    /**
     * Tests a database connection for the given CI4 driver.
     *
     * @param string $driver      One of: MySQLi, Postgre, SQLite3, SQLSRV
     * @param array  $credentials Keys: hostname, port, database, username, password (server-based)
     *                            or path (SQLite3).
     */
    public function test(string $driver, array $credentials): Result
    {
        if (! $this->checkRateLimit()) {
            return Result::fail(
                'Rate limit exceeded: too many connection attempts. Please wait a few minutes and try again.'
            );
        }

        return match ($driver) {
            'MySQLi'  => $this->testMySQLi($credentials),
            'Postgre' => $this->testPostgre($credentials),
            'SQLite3' => $this->testSQLite3($credentials),
            'SQLSRV'  => $this->testSQLSRV($credentials),
            default   => Result::fail("Unknown driver '{$driver}'. Must be one of: MySQLi, Postgre, SQLite3, SQLSRV."),
        };
    }

    /**
     * Creates the target database using the given driver.
     *
     * Connects without selecting a database, then issues CREATE DATABASE.
     * For SQLite3 the file is simply created on first open.
     *
     * @param string $driver      One of: MySQLi, Postgre, SQLite3, SQLSRV
     * @param array  $credentials Same shape as test().
     */
    public function createDatabase(string $driver, array $credentials): Result
    {
        if (! $this->checkRateLimit()) {
            return Result::fail(
                'Rate limit exceeded: too many connection attempts. Please wait a few minutes and try again.'
            );
        }

        return match ($driver) {
            'MySQLi'  => $this->createMySQLi($credentials),
            'Postgre' => $this->createPostgre($credentials),
            'SQLite3' => $this->createSQLite3($credentials),
            'SQLSRV'  => $this->createSQLSRV($credentials),
            default   => Result::fail("Unknown driver '{$driver}'. Must be one of: MySQLi, Postgre, SQLite3, SQLSRV."),
        };
    }

    // ---------------------------------------------------------------------------
    // MySQLi — test
    // ---------------------------------------------------------------------------

    private function testMySQLi(array $creds): Result
    {
        $host = $creds['hostname'] ?? '127.0.0.1';
        $user = $creds['username'] ?? '';
        $pass = $creds['password'] ?? '';
        $db   = $creds['database'] ?? '';
        $port = isset($creds['port']) ? (int) $creds['port'] : 3306;

        // Suppress warnings — we inspect the error ourselves.
        mysqli_report(MYSQLI_REPORT_OFF);

        $conn = @mysqli_connect($host, $user, $pass, '', $port);

        if ($conn === false) {
            $errno = mysqli_connect_errno();
            $error = mysqli_connect_error() ?? '';

            // 1045 = Access denied (bad credentials)
            // 2002 = Can't connect to server (unreachable)
            // 2003 = Can't connect to MySQL server (wrong port / firewall)
            if ($errno === 1045) {
                return Result::fail("Authentication failed for user '{$user}' on {$host}:{$port}.");
            }

            if (in_array($errno, [2002, 2003, 2005], true)) {
                return Result::fail("Server unreachable at {$host}:{$port}. ({$error})");
            }

            return Result::fail("MySQLi connection error ({$errno}): {$error}");
        }

        // Connected — now try to select the database.
        if ($db !== '') {
            $selected = @mysqli_select_db($conn, $db);
            mysqli_close($conn);

            if (! $selected) {
                // errno 1044 = access denied to db, 1049 = unknown database
                $errno = mysqli_errno($conn) ?: 0;

                // At this point $conn is closed; use the last known error string.
                // Re-open briefly just to get the error number.
                $conn2 = @mysqli_connect($host, $user, $pass, '', $port);
                if ($conn2 !== false) {
                    @mysqli_select_db($conn2, $db);
                    $errno = mysqli_errno($conn2);
                    mysqli_close($conn2);
                }

                if ($errno === 1044) {
                    return Result::fail("Authentication failed: user '{$user}' is not permitted to access database '{$db}'.");
                }

                // 1049 or anything else — the database simply doesn't exist.
                return Result::fail("Database '{$db}' does not exist on {$host}:{$port}.");
            }
        } else {
            mysqli_close($conn);
        }

        return Result::ok();
    }

    // ---------------------------------------------------------------------------
    // MySQLi — createDatabase
    // ---------------------------------------------------------------------------

    private function createMySQLi(array $creds): Result
    {
        $host = $creds['hostname'] ?? '127.0.0.1';
        $user = $creds['username'] ?? '';
        $pass = $creds['password'] ?? '';
        $db   = $creds['database'] ?? '';
        $port = isset($creds['port']) ? (int) $creds['port'] : 3306;

        mysqli_report(MYSQLI_REPORT_OFF);

        $conn = @mysqli_connect($host, $user, $pass, '', $port);

        if ($conn === false) {
            $errno = mysqli_connect_errno();
            $error = mysqli_connect_error() ?? '';

            if ($errno === 1045) {
                return Result::fail("Authentication failed for user '{$user}' on {$host}:{$port}.");
            }

            if (in_array($errno, [2002, 2003, 2005], true)) {
                return Result::fail("Server unreachable at {$host}:{$port}. ({$error})");
            }

            return Result::fail("MySQLi connection error ({$errno}): {$error}");
        }

        $escapedDb = mysqli_real_escape_string($conn, $db);
        $sql       = "CREATE DATABASE `{$escapedDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";

        if (! mysqli_query($conn, $sql)) {
            $error = mysqli_error($conn);
            $errno = mysqli_errno($conn);
            mysqli_close($conn);

            if ($errno === 1007) {
                return Result::fail("Database '{$db}' already exists.");
            }

            if ($errno === 1044 || $errno === 1227) {
                return Result::fail("User '{$user}' does not have CREATE DATABASE privileges on {$host}:{$port}.");
            }

            return Result::fail("Failed to create database '{$db}': ({$errno}) {$error}");
        }

        mysqli_close($conn);

        return Result::ok("Database '{$db}' created successfully.");
    }

    // ---------------------------------------------------------------------------
    // Postgre — test
    // ---------------------------------------------------------------------------

    private function testPostgre(array $creds): Result
    {
        if (! function_exists('pg_connect')) {
            return Result::fail('The PostgreSQL PHP extension (pgsql) is not loaded on this server.');
        }

        $host = $creds['hostname'] ?? '127.0.0.1';
        $user = $creds['username'] ?? '';
        $pass = $creds['password'] ?? '';
        $db   = $creds['database'] ?? 'postgres';
        $port = isset($creds['port']) ? (int) $creds['port'] : 5432;

        $dsn  = $this->buildPgDsn($host, $port, $db, $user, $pass);

        // Capture any PHP warnings emitted by pg_connect.
        set_error_handler(static fn() => true);
        $conn = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        restore_error_handler();

        if ($conn === false) {
            // pg_last_error() gives us the PostgreSQL error message.
            $error = pg_last_error();

            return $this->parsePgError($error, $host, $port, $user, $db);
        }

        pg_close($conn);

        return Result::ok();
    }

    // ---------------------------------------------------------------------------
    // Postgre — createDatabase
    // ---------------------------------------------------------------------------

    private function createPostgre(array $creds): Result
    {
        if (! function_exists('pg_connect')) {
            return Result::fail('The PostgreSQL PHP extension (pgsql) is not loaded on this server.');
        }

        $host = $creds['hostname'] ?? '127.0.0.1';
        $user = $creds['username'] ?? '';
        $pass = $creds['password'] ?? '';
        $db   = $creds['database'] ?? '';
        $port = isset($creds['port']) ? (int) $creds['port'] : 5432;

        // Connect to the default maintenance database.
        $dsn  = $this->buildPgDsn($host, $port, 'postgres', $user, $pass);

        set_error_handler(static fn() => true);
        $conn = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        restore_error_handler();

        if ($conn === false) {
            $error = pg_last_error();
            return $this->parsePgError($error, $host, $port, $user, 'postgres');
        }

        // Sanitise the database name to prevent SQL injection (identifiers cannot
        // be parameterised in PostgreSQL DDL statements).
        $escapedDb = pg_escape_identifier($conn, $db);
        $sql       = "CREATE DATABASE {$escapedDb} ENCODING 'UTF8'";

        $res = @pg_query($conn, $sql);

        if ($res === false) {
            $error = pg_last_error($conn);
            pg_close($conn);

            if (str_contains($error, 'already exists')) {
                return Result::fail("Database '{$db}' already exists.");
            }

            if (str_contains($error, 'permission denied')) {
                return Result::fail("User '{$user}' does not have CREATE DATABASE privileges on {$host}:{$port}.");
            }

            return Result::fail("Failed to create database '{$db}': {$error}");
        }

        pg_close($conn);

        return Result::ok("Database '{$db}' created successfully.");
    }

    /**
     * Builds a libpq connection string from individual components.
     */
    private function buildPgDsn(
        string $host,
        int    $port,
        string $db,
        string $user,
        string $pass,
    ): string {
        // Escape single quotes in credential values.
        $escape = static fn(string $v): string => str_replace(["\\", "'"], ["\\\\", "\\'"], $v);

        return sprintf(
            "host='%s' port='%d' dbname='%s' user='%s' password='%s' connect_timeout=5",
            $escape($host),
            $port,
            $escape($db),
            $escape($user),
            $escape($pass),
        );
    }

    /**
     * Converts a libpq / PostgreSQL error message into a specific Result::fail().
     */
    private function parsePgError(string $error, string $host, int $port, string $user, string $db): Result
    {
        $lower = strtolower($error);

        if (str_contains($lower, 'connection refused') || str_contains($lower, 'could not connect')) {
            return Result::fail("Server unreachable at {$host}:{$port}. (Connection refused)");
        }

        if (str_contains($lower, 'no such host') || str_contains($lower, 'name or service not known')) {
            return Result::fail("Server unreachable at {$host}:{$port}. (Host not found)");
        }

        if (str_contains($lower, 'timeout')) {
            return Result::fail("Server unreachable at {$host}:{$port}. (Connection timed out)");
        }

        if (str_contains($lower, 'password authentication failed') || str_contains($lower, 'authentication failed')) {
            return Result::fail("Authentication failed for user '{$user}' on {$host}:{$port}.");
        }

        if (str_contains($lower, 'role') && str_contains($lower, 'does not exist')) {
            return Result::fail("Authentication failed: PostgreSQL role '{$user}' does not exist on {$host}:{$port}.");
        }

        if (str_contains($lower, 'database') && str_contains($lower, 'does not exist')) {
            return Result::fail("Database '{$db}' does not exist on {$host}:{$port}.");
        }

        if (str_contains($lower, 'permission denied')) {
            return Result::fail("User '{$user}' does not have permission to access database '{$db}' on {$host}:{$port}.");
        }

        return Result::fail("PostgreSQL connection error: {$error}");
    }

    // ---------------------------------------------------------------------------
    // SQLite3 — test
    // ---------------------------------------------------------------------------

    private function testSQLite3(array $creds): Result
    {
        if (! class_exists(SQLite3::class)) {
            return Result::fail('The SQLite3 PHP extension is not loaded on this server.');
        }

        $path = $creds['path'] ?? '';

        if ($path === '') {
            return Result::fail('SQLite3 requires a file path. Provide credentials[\'path\'].');
        }

        $dir = is_file($path) ? dirname($path) : (is_dir($path) ? $path : dirname($path));

        if (! is_dir($dir)) {
            return Result::fail("Directory does not exist for SQLite3 path: {$dir}");
        }

        if (! is_writable($dir)) {
            return Result::fail("Directory is not writable by the web server: {$dir}");
        }

        // Warn if the path is inside the document root (security concern).
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $warning = null;

        if ($docRoot !== '' && str_starts_with(realpath($dir) ?: $dir, realpath($docRoot) ?: $docRoot)) {
            $warning = "Warning: the SQLite3 file at '{$path}' is inside the web document root and may be publicly accessible.";
        }

        try {
            $db = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $db->close();
        } catch (Throwable $e) {
            return Result::fail("Could not open SQLite3 database at '{$path}': " . $e->getMessage());
        }

        return Result::ok($warning);
    }

    // ---------------------------------------------------------------------------
    // SQLite3 — createDatabase
    // ---------------------------------------------------------------------------

    private function createSQLite3(array $creds): Result
    {
        // For SQLite3, opening (or creating) the file IS the "create database" step.
        return $this->testSQLite3($creds);
    }

    // ---------------------------------------------------------------------------
    // SQLSRV — test
    // ---------------------------------------------------------------------------

    private function testSQLSRV(array $creds): Result
    {
        if (! function_exists('sqlsrv_connect')) {
            return Result::fail('The SQL Server PHP extension (sqlsrv) is not loaded on this server.');
        }

        $host = $creds['hostname'] ?? '';
        $user = $creds['username'] ?? '';
        $pass = $creds['password'] ?? '';
        $db   = $creds['database'] ?? '';
        $port = isset($creds['port']) ? (int) $creds['port'] : 1433;

        // The SQLSRV driver accepts "host,port" as the server string.
        $server = $port !== 1433 ? "{$host},{$port}" : $host;

        $connInfo = [
            'Database'             => $db,
            'UID'                  => $user,
            'PWD'                  => $pass,
            'LoginTimeout'         => 5,
            'TrustServerCertificate' => true,
        ];

        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $conn = sqlsrv_connect($server, $connInfo);

        if ($conn === false) {
            return $this->parseSqlsrvError($host, $port, $user, $db);
        }

        sqlsrv_close($conn);

        return Result::ok();
    }

    // ---------------------------------------------------------------------------
    // SQLSRV — createDatabase
    // ---------------------------------------------------------------------------

    private function createSQLSRV(array $creds): Result
    {
        if (! function_exists('sqlsrv_connect')) {
            return Result::fail('The SQL Server PHP extension (sqlsrv) is not loaded on this server.');
        }

        $host = $creds['hostname'] ?? '';
        $user = $creds['username'] ?? '';
        $pass = $creds['password'] ?? '';
        $db   = $creds['database'] ?? '';
        $port = isset($creds['port']) ? (int) $creds['port'] : 1433;

        $server = $port !== 1433 ? "{$host},{$port}" : $host;

        // Connect without specifying a database.
        $connInfo = [
            'UID'                    => $user,
            'PWD'                    => $pass,
            'LoginTimeout'           => 5,
            'TrustServerCertificate' => true,
        ];

        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $conn = sqlsrv_connect($server, $connInfo);

        if ($conn === false) {
            return $this->parseSqlsrvError($host, $port, $user, $db);
        }

        // Bracket-quote the database name to handle reserved words / spaces.
        $escapedDb = str_replace(']', ']]', $db);
        $sql       = "CREATE DATABASE [{$escapedDb}]";

        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt === false) {
            $errors = sqlsrv_errors() ?? [];
            sqlsrv_close($conn);

            $message = $this->formatSqlsrvErrors($errors);

            // SQLSTATE 42000 / error 1801 = database already exists.
            foreach ($errors as $err) {
                if (($err['code'] ?? 0) === 1801) {
                    return Result::fail("Database '{$db}' already exists.");
                }
                if (($err['code'] ?? 0) === 262) {
                    return Result::fail("User '{$user}' does not have CREATE DATABASE privileges on {$host}:{$port}.");
                }
            }

            return Result::fail("Failed to create database '{$db}': {$message}");
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);

        return Result::ok("Database '{$db}' created successfully.");
    }

    /**
     * Converts the sqlsrv_errors() array into a human-readable Result::fail().
     */
    private function parseSqlsrvError(string $host, int $port, string $user, string $db): Result
    {
        $errors = sqlsrv_errors() ?? [];
        $codes  = array_column($errors, 'code');

        // 18456 = Login failed
        if (in_array(18456, $codes, true)) {
            return Result::fail("Authentication failed for user '{$user}' on {$host}:{$port}.");
        }

        // 4060 = Cannot open database
        if (in_array(4060, $codes, true)) {
            return Result::fail("Database '{$db}' does not exist or user '{$user}' cannot access it on {$host}:{$port}.");
        }

        // Connection errors typically carry SQLSTATE 08001
        foreach ($errors as $err) {
            if (($err['SQLSTATE'] ?? '') === '08001') {
                return Result::fail("Server unreachable at {$host}:{$port}. (" . ($err['message'] ?? 'Connection failed') . ")");
            }
        }

        $message = $this->formatSqlsrvErrors($errors);

        return Result::fail("SQL Server connection error for {$host}:{$port}: {$message}");
    }

    /**
     * Collapses the sqlsrv_errors() array into a single string.
     */
    private function formatSqlsrvErrors(array $errors): string
    {
        $parts = [];

        foreach ($errors as $err) {
            $parts[] = sprintf(
                'SQLSTATE %s / %d: %s',
                $err['SQLSTATE'] ?? '?',
                $err['code']     ?? 0,
                $err['message']  ?? '',
            );
        }

        return implode('; ', $parts) ?: 'Unknown error';
    }
}
