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

use Laika\Model\Abstracts\BuilderHelper;
use Laika\Model\Exceptions\CompileException;

class Compile
{
    ######################################################################
    /*--------------------------- PUBLIC API ---------------------------*/
    ######################################################################
    public function __call($name, $arguments): BuilderHelper
    {
        $builder = ucfirst($name);
        return $this->compile($builder, ...$arguments);
    }

    protected function compile(string $builder, mixed ...$params): BuilderHelper
    {
        $builder = "\\Laika\\Model\\Compile\\{$builder}";

        if (!class_exists($builder)) {
            throw new CompileException("Builder Class [{$builder}] Not Found", 10120);
        }

        $instance = new $builder(...$params);
        if (!$instance instanceof BuilderHelper) {
            throw new CompileException("Builder Class [{$builder}] Must Extend BuilderHelper", 10120);
        }

        return $instance;
    }
}