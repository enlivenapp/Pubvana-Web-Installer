<?php

namespace CI4Installer\Auth;

use CI4Installer\Result;

/**
 * Auth adapter for CodeIgniter Shield.
 *
 * Uses direct SQL inserts against the Shield table schema rather than
 * bootstrapping CI4 (which requires is_cli() and fails under a web request).
 *
 * Expected $dbCredentials keys:
 *   driver    – 'MySQLi' | 'Postgre' | 'SQLite3' | 'SQLSRV'
 *   hostname  – host / path for SQLite
 *   port      – (optional)
 *   database  – database name / SQLite file path
 *   username  – (not required for SQLite3)
 *   password  – (not required for SQLite3)
 *
 * @see https://shield.codeigniter.com
 */
class ShieldAdapter implements AuthAdapterInterface
{
    public function __construct(
        private readonly string $appRoot,
        private readonly string $group = 'superadmin',
        private readonly array  $dbCredentials = [],
    ) {}

    // -------------------------------------------------------------------------

    public function canHandle(): bool
    {
        try {
            $conn = $this->connect();
        } catch (\Throwable $e) {
            return false;
        }

        try {
            $hasUsers      = $this->tableExists($conn, 'users');
            $hasIdentities = $this->tableExists($conn, 'auth_identities');
            $this->closeConnection($conn);

            return $hasUsers && $hasIdentities;
        } catch (\Throwable $e) {
            $this->closeConnection($conn);
            return false;
        }
    }

    public function getFields(): array
    {
        return [
            [
                'name'     => 'username',
                'type'     => 'text',
                'label'    => 'Username',
                'required' => true,
            ],
            [
                'name'     => 'email',
                'type'     => 'email',
                'label'    => 'Email',
                'required' => true,
            ],
            [
                'name'     => 'password',
                'type'     => 'password',
                'label'    => 'Password',
                'required' => true,
            ],
        ];
    }

    public function createAdmin(array $data): Result
    {
        try {
            $conn = $this->connect();
        } catch (\Throwable $e) {
            return Result::fail('DB connection failed: ' . $e->getMessage());
        }

        try {
            $now      = date('Y-m-d H:i:s');
            $username = $data['username'] ?? '';
            $email    = $data['email']    ?? '';
            $password = $data['password'] ?? '';

            // 1. Insert into users table.
            $userId = $this->insertAndGetId(
                $conn,
                'users',
                ['username', 'active', 'created_at', 'updated_at'],
                [$username, 1, $now, $now],
            );

            // 2. Insert email/password identity.
            $this->insertRow(
                $conn,
                'auth_identities',
                ['user_id', 'type', 'secret', 'secret2', 'created_at', 'updated_at'],
                [
                    $userId,
                    'email_password',
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $now,
                    $now,
                ],
            );

            // 3. Assign group.
            $this->insertRow(
                $conn,
                'auth_groups_users',
                ['user_id', '`group`', 'created_at'],
                [$userId, $this->group, $now],
            );

            $this->closeConnection($conn);

            return Result::ok(['user_id' => $userId]);
        } catch (\Throwable $e) {
            $this->closeConnection($conn);
            return Result::fail('Shield createAdmin error: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Database connection helpers
    // -------------------------------------------------------------------------

    /**
     * Open a raw DB connection.
     *
     * @return \mysqli|\PDO|\SQLite3
     * @throws \RuntimeException on failure
     */
    private function connect(): mixed
    {
        $driver   = strtolower($this->dbCredentials['driver'] ?? 'mysqli');
        $hostname = $this->dbCredentials['hostname'] ?? 'localhost';
        $port     = isset($this->dbCredentials['port'])
            ? (int) $this->dbCredentials['port']
            : null;
        $database = $this->dbCredentials['database'] ?? '';
        $username = $this->dbCredentials['username'] ?? '';
        $password = $this->dbCredentials['password'] ?? '';

        return match ($driver) {
            'mysqli', 'mysql' => $this->connectMySQLi(
                $hostname, $port ?? 3306, $database, $username, $password
            ),
            'postgre', 'pgsql', 'postgres', 'postgresql' => $this->connectPostgre(
                $hostname, $port ?? 5432, $database, $username, $password
            ),
            'sqlite3', 'sqlite' => $this->connectSQLite3($database),
            'sqlsrv', 'mssql'   => $this->connectSQLSRV(
                $hostname, $port ?? 1433, $database, $username, $password
            ),
            default => throw new \RuntimeException(
                'ShieldAdapter: unsupported driver "' . $driver . '".'
            ),
        };
    }

    private function connectMySQLi(
        string $host, int $port, string $db, string $user, string $pass
    ): \mysqli {
        $conn = @new \mysqli($host, $user, $pass, $db, $port);

        if ($conn->connect_errno) {
            throw new \RuntimeException(
                'MySQLi connect error: ' . $conn->connect_error
            );
        }

        $conn->set_charset('utf8mb4');

        return $conn;
    }

    private function connectPostgre(
        string $host, int $port, string $db, string $user, string $pass
    ): \PDO {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $db);

        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function connectSQLite3(string $path): \SQLite3
    {
        if (! file_exists($path)) {
            throw new \RuntimeException(
                'SQLite3: database file not found at "' . $path . '".'
            );
        }

        return new \SQLite3($path);
    }

    private function connectSQLSRV(
        string $host, int $port, string $db, string $user, string $pass
    ): \PDO {
        $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s', $host, $port, $db);

        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether a table exists in the connected database.
     */
    private function tableExists(mixed $conn, string $table): bool
    {
        if ($conn instanceof \mysqli) {
            $result = $conn->query(
                sprintf("SHOW TABLES LIKE '%s'", $conn->real_escape_string($table))
            );
            return $result && $result->num_rows > 0;
        }

        if ($conn instanceof \SQLite3) {
            $stmt = $conn->prepare(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=:t"
            );
            $stmt->bindValue(':t', $table, \SQLITE3_TEXT);
            $result = $stmt->execute();
            $row    = $result->fetchArray();
            $stmt->close();
            return $row !== false;
        }

        // PDO (Postgre / SQLSRV)
        if ($conn instanceof \PDO) {
            $driver = $conn->getAttribute(\PDO::ATTR_DRIVER_NAME);

            if ($driver === 'pgsql') {
                $stmt = $conn->prepare(
                    "SELECT 1 FROM information_schema.tables WHERE table_name = ?"
                );
            } else {
                // sqlsrv
                $stmt = $conn->prepare(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?"
                );
            }

            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        }

        return false;
    }

    /**
     * Insert a row and return the auto-increment ID.
     *
     * @return int The insert ID.
     * @throws \RuntimeException on failure.
     */
    private function insertAndGetId(
        mixed  $conn,
        string $table,
        array  $columns,
        array  $values,
    ): int {
        $this->insertRow($conn, $table, $columns, $values);

        if ($conn instanceof \mysqli) {
            return (int) $conn->insert_id;
        }

        if ($conn instanceof \SQLite3) {
            return (int) $conn->lastInsertRowID();
        }

        if ($conn instanceof \PDO) {
            return (int) $conn->lastInsertId();
        }

        throw new \RuntimeException('ShieldAdapter: unable to retrieve insert ID.');
    }

    /**
     * Execute a parameterised INSERT.
     *
     * Column names that are already back-tick quoted (e.g. '`group`') are
     * passed through as-is; all others are quoted for the target driver.
     *
     * @throws \RuntimeException on failure.
     */
    private function insertRow(
        mixed  $conn,
        string $table,
        array  $columns,
        array  $values,
    ): void {
        // Determine quoting style.
        $useBackticks = ($conn instanceof \mysqli);
        $q            = $useBackticks ? '`' : '"';

        $quotedCols = array_map(function (string $col) use ($q): string {
            // Strip existing quotes and re-quote for the target driver.
            $col = trim($col, '`"');
            return $q . $col . $q;
        }, $columns);

        $colList      = implode(', ', $quotedCols);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $tableQuoted  = $q . $table . $q;
        $sql          = sprintf('INSERT INTO %s (%s) VALUES (%s)', $tableQuoted, $colList, $placeholders);

        if ($conn instanceof \mysqli) {
            $stmt = $conn->prepare($sql);

            if ($stmt === false) {
                throw new \RuntimeException('MySQLi prepare failed: ' . $conn->error);
            }

            $types = str_repeat('s', count($values));
            $stmt->bind_param($types, ...$values);

            if (! $stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new \RuntimeException('MySQLi execute failed: ' . $error);
            }

            $stmt->close();
            return;
        }

        if ($conn instanceof \SQLite3) {
            $stmt = $conn->prepare($sql);

            if ($stmt === false) {
                throw new \RuntimeException('SQLite3 prepare failed: ' . $conn->lastErrorMsg());
            }

            foreach ($values as $i => $value) {
                $type = is_int($value) ? \SQLITE3_INTEGER : \SQLITE3_TEXT;
                $stmt->bindValue($i + 1, $value, $type);
            }

            $result = $stmt->execute();

            if ($result === false) {
                throw new \RuntimeException('SQLite3 execute failed: ' . $conn->lastErrorMsg());
            }

            $stmt->close();
            return;
        }

        // PDO (Postgre / SQLSRV)
        if ($conn instanceof \PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute($values);
            return;
        }

        throw new \RuntimeException('ShieldAdapter: unrecognised connection type.');
    }

    // -------------------------------------------------------------------------
    // Cleanup
    // -------------------------------------------------------------------------

    private function closeConnection(mixed $conn): void
    {
        if ($conn instanceof \mysqli) {
            $conn->close();
        } elseif ($conn instanceof \SQLite3) {
            $conn->close();
        }
        // PDO closes when the object goes out of scope.
    }
}
