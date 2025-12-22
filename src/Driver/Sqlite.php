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

use InvalidArgumentException;
use Laika\Model\Abstracts\DriverBlueprint;

class Sqlite extends DriverBlueprint
{
    private string $host;

    /**
     * @param array{host:string} $config
     */
    public function __construct(array $config)
    {
        $this->host = $config['host'];
    }

    public function dsn(): string
    {
        // Create File-based SQLite DSN
        if ($this->host !== ':memory:') {
            if (!is_file($this->host)) {
                touch($this->host);
            }
            // Validate File Extension
            $parts = explode('.', $this->host);
            if (count($parts) < 2 || !in_array(strtolower(end($parts)), ['sqlite', 'db', 'sqlite3', 'sq3'])) {
                throw new InvalidArgumentException("SQLite Database File must have a .sqlite or .db or .sqlite3 or .sq3 extension.");
            }
            return "sqlite:" . realpath($this->host);
        }
        return "sqlite:{$this->host}";
    }
}