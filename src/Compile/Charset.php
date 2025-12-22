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

namespace Laika\Model\Compile;

use PDOException;
use Laika\Model\Abstracts\BuilderHelper;

class Charset extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile CHARSET Blueprint
     * @param ?string $charset Charset Name. Example: 'utf8mb4'
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(?string $charset, string $driver)
    {
        switch ($driver) {
            case 'mysql':
                $charset = !empty($charset) ? $charset : 'utf8mb4';
                $this->query = "\nDEFAULT CHARSET={$charset}";
                break;
            case 'pgsql':
                $this->query = "";
                break;
            case 'sqlite':
                $this->query = "";
                break;
            case 'sqlsrv':
                $this->query = "";
                break;
            case 'oci':
                $this->query = "";
                break;
            case 'firebird':
                $this->query = "";
                break;
            default:
                $this->query = throw new PDOException("Query Builder Detected Invalid Driver: [{$driver}]", 10110);
                break;
        }
    }

    public function sql(): ?string
    {
        return $this->query;
    }
}
