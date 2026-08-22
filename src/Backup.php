<?php
/**
 * Laika Database Model
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Model;

use Laika\Model\Exceptions\BackupException;

class Backup
{
    /** @var \PDO PDO Connection */
    protected \PDO $pdo;

    /** @var string SQL Driver */
    protected string $driver;

    /** @var array Connection Config */
    protected array $config;

    /** @var string Connection Name */
    protected string $connection;

    /**
     * @param ?string $connection Connection name (default: Connection's default).
     */
    public function __construct(?string $connection = null)
    {
        $this->connection = $connection ?? Connection::getDefault();
        $this->pdo        = Connection::get($this->connection);
        $this->driver     = Connection::driver($this->connection);
        $this->config     = Connection::config($this->connection);
    }

    ####################################################################
    /*------------------------- EXTERNAL API -------------------------*/
    ####################################################################

    /**
     * Create Backup File
     * @param string $path Full file path to save backup (e.g. /backups/db_2026.sql)
     * @return string Path of created backup file
     * @throws BackupException
     */
    public function create(string $path): string
    {
        // $this->driver is canonical (see Connection::driver()), so aliases
        // such as 'mariadb' or 'oracle' never reach this match.
        return match ($this->driver) {
            'mysql'     =>  $this->mysqlBackup($path),
            'mariadb'   =>  $this->mysqlBackup($path),
            'pgsql'     =>  $this->pgsqlBackup($path),
            'sqlite'    =>  $this->sqliteBackup($path),
            'sqlite3'   =>  $this->sqliteBackup($path),
            'sqlsrv'    =>  $this->sqlsrvBackup($path),
            'firebird'  =>  $this->firebirdBackup($path),
            'oci'       =>  $this->ociBackup($path),
            default     =>  throw new BackupException("Backup Not Supported For Driver [{$this->driver}]."),
        };
    }

    /**
     * Export The Database As Plain SQL Text
     *
     * Deliberately separate from create(). That method produces whatever the
     * engine's own restore tooling wants — a binary .db copy for SQLite, a .bak
     * for SQL Server, a gbak archive for Firebird — which is right for a
     * same-engine round trip but unreadable to anything else. dump() instead
     * guarantees CREATE TABLE / INSERT text, which is what Converter parses.
     *
     * @param string $path Full file path to write the SQL to.
     * @return string Path of the created file.
     * @throws BackupException On an unsupported driver or a failing tool.
     */
    public function dump(string $path): string
    {
        return match ($this->driver) {
            'mysql'     =>  $this->mysqlDump($path),
            'mariadb'   =>  $this->mysqlDump($path),
            'pgsql'     =>  $this->pgsqlDump($path),
            'sqlite'    =>  $this->sqliteDump($path),
            'sqlite3'   =>  $this->sqliteDump($path),
            'sqlsrv'    =>  $this->sqlsrvDump($path),
            default     =>  throw new BackupException(
                "SQL Dump Not Supported For Driver [{$this->driver}]. "
                . "Supported sources are mysql, mariadb, pgsql, sqlite and sqlsrv — "
                . "the dialects the Converter has a grammar for."
            ),
        };
    }

    /**
     * Restore From Backup File
     * @param string $path Backup file path
     * @return void
     * @throws BackupException
     */
    public function restore(string $path): void
    {
        if (!file_exists($path)) {
            throw new BackupException("Backup File Not Found [{$path}].");
        }

        match ($this->driver) {
            'mysql'    => $this->mysqlRestore($path),
            'mariadb'  => $this->mysqlRestore($path),
            'pgsql'    => $this->pgsqlRestore($path),
            'sqlite'   => $this->sqliteRestore($path),
            'sqlite3'  => $this->sqliteRestore($path),
            'sqlsrv'   => $this->sqlsrvRestore($path),
            'firebird' => $this->firebirdRestore($path),
            'oci'      => $this->ociRestore($path),
            'oracle'   => $this->ociRestore($path),
            default    => throw new BackupException("Restore Not Supported For Driver [{$this->driver}]."),
        };
    }

    ####################################################################
    /*------------------------- INTERNAL API -------------------------*/
    ####################################################################

    /*------------------------- MYSQL / MARIADB -----------------------*/
    protected function mysqlBackup(string $path): string
    {
        $this->requireTool('mysqldump', 'MySQL Client Tools (mysqldump)', 'https://dev.mysql.com/downloads/mysql/');

        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s 2>&1',
            escapeshellarg($this->config['host'] ?? '127.0.0.1'),
            escapeshellarg((string)($this->config['port'] ?? 3306)),
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['password'] ?? ''),
            escapeshellarg($this->config['database']),
            escapeshellarg($path)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new BackupException("MySQL Backup Failed: " . implode("\n", $output));
        }

        return $path;
    }

    /**
     * mysqldump already writes plain SQL, so the dump is the backup. Kept as a
     * distinct method so the two contracts can diverge later without surprise.
     */
    protected function mysqlDump(string $path): string
    {
        return $this->mysqlBackup($path);
    }

    protected function mysqlRestore(string $path): void
    {
        $this->requireTool('mysql', 'MySQL Client Tools (mysql)', 'https://dev.mysql.com/downloads/mysql/');

        $cmd = sprintf(
            'mysql --host=%s --port=%s --user=%s --password=%s %s < %s 2>&1',
            escapeshellarg($this->config['host'] ?? '127.0.0.1'),
            escapeshellarg((string)($this->config['port'] ?? 3306)),
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['password'] ?? ''),
            escapeshellarg($this->config['database']),
            escapeshellarg($path)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new BackupException("MySQL Restore Failed: " . implode("\n", $output));
        }
    }

    /*------------------------- POSTGRESQL ----------------------------*/
    protected function pgsqlBackup(string $path): string
    {
        $this->requireTool('pg_dump', 'PostgreSQL Client Tools (pg_dump)', 'https://www.postgresql.org/download/');

        putenv('PGPASSWORD=' . ($this->config['password'] ?? ''));

        $cmd = sprintf(
            'pg_dump --host=%s --port=%s --username=%s %s > %s 2>&1',
            escapeshellarg($this->config['host'] ?? '127.0.0.1'),
            escapeshellarg((string)($this->config['port'] ?? 5432)),
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['database']),
            escapeshellarg($path)
        );

        exec($cmd, $output, $code);
        putenv('PGPASSWORD');

        if ($code !== 0) {
            throw new BackupException("PostgreSQL Backup Failed: " . implode("\n", $output));
        }

        return $path;
    }

    /**
     * pg_dump defaults to COPY ... FROM stdin for table data, which is a bulk
     * stream rather than a statement and which the Converter cannot parse at
     * all. --inserts is therefore required, not a preference. Ownership and
     * grants are dropped too: they name roles that do not exist on the target.
     */
    protected function pgsqlDump(string $path): string
    {
        $this->requireTool('pg_dump', 'PostgreSQL Client Tools (pg_dump)', 'https://www.postgresql.org/download/');

        putenv('PGPASSWORD=' . ($this->config['password'] ?? ''));

        $cmd = sprintf(
            'pg_dump --inserts --no-owner --no-privileges --host=%s --port=%s --username=%s %s > %s 2>&1',
            escapeshellarg($this->config['host'] ?? '127.0.0.1'),
            escapeshellarg((string)($this->config['port'] ?? 5432)),
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['database']),
            escapeshellarg($path)
        );

        exec($cmd, $output, $code);
        putenv('PGPASSWORD');

        if ($code !== 0) {
            throw new BackupException("PostgreSQL Dump Failed: " . implode("\n", $output));
        }

        return $path;
    }

    protected function pgsqlRestore(string $path): void
    {
        $this->requireTool('psql', 'PostgreSQL Client Tools (psql)', 'https://www.postgresql.org/download/');

        putenv('PGPASSWORD=' . ($this->config['password'] ?? ''));

        $cmd = sprintf(
            'psql --host=%s --port=%s --username=%s %s < %s 2>&1',
            escapeshellarg($this->config['host'] ?? '127.0.0.1'),
            escapeshellarg((string)($this->config['port'] ?? 5432)),
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['database']),
            escapeshellarg($path)
        );

        exec($cmd, $output, $code);
        putenv('PGPASSWORD');

        if ($code !== 0) {
            throw new BackupException("PostgreSQL Restore Failed: " . implode("\n", $output));
        }
    }

    /*------------------------- SQLITE --------------------------------*/
    protected function sqliteBackup(string $path): string
    {
        $dbFile = $this->config['database'] ?? $this->config['path'] ?? NULL;

        if (!$dbFile || !file_exists($dbFile)) {
            throw new BackupException("SQLite Database File Not Found.");
        }

        if (!copy($dbFile, $path)) {
            throw new BackupException("SQLite Backup Failed.");
        }

        return $path;
    }

    /**
     * Built from sqlite_master over the existing connection rather than by
     * shelling out to `sqlite3 .dump`.
     *
     * The CLI was the obvious choice and is the wrong one: recent versions
     * wrap any string holding a control character in a unistr() call, with the
     * character written as a unicode escape. No other engine has that function,
     * and neither does the SQLite that PDO bundles, so a single newline in the
     * data failed the entire migration. Reading sqlite_master gives the same
     * original DDL with none of that, and PDO::quote() escapes the values.
     *
     * sqliteBackup() still copies the binary file, which remains right for a
     * same-engine restore.
     */
    protected function sqliteDump(string $path): string
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new BackupException("Cannot open [{$path}] for writing.");
        }

        try {
            // sqlite_master holds the verbatim CREATE text. Internal tables use
            // the reserved sqlite_ prefix and are excluded: the engine rebuilds
            // them itself, and no other engine has them at all.
            $tables = $this->pdo->query(
                "SELECT name, sql FROM sqlite_master
                 WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL
                 ORDER BY name"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($tables as $table) {
                // Matches what mysqldump emits, so re-running a migration
                // replaces the target rather than failing on a duplicate.
                fwrite($handle, 'DROP TABLE IF EXISTS ' . $this->identifier($table['name']) . ";\n");
                fwrite($handle, rtrim((string) $table['sql'], "; \n") . ";\n");

                $this->dumpRows($handle, (string) $table['name']);
            }

            // Indexes come after the data: building them once at the end is
            // cheaper than maintaining them across every INSERT.
            $indexes = $this->pdo->query(
                "SELECT sql FROM sqlite_master
                 WHERE type = 'index' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL
                 ORDER BY name"
            )->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($indexes as $sql) {
                fwrite($handle, rtrim((string) $sql, "; \n") . ";\n");
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }

    protected function sqliteRestore(string $path): void
    {
        $dbFile = $this->config['database'] ?? $this->config['path'] ?? NULL;

        if (!$dbFile) {
            throw new BackupException("SQLite Database Path Not Configured.");
        }

        Connection::close($this->connection);

        if (!copy($path, $dbFile)) {
            throw new BackupException("SQLite Restore Failed.");
        }
    }

    /*------------------------ SQL SERVER ----------------------------*/
    protected function sqlsrvBackup(string $path): string
    {
        $this->requireTool(
            'sqlcmd',
            "Microsoft Command Line Utilities for SQL Server (sqlcmd)",
            'https://learn.microsoft.com/en-us/sql/tools/sqlcmd/sqlcmd-utility'
        );

        $database = $this->config['database'];

        $sql = sprintf(
            "BACKUP DATABASE [%s] TO DISK = N'%s' WITH INIT",
            $database,
            addslashes($path)
        );

        $cmd = sprintf(
            'sqlcmd -S %s -U %s -P %s -Q %s 2>&1',
            escapeshellarg($this->config['host'] ?? 'localhost'),
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['password'] ?? ''),
            escapeshellarg($sql)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new BackupException("SQL Server Backup Failed: " . implode("\n", $output));
        }

        return $path;
    }

    /**
     * SQL Server has no mysqldump equivalent: BACKUP DATABASE writes a binary
     * .bak, sqlcmd cannot script schema and data together, and bcp does data
     * only. So this path reconstructs the SQL from information_schema over the
     * existing PDO connection — no external tool required.
     *
     * The DDL is emitted in T-SQL because the Converter will read it back as
     * `sqlsrv` source dialect.
     */
    protected function sqlsrvDump(string $path): string
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new BackupException("Cannot open [{$path}] for writing.");
        }

        try {
            foreach ($this->introspectTables() as $table) {
                fwrite($handle, $this->sqlsrvCreateTable($table) . "\n");
                $this->dumpRows($handle, $table);
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }

    /** Base tables only — views cannot be recreated from column metadata. */
    protected function introspectTables(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    protected function sqlsrvCreateTable(string $table): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE, COLUMN_DEFAULT
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([$table]);

        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($columns)) {
            throw new BackupException("Table [{$table}] Reported No Columns.");
        }

        $lines = [];

        foreach ($columns as $column) {
            $line = '  ' . $this->identifier((string) $column['COLUMN_NAME'])
                . ' ' . $this->sqlsrvType($column)
                . ($column['IS_NULLABLE'] === 'NO' ? ' NOT NULL' : ' NULL');

            // SQL Server stores defaults already parenthesised, e.g. ((0)).
            if ($column['COLUMN_DEFAULT'] !== null) {
                $line .= ' DEFAULT ' . $column['COLUMN_DEFAULT'];
            }

            $lines[] = $line;
        }

        if ($primary = $this->sqlsrvPrimaryKey($table)) {
            $lines[] = '  PRIMARY KEY (' . implode(', ', array_map([$this, 'identifier'], $primary)) . ')';
        }

        return "CREATE TABLE {$this->identifier($table)} (\n" . implode(",\n", $lines) . "\n);";
    }

    protected function sqlsrvPrimaryKey(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT k.COLUMN_NAME
             FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS c
             JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
               ON k.CONSTRAINT_NAME = c.CONSTRAINT_NAME
              AND k.TABLE_NAME      = c.TABLE_NAME
             WHERE c.TABLE_NAME = ? AND c.CONSTRAINT_TYPE = 'PRIMARY KEY'
             ORDER BY k.ORDINAL_POSITION"
        );
        $stmt->execute([$table]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @param array<string,mixed> $column A row from INFORMATION_SCHEMA.COLUMNS. */
    protected function sqlsrvType(array $column): string
    {
        $type = strtolower((string) $column['DATA_TYPE']);

        if (in_array($type, ['varchar', 'nvarchar', 'char', 'nchar', 'binary', 'varbinary'], true)) {
            $length = (int) $column['CHARACTER_MAXIMUM_LENGTH'];

            // -1 is how information_schema reports the (MAX) variants.
            return $length === -1 ? "{$type}(MAX)" : "{$type}({$length})";
        }

        if (in_array($type, ['decimal', 'numeric'], true)) {
            return sprintf(
                '%s(%d,%d)',
                $type,
                (int) $column['NUMERIC_PRECISION'],
                (int) $column['NUMERIC_SCALE']
            );
        }

        return $type;
    }

    protected function sqlsrvRestore(string $path): void
    {
        $this->requireTool(
            'sqlcmd',
            "Microsoft Command Line Utilities for SQL Server (sqlcmd)",
            'https://learn.microsoft.com/en-us/sql/tools/sqlcmd/sqlcmd-utility'
        );

        $database = $this->config['database'];

        $sql = sprintf(
            "RESTORE DATABASE [%s] FROM DISK = N'%s' WITH REPLACE",
            $database,
            addslashes($path)
        );

        $cmd = sprintf(
            'sqlcmd -S %s -U %s -P %s -Q %s 2>&1',
            escapeshellarg($this->config['host'] ?? 'localhost'),
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['password'] ?? ''),
            escapeshellarg($sql)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new BackupException("SQL Server Restore Failed: " . implode("\n", $output));
        }
    }

    /*------------------------ FIREBIRD -------------------------------*/
    protected function firebirdBackup(string $path): string
    {
        $this->requireTool('gbak', 'Firebird gbak Utility', 'https://firebirdsql.org/en/firebird-3-0-downloads/');

        $database = $this->config['database']; // full path to .fdb

        $cmd = sprintf(
            'gbak -b -user %s -password %s %s %s 2>&1',
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['password'] ?? ''),
            escapeshellarg($database),
            escapeshellarg($path)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new BackupException("Firebird Backup Failed: " . implode("\n", $output));
        }

        return $path;
    }

    protected function firebirdRestore(string $path): void
    {
        $this->requireTool('gbak', 'Firebird gbak Utility', 'https://firebirdsql.org/en/firebird-3-0-downloads/');

        $database = $this->config['database'];

        $cmd = sprintf(
            'gbak -c -user %s -password %s %s %s 2>&1',
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['password'] ?? ''),
            escapeshellarg($path),
            escapeshellarg($database)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new BackupException("Firebird Restore Failed: " . implode("\n", $output));
        }
    }

    /*------------------------ ORACLE (OCI) ---------------------------*/
    protected function ociBackup(string $path): string
    {
        $this->requireTool('expdp', 'Oracle Data Pump Export (expdp)', 'https://www.oracle.com/database/technologies/instant-client.html');

        $dir = dirname($path);
        $file = basename($path);

        $cmd = sprintf(
            'expdp %s/%s@%s directory=%s dumpfile=%s logfile=%s.log 2>&1',
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['password'] ?? ''),
            escapeshellarg($this->config['tns'] ?? ($this->config['host'] ?? 'localhost')),
            escapeshellarg($this->config['directory'] ?? $dir),
            escapeshellarg($file),
            escapeshellarg($file)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new BackupException("Oracle Backup Failed: " . implode("\n", $output));
        }

        return $path;
    }

    protected function ociRestore(string $path): void
    {
        $this->requireTool('impdp', 'Oracle Data Pump Import (impdp)', 'https://www.oracle.com/database/technologies/instant-client.html');

        $dir = dirname($path);
        $file = basename($path);

        $cmd = sprintf(
            'impdp %s/%s@%s directory=%s dumpfile=%s logfile=%s_restore.log 2>&1',
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['password'] ?? ''),
            escapeshellarg($this->config['tns'] ?? ($this->config['host'] ?? 'localhost')),
            escapeshellarg($this->config['directory'] ?? $dir),
            escapeshellarg($file),
            escapeshellarg($file)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new BackupException("Oracle Restore Failed: " . implode("\n", $output));
        }
    }

    /**
     * Write every row of a table as INSERT statements.
     *
     * Streams through Model::cursor() so the row set is never materialised —
     * a table larger than memory dumps fine. A bare Model declares no casts,
     * so values arrive exactly as the driver returned them.
     *
     * @param resource $handle Open write handle.
     */
    protected function dumpRows($handle, string $table): void
    {
        $target = $this->identifier($table);
        $batch  = [];

        foreach ((new Model($this->connection))->table($table)->cursor() as $row) {
            // Rows are arrays or stdClass depending on the connection's fetch
            // mode; the cast normalises both without reordering the columns.
            $batch[] = '(' . implode(', ', array_map([$this, 'literal'], array_values((array) $row))) . ')';

            if (count($batch) >= 200) {
                fwrite($handle, "INSERT INTO {$target} VALUES " . implode(', ', $batch) . ";\n");
                $batch = [];
            }
        }

        if (!empty($batch)) {
            fwrite($handle, "INSERT INTO {$target} VALUES " . implode(', ', $batch) . ";\n");
        }
    }

    /**
     * Render one value as a source-dialect SQL literal.
     *
     * Quoting goes through the source connection's PDO so the escaping matches
     * the dialect the Converter is about to parse.
     */
    protected function literal(mixed $value): string
    {
        return match (true) {
            $value === null              => 'NULL',
            is_bool($value)              => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default                      => $this->pdo->quote((string) $value),
        };
    }

    /**
     * Quote an identifier for the *source* dialect.
     *
     * dumpRows() is shared by every introspection-based dump, so the quoting
     * has to follow the driver rather than being hardcoded. Mirrors
     * Model::wrapIdent().
     */
    protected function identifier(string $name): string
    {
        return match ($this->driver) {
            'mysql', 'mariadb' => '`' . str_replace('`', '``', $name) . '`',
            'sqlsrv'           => '[' . str_replace(']', ']]', $name) . ']',
            default            => '"' . str_replace('"', '""', $name) . '"',
        };
    }

    /**
     * Check If A CLI Tool Is Available On The System
     * @param string $binary Binary name, e.g. 'sqlcmd', 'gbak', 'mysqldump'
     * @return bool
     */
    protected function toolExists(string $binary): bool
    {
        $checkCmd = stripos(PHP_OS, 'WIN') === 0
            ? "where {$binary}"
            : "command -v {$binary}";

        exec($checkCmd . ' 2>&1', $output, $code);
        return $code === 0;
    }

    /**
     * Throw a Clear Error If a Required CLI Tool Is Missing
     * @param string $binary Binary name to check
     * @param string $label Human-readable tool name for the error message
     * @param string $url Download / install reference link
     * @return void
     * @throws BackupException
     */
    protected function requireTool(string $binary, string $label, string $url): void
    {
        if ($this->toolExists($binary)) {
            return;
        }

        throw new BackupException(
            "[{$binary}] Not Found. Please Install {$label}.\n" .
            "Download / Install Guide: {$url}\n" .
            "Make Sure It Is Added To Your System PATH."
        );
    }
}
