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

class BooleanAttribute extends BuilderHelper
{
    /**
     * @var bool|string|int $value Boolean Value. Example: true or false or 0 or 1 or 'TRUE' or 'FALSE'
     */
    protected bool|string|int $value;

    /**
     * Compile BOOLEAN Blueprint
     * @param bool $value Boolean Value. Example: true or false
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(bool $value, string $driver)
    {
        switch ($driver) {
            case 'mysql':
                $this->value = $value ? 1 : 0;
                break;
            case 'pgsql':
                $this->value = $value ? 'TRUE' : 'FALSE';
                break;
            case 'sqlite':
                $this->value = $value ? '1' : '0';
                break;
            case 'sqlsrv':
                $this->value = $value ? '1' : '0';
                break;
            case 'oci':
                $this->value = $value ? '1' : '0';
                break;
            case 'firebird':
                $this->value = $value ? '1' : '0';
                break;            
            default:
                throw new PDOException("Query Builder Detected Invalid Driver: [{$driver}]", 10110);
                break;
        }
    }

    public function sql(): ?string
    {
        return null;
    }

    public function value(): bool|string|int
    {
        return $this->value;
    }
}
