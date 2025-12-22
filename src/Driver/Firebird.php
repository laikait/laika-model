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

class Firebird extends DriverBlueprint
{
    private string $host;
    private string $database;
    private string $charset;

    /**
     * @param array{host?:string,database:string,charset?:string} $config
     */
    public function __construct(array $config)
    {
        // Check Database Name Key Exists
        if (empty($config['database'])) {
            throw new InvalidArgumentException('[database] Key Not Found in Config!');
        }

        $this->host = $config['host'] ?? 'localhost';
        $this->database = $config['database'];
        $this->charset = $config['charset'] ?? 'UTF8';
    }

    public function dsn(): string
    {
        return "firebird:dbname={$this->host}.{$this->database};charset={$this->charset}";
    }
}
