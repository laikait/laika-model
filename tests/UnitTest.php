```


## tests/UnitTest.php


```php
<?php

use PHPUnit\Framework\TestCase;
use Laika\Model\Connection;


class UnitTest extends TestCase
{    
    public function testRenderSimple()
    {
        $driver = getenv('DB_DRIVER');
        $config = match ($driver) {
            'mysql', 'pgsql' => [
                'driver'   => $driver,
                'host'     => getenv('DB_HOST'),
                'username' => getenv('DB_USER'),
                'password' => '1234567890#ABcd',
                'database' => getenv('DB_NAME'),
                'port'     => getenv('DB_PORT')
            ],
            'sqlite' => [
                'driver'    => $driver,
                'host'      => getenv('DB_PATH'),
                'database'  => getenv('DB_NAME')
            ],
            default =>  [
                'driver'   => 'none'
            ]
        };

        Connection::add($config);
        $this->assertNotNull(Connection::get(), "Failed to initialize connection for {$driver}");
    }
}
