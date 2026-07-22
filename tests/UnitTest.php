```


## tests/UnitTest.php


```php
<?php

use PHPUnit\Framework\TestCase;
use Laika\Model\Connection;
use Laika\Model\Schema\Schema;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Model;


class UnitTest extends TestCase
{    
    public function testConnectionTest()
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

    public function testCreateTable()
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
        Schema::on()->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('full_name', 255);
            $t->timestamp('created_at');

            $t->index('created_at');
        });

        $model = new Model();
        try {
            $data = ['full_name' => 'Showket Ahmed'];
            $inserted = $model->table('users')->insert($data);
            $row = $model->table('users')->get($data);
            echo "Inserted Data: " . json_encode($row, JSON_FORCE_OBJECT);
        } catch (\Throwable $th) {
            echo $th->getMessage();
        }

        $this->assertTrue((bool) $inserted , "Failed to insert data in driver [{$driver}]");
    }
}
