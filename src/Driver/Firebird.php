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

class Firebird extends DriverBlueprint
{
    /**
     * @var string $host
     */
    private string $host;

    /**
     * @var string $database
     */
    private string $database;

    /**
     * @var string $charset
     */
    private string $charset;

    /**
     * @param array{host:string,database:string,charset?:string} $config
     */
    public function __construct(array $config)
    {
        // Check Extension Loaded
        if (!extension_loaded('pdo_firebird')) {
            throw new \RuntimeException("Extension Not Loaded: [pdo_firebird]");
        }

        $this->host = trim($config['host'] ?? '');
        $this->database = trim($config['database'] ?? '');
        $this->charset = trim($config['charset'] ?? 'UTF8');

        // Check Host Key Exists
        if (empty($this->host)) {
            throw new \InvalidArgumentException('[host] Key Not Found or Empty!');
        }

        // Check Database Key Exists
        if (empty($this->database)) {
            throw new \InvalidArgumentException('[database] Key Not Found or Empty!');
        }
    }

    public function dsn(): string
    {
        return "firebird:dbname={$this->host}.{$this->database};charset={$this->charset}";
    }
}
