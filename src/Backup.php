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

    public function __construct(array $config, string $connection = 'default')
    {
        $this->connection = $connection;
        $this->pdo        = Connection::get($connection);
        $this->driver     = Connection::driver($connection);
        $this->config     = $config;
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
        return match ($this->driver) {
            'mysql', 'mariadb'  => $this->mysqlBackup($path),
            'pgsql'             => $this->pgsqlBackup($path),
            'sqlite', 'sqlite3' => $this->sqliteBackup($path),
            'sqlsrv'            => $this->sqlsrvBackup($path),
            'firebird'          => $this->firebirdBackup($path),
            'oci', 'oracle'     => $this->ociBackup($path),
            default             => throw new BackupException("Backup Not Supported For Driver [{$this->driver}]."),
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
            'mysql', 'mariadb'  => $this->mysqlRestore($path),
            'pgsql'             => $this->pgsqlRestore($path),
            'sqlite', 'sqlite3' => $this->sqliteRestore($path),
            'sqlsrv'            => $this->sqlsrvRestore($path),
            'firebird'          => $this->firebirdRestore($path),
            'oci', 'oracle'     => $this->ociRestore($path),
            default             => throw new BackupException("Restore Not Supported For Driver [{$this->driver}]."),
        };
    }

    ####################################################################
    /*------------------------- MYSQL / MARIADB -----------------------*/
    ####################################################################
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

    ####################################################################
    /*------------------------- POSTGRESQL ----------------------------*/
    ####################################################################

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

    ####################################################################
    /*------------------------- SQLITE --------------------------------*/
    ####################################################################

    protected function sqliteBackup(string $path): string
    {
        $dbFile = $this->config['database'] ?? null;

        if (!$dbFile || !file_exists($dbFile)) {
            throw new BackupException("SQLite Database File Not Found.");
        }

        if (!copy($dbFile, $path)) {
            throw new BackupException("SQLite Backup Failed.");
        }

        return $path;
    }

    protected function sqliteRestore(string $path): void
    {
        $dbFile = $this->config['database'] ?? null;

        if (!$dbFile) {
            throw new BackupException("SQLite Database Path Not Configured.");
        }

        Connection::close($this->connection);

        if (!copy($path, $dbFile)) {
            throw new BackupException("SQLite Restore Failed.");
        }
    }

    ####################################################################
    /*------------------------- SQL SERVER -----------------------------*/
    ####################################################################

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

    ####################################################################
    /*------------------------- FIREBIRD --------------------------------*/
    ####################################################################

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

    ####################################################################
    /*------------------------- ORACLE (OCI) ----------------------------*/
    ####################################################################

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

    ####################################################################
    /*------------------------- INTERNAL API -------------------------*/
    ####################################################################

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
