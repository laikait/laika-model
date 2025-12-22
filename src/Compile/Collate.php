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

class Collate extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile COLLATION Blueprint
     * @param ?string $collation Collation Name. Example: 'utf8mb4_unicode_ci'
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(?string $collation, string $driver)
    {
        switch ($driver) {
            case 'mysql':
                $collation = !empty($collation) ? $collation : 'utf8mb4_unicode_ci';
                $this->query = "\nCOLLATE={$collation}";
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
