<?php

declare(strict_types=1);

namespace Laika\Model\Drivers;

class MySqlDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'mysql';
    }

    public function buildDsn(array $config): string
    {
        $host    = $this->getHost($config);
        $port    = $this->getPort($config, 3306);
        $dbname  = $config['database'] ?? '';
        $charset = $this->getCharset($config);

        if (isset($config['unix_socket'])) {
            // Unix sockets are a local IPC mechanism — the socket file lives on
            // the same machine as PHP. They cannot reach a remote host.
            $localHosts = ['localhost', '127.0.0.1', '::1'];
            if (!in_array($host, $localHosts, true)) {
                throw new \InvalidArgumentException(
                    "unix_socket can only be used with a local host (localhost / 127.0.0.1 / ::1). " .
                    "Got host [{$host}]. Use host/port for remote connections."
                );
            }

            return "mysql:unix_socket={$config['unix_socket']};dbname={$dbname};charset={$charset}";
        }

        return "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    }

    public function getOptions(array $config): array
    {
        return array_merge(parent::getOptions($config), [
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$this->getCharset($config)}'",
        ]);
    }
}
