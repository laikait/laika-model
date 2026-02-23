<?php

declare(strict_types=1);

namespace Laika\Model\Drivers;

class SqliteDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'sqlite';
    }

    public function buildDsn(array $config): string
    {
        // In-memory database
        if (isset($config['database']) && preg_match('/^[: ]*memory[: ]*/i', $config['database'])) {
            return 'sqlite::memory:';
        }

        $path = $config['database'] ?? $config['path'] ?? null;

        if ($path === null) {
            throw new \InvalidArgumentException(
                "SQLite config must contain a 'database' key with a file path or ':memory:'."
            );
        }

        return "sqlite:{$path}";
    }

    public function getOptions(array $config): array
    {
        // SQLite doesn't support ATTR_EMULATE_PREPARES = false well in all versions
        return array_merge(
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
            $config['options'] ?? []
        );
    }
}
