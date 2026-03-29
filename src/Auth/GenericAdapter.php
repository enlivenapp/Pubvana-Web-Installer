<?php

namespace CI4Installer\Auth;

use CI4Installer\Result;

/**
 * Generic fallback adapter that performs a direct DB INSERT for any auth
 * system that is not explicitly supported.  No CI4 bootstrap is required.
 *
 * Expected $authConfig keys:
 *   table         – target table name
 *   fields        – associative map of column => wizard-field-name
 *                   e.g. ['user_email' => 'email', 'user_pass' => 'password']
 *   hash_method   – password_hash algorithm constant name string
 *                   e.g. 'PASSWORD_BCRYPT' or 'PASSWORD_DEFAULT'
 *   extra_inserts – associative map of column => literal value to always insert
 *                   e.g. ['active' => 1, 'role' => 'admin']
 *   collect       – (optional) list of wizard field names to surface; when
 *                   absent the list is derived from the values in 'fields'
 *
 * Expected $dbCredentials keys:
 *   driver    – 'MySQLi' | 'Postgre' | 'SQLite3' | 'SQLSRV'
 *   hostname  – host / path for SQLite
 *   port      – (optional)
 *   database  – database name / SQLite file path
 *   username  – (not required for SQLite3)
 *   password  – (not required for SQLite3)
 */
class GenericAdapter implements AuthAdapterInterface
{
    public function __construct(
        private readonly array $authConfig,
        private readonly array $dbCredentials,
    ) {}

    // -------------------------------------------------------------------------

    public function canHandle(): bool
    {
        return true; // always usable as a fallback
    }

    public function getFields(): array
    {
        // Prefer an explicit 'collect' list; otherwise derive from field mapping.
        $fieldNames = $this->authConfig['collect']
            ?? array_values($this->authConfig['fields'] ?? []);

        $fields = [];

        foreach ($fieldNames as $name) {
            // Make a reasonable guess at the HTML input type.
            $type = 'text';
            if (stripos($name, 'password') !== false || stripos($name, 'pass') !== false) {
                $type = 'password';
            } elseif (stripos($name, 'email') !== false) {
                $type = 'email';
            }

            $fields[] = [
                'name'     => $name,
                'type'     => $type,
                'label'    => ucwords(str_replace('_', ' ', $name)),
                'required' => true,
            ];
        }

        return $fields;
    }

    public function createAdmin(array $data): Result
    {
        try {
            $connection = $this->connect();
        } catch (\Throwable $e) {
            return Result::fail('DB connection failed: ' . $e->getMessage());
        }

        try {
            $table        = $this->authConfig['table']         ?? 'users';
            $fieldMap     = $this->authConfig['fields']        ?? [];
            $hashMethod   = $this->authConfig['hash_method']   ?? 'PASSWORD_DEFAULT';
            $extraInserts = $this->authConfig['extra_inserts'] ?? [];

            // Resolve the hashing constant.
            $hashAlgo = defined($hashMethod) ? constant($hashMethod) : PASSWORD_DEFAULT;

            // Build column => value pairs from the field mapping.
            $columns = [];
            $values  = [];

            foreach ($fieldMap as $column => $wizardField) {
                $rawValue = $data[$wizardField] ?? '';

                // Hash the password column automatically.
                if (
                    stripos($wizardField, 'password') !== false ||
                    stripos($wizardField, 'pass')     !== false
                ) {
                    $rawValue = password_hash($rawValue, $hashAlgo);
                }

                $columns[] = $column;
                $values[]  = $rawValue;
            }

            // Append extra literal inserts.
            foreach ($extraInserts as $column => $literal) {
                $columns[] = $column;
                $values[]  = $literal;
            }

            if (empty($columns)) {
                return Result::fail('GenericAdapter: no columns defined to insert.');
            }

            $result = $this->executeInsert($connection, $table, $columns, $values);

            $this->closeConnection($connection);

            return $result;
        } catch (\Throwable $e) {
            $this->closeConnection($connection);
            return Result::fail('GenericAdapter createAdmin error: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Database connection helpers
    // -------------------------------------------------------------------------

    /**
     * Open a raw DB connection.  Returns a driver-specific resource/object.
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
                'GenericAdapter: unsupported driver "' . $driver . '".'
            ),
        };
    }

    private function connectMySQLi(
        string $host, int $port, string $db, string $user, string $pass
    ): \mysqli {
        // Suppress connection warnings; check instead.
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
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host, $port, $db
        );

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
        $dsn = sprintf(
            'sqlsrv:Server=%s,%d;Database=%s',
            $host, $port, $db
        );

        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Execute a parameterised INSERT and return a Result.
     *
     * @param \mysqli|\PDO|\SQLite3 $conn
     */
    private function executeInsert(
        mixed  $conn,
        string $table,
        array  $columns,
        array  $values
    ): Result {
        $colList      = implode(', ', array_map(fn($c) => '`' . $c . '`', $columns));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $sql          = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table, $colList, $placeholders
        );

        if ($conn instanceof \mysqli) {
            return $this->insertMySQLi($conn, $sql, $values);
        }

        if ($conn instanceof \SQLite3) {
            return $this->insertSQLite3($conn, $table, $columns, $values);
        }

        // PDO covers both Postgre and SQLSRV.
        if ($conn instanceof \PDO) {
            return $this->insertPDO($conn, $sql, $values, $table, $columns);
        }

        return Result::fail('GenericAdapter: unrecognised connection type.');
    }

    private function insertMySQLi(\mysqli $conn, string $sql, array $values): Result
    {
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            return Result::fail('MySQLi prepare failed: ' . $conn->error);
        }

        // Build a type string: treat everything as string ('s') for safety.
        $types = str_repeat('s', count($values));
        $stmt->bind_param($types, ...$values);

        if (! $stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            return Result::fail('MySQLi execute failed: ' . $error);
        }

        $insertId = (int) $conn->insert_id;
        $stmt->close();

        return Result::ok(['user_id' => $insertId]);
    }

    private function insertSQLite3(
        \SQLite3 $conn,
        string   $table,
        array    $columns,
        array    $values
    ): Result {
        // SQLite3 uses named or positional parameters via prepare.
        $colList      = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $sql          = sprintf(
            'INSERT INTO "%s" (%s) VALUES (%s)',
            $table, $colList, $placeholders
        );

        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            return Result::fail('SQLite3 prepare failed: ' . $conn->lastErrorMsg());
        }

        foreach ($values as $i => $value) {
            $type = is_int($value) ? \SQLITE3_INTEGER : \SQLITE3_TEXT;
            $stmt->bindValue($i + 1, $value, $type);
        }

        $result = $stmt->execute();

        if ($result === false) {
            return Result::fail('SQLite3 execute failed: ' . $conn->lastErrorMsg());
        }

        $insertId = $conn->lastInsertRowID();
        $stmt->close();

        return Result::ok(['user_id' => $insertId]);
    }

    private function insertPDO(
        \PDO   $conn,
        string $sql,
        array  $values,
        string $table,
        array  $columns
    ): Result {
        // Re-build SQL without backticks for non-MySQL drivers.
        $driver = $conn->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver !== 'mysql') {
            $colList      = implode(', ', $columns);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $sql          = sprintf(
                'INSERT INTO "%s" (%s) VALUES (%s)',
                $table, $colList, $placeholders
            );
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($values);

        $insertId = (int) $conn->lastInsertId();

        return Result::ok(['user_id' => $insertId]);
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
        // PDO closes when the object goes out of scope; nothing explicit needed.
    }
}
