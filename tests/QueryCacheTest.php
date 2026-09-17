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

namespace Laika\Model\Tests;

use Laika\Model\Connection;
use Laika\Model\Log;
use Laika\Model\Model;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use PHPUnit\Framework\TestCase;

/**
 * A store that behaves like laika-cache where it matters: values go through
 * serialize() and come back with allowed_classes false, so an object in a
 * cached result would come back broken exactly as it would in production.
 */
final class SerializingStore
{
    /** @var array<string,string> */
    public array $data = [];

    public bool $broken = false;

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->broken) {
            throw new \RuntimeException('store down');
        }

        return array_key_exists($key, $this->data)
            ? unserialize($this->data[$key], ['allowed_classes' => false])
            : $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($this->broken) {
            throw new \RuntimeException('store down');
        }

        $this->data[$key] = serialize($value);

        return true;
    }

    public function pop(string $key): bool
    {
        unset($this->data[$key]);

        return true;
    }
}

final class CastingScoreModel extends Model
{
    protected array $casts = ['score' => 'int'];
}

/**
 * Query caching is only worth having if it is never wrong. Most of these tests
 * are about invalidation -- when a cached result must stop being served --
 * because that is where a cache turns into a bug.
 *
 * "Queries issued" is read from Log, which records only statements that
 * actually reached the database.
 */
final class QueryCacheTest extends TestCase
{
    private SerializingStore $store;

    protected function setUp(): void
    {
        if (!in_array('pdo_sqlite', get_loaded_extensions(), true)) {
            $this->markTestSkipped('Extension pdo_sqlite is not loaded.');
        }

        Connection::purge();
        Log::flush();

        $this->store = new SerializingStore();
        Model::setQueryCache(fn () => $this->store, 60);
    }

    protected function tearDown(): void
    {
        Model::setQueryCache(null);
        Connection::purge();
        Log::flush();
    }

    private function seed(int $fetchMode = \PDO::FETCH_ASSOC): void
    {
        Connection::add([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'options'  => [\PDO::ATTR_DEFAULT_FETCH_MODE => $fetchMode],
        ]);

        Schema::on()->create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 50);
            $t->integer('score');
        });

        Schema::on()->create('posts', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('title', 50);
        });

        (new Model())->table('users')->insert([
            ['name' => 'ada', 'score' => 10],
            ['name' => 'bob', 'score' => 20],
        ]);
        (new Model())->table('posts')->insert(['user_id' => 1, 'title' => 'hello']);

        Log::flush();
    }

    /** Run a callback and return how many statements reached the database */
    private function queries(callable $fn): int
    {
        $before = Log::count();
        $fn();

        return Log::count() - $before;
    }

    private function names(): array
    {
        return array_column((new Model())->table('users')->order('id')->remember()->get(), 'name');
    }

    /*=============================== HITTING ================================*/

    public function testARememberedQueryReachesTheDatabaseOnce(): void
    {
        $this->seed();

        $first = null;
        $issued = $this->queries(function () use (&$first): void {
            $first = (new Model())->table('users')->where(['name' => 'ada'])->remember()->get();
            $second = (new Model())->table('users')->where(['name' => 'ada'])->remember()->get();
            self::assertSame($first, $second);
        });

        self::assertSame(1, $issued);
        self::assertSame('ada', $first[0]['name']);
    }

    public function testFirstFindAndPluckAreCachedThroughGet(): void
    {
        $this->seed();

        $issued = $this->queries(function (): void {
            foreach ([1, 2] as $_) {
                (new Model())->table('users')->where(['id' => 1])->remember()->first();
                (new Model())->table('users')->remember()->pluck('name');
            }
        });

        self::assertSame(2, $issued);
    }

    public function testDifferentBindingsDoNotShareAnEntry(): void
    {
        $this->seed();

        $ada = (new Model())->table('users')->where(['name' => 'ada'])->remember()->get();
        $bob = (new Model())->table('users')->where(['name' => 'bob'])->remember()->get();

        self::assertSame('ada', $ada[0]['name']);
        self::assertSame('bob', $bob[0]['name']);
    }

    public function testCountIsCachedSeparatelyFromGet(): void
    {
        $this->seed();

        $issued = $this->queries(function (): void {
            self::assertSame(2, (new Model())->table('users')->remember()->count());
            self::assertSame(2, (new Model())->table('users')->remember()->count());
            // Same builder state, different kind: must not come back as the count
            self::assertCount(2, (new Model())->table('users')->remember()->get());
        });

        self::assertSame(2, $issued);
    }

    /*============================ NOT CACHING ===============================*/

    public function testAQueryWithoutRememberIsNeverCached(): void
    {
        $this->seed();

        self::assertSame(2, $this->queries(function (): void {
            (new Model())->table('users')->get();
            (new Model())->table('users')->get();
        }));
        self::assertSame([], $this->store->data);
    }

    public function testRememberIsANoOpWithNoStoreConfigured(): void
    {
        $this->seed();
        Model::setQueryCache(null);

        self::assertSame(2, $this->queries(function (): void {
            (new Model())->table('users')->remember()->get();
            (new Model())->table('users')->remember()->get();
        }));
    }

    public function testRememberDoesNotLeakIntoTheNextQueryOnTheSameInstance(): void
    {
        $this->seed();
        $model = new Model();

        $model->table('users')->remember()->get();

        // reset() must clear the flag, or every later query on this instance
        // would be cached without having asked to be
        self::assertSame(2, $this->queries(function () use ($model): void {
            $model->table('users')->get();
            $model->table('users')->get();
        }));
    }

    public function testABrokenStoreDegradesToRunningTheQuery(): void
    {
        $this->seed();
        $this->store->broken = true;

        self::assertCount(2, (new Model())->table('users')->remember()->get());
        self::assertSame(2, (new Model())->table('users')->remember()->count());
    }

    /*=============================== SHAPES =================================*/

    public function testAHitUnderFetchObjReturnsRealObjectsWithCastsApplied(): void
    {
        // Rows are stdClass here. Cached as-is they would come back as
        // __PHP_Incomplete_Class through allowed_classes false; cached as arrays
        // and rebuilt, they come back identical to a miss.
        $this->seed(\PDO::FETCH_OBJ);

        $miss = (new CastingScoreModel())->table('users')->order('id')->remember()->get();
        $hit  = (new CastingScoreModel())->table('users')->order('id')->remember()->get();

        self::assertInstanceOf(\stdClass::class, $hit[0]);
        self::assertSame(10, $hit[0]->score);
        self::assertEquals($miss, $hit);
    }

    /*============================ INVALIDATION ==============================*/

    public function testEveryWriteInvalidatesTheTable(): void
    {
        $this->seed();
        self::assertSame(['ada', 'bob'], $this->names());

        (new Model())->table('users')->insert(['name' => 'cleo', 'score' => 30]);
        self::assertSame(['ada', 'bob', 'cleo'], $this->names(), 'insert');

        (new Model())->table('users')->where(['name' => 'cleo'])->update(['name' => 'cora']);
        self::assertSame(['ada', 'bob', 'cora'], $this->names(), 'update');

        (new Model())->table('users')->where(['name' => 'cora'])->delete();
        self::assertSame(['ada', 'bob'], $this->names(), 'delete');

        $score = fn () => (new Model())->table('users')->where(['id' => 1])->remember()->first()['score'];
        self::assertSame(10, (int) $score());
        (new Model())->table('users')->where(['id' => 1])->increment('score', 5);
        self::assertSame(15, (int) $score(), 'increment');
    }

    public function testAWriteToAJoinedTableInvalidatesTheJoin(): void
    {
        $this->seed();

        $titles = fn () => array_column(
            (new Model())->table('users')->select(['users.name', 'posts.title'])
                ->join('posts', 'posts.user_id', '=', 'users.id', 'INNER')
                ->remember()->get(),
            'title'
        );

        self::assertSame(['hello'], $titles());

        // Only posts is written; the query's own table is untouched
        (new Model())->table('posts')->where(['id' => 1])->update(['title' => 'changed']);

        self::assertSame(['changed'], $titles());
    }

    public function testAWriteToAnUnrelatedTableKeepsTheEntry(): void
    {
        $this->seed();
        $this->names();

        (new Model())->table('posts')->insert(['user_id' => 2, 'title' => 'other']);

        self::assertSame(0, $this->queries(fn () => $this->names()));
    }

    public function testALostGenerationTokenCausesAMissNotAStaleHit(): void
    {
        // Memcached evicts under memory pressure. The token being gone must
        // never let an old entry be served again.
        $this->seed();
        $this->names();

        foreach (array_keys($this->store->data) as $key) {
            if (str_starts_with($key, 'query-gen:')) {
                unset($this->store->data[$key]);
            }
        }

        self::assertSame(1, $this->queries(fn () => $this->names()));
    }

    public function testForgetQueryCacheCoversWritesTheModelCannotSee(): void
    {
        $this->seed();
        $this->names();

        (new Model())->execute("UPDATE users SET name = 'ann' WHERE id = 1");

        // Raw SQL is invisible to invalidation: still the old answer...
        self::assertSame(['ada', 'bob'], $this->names());

        Model::forgetQueryCache('users');

        // ...until told
        self::assertSame(['ann', 'bob'], $this->names());
    }

    /*============================= TRANSACTIONS =============================*/

    public function testAReadInsideATransactionIsNeverCached(): void
    {
        $this->seed();

        try {
            (new Model())->transaction(function (Model $m): void {
                $m->table('users')->insert(['name' => 'uncommitted', 'score' => 0]);

                // This read sees a row that may never exist. Cached, it would be
                // served to every other request after the rollback.
                $issued = $this->queries(function () use ($m): void {
                    $m->table('users')->remember()->get();
                    $m->table('users')->remember()->get();
                });
                self::assertSame(2, $issued);

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        $cached = array_filter(array_keys($this->store->data), fn ($k) => str_starts_with($k, 'query:'));
        self::assertSame([], array_values($cached));
        self::assertSame(['ada', 'bob'], $this->names());
    }

    public function testInvalidationWaitsForCommit(): void
    {
        $this->seed();
        $this->names();

        (new Model())->transaction(function (Model $m): void {
            $m->table('users')->insert(['name' => 'cleo', 'score' => 30]);

            // Still inside: other requests cannot see cleo yet, so the entry
            // they would read must not have been invalidated either
            $tokens = array_filter(array_keys($this->store->data), fn ($k) => str_starts_with($k, 'query-gen:'));
            self::assertNotEmpty($tokens, 'invalidated before commit');
        });

        self::assertSame(['ada', 'bob', 'cleo'], $this->names());
    }

    public function testARolledBackWriteLeavesTheEntryInPlace(): void
    {
        $this->seed();
        $this->names();

        try {
            (new Model())->transaction(function (Model $m): void {
                $m->table('users')->insert(['name' => 'ghost', 'score' => 0]);
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }

        // Nothing was stored, so the cached answer is still right and still served
        self::assertSame(0, $this->queries(fn () => self::assertSame(['ada', 'bob'], $this->names())));
    }
}
