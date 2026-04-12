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

namespace Laika\Model\Drivers;

abstract class AbstractDriver implements DriverInterface
{
    protected function getHost(array $config): string
    {
        return $config['host'] ?? '127.0.0.1';
    }

    protected function getPort(array $config, int $default): int
    {
        return (int)($config['port'] ?? $default);
    }

    protected function getCharset(array $config, string $default = 'utf8mb4'): string
    {
        return $config['charset'] ?? $default;
    }

    public function getOptions(array $config): array
    {
        $default_options = [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ];
        return $default_options + ($config['options'] ?? []);
    }
}
