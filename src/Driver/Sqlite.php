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

namespace Laika\Model\Driver;

use Laika\Model\Abstracts\DriverBlueprint;

class Sqlite extends DriverBlueprint
{
    /**
     * @var string $host
     */
    private string $host;

    /**
     * @var array $extensions
     */
    private array $extensions;

    /**
     * @param array{host:string} $config
     */
    public function __construct(array $config)
    {
        // Check Extension Loaded
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException("Extension Not Loaded: [pdo_sqlite]");
        }

        $this->host = trim($config['host'] ?? '');
        $this->extensions = ['sqlite', 'db', 'sqlite3', 'sq3'];

        // Check Host Key Exists
        if (empty($this->host)) {
            throw new \InvalidArgumentException('[host] Key Not Found or Empty!');
        }
    }

    public function dsn(): string
    {
        // Check DB in Memory
        if (preg_match('/^[: ]*memory[: ]*/i', $this->host)) {
            $this->host = ':memory:';
            return "sqlite:{$this->host}";
        }

        if (!is_file($this->host)) {
            touch($this->host);
        }
        // Validate File Extension
        $parts = explode('.', $this->host);
        if (count($parts) < 2 || !in_array(strtolower(end($parts)), $this->extensions)) {
            throw new \InvalidArgumentException("SqLite Supported Extensions Are: [" . implode(', ', $this->extensions) . "].");
        }
        return "sqlite:" . realpath($this->host);
    }
}