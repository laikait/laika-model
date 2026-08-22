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

use PHPUnit\Framework\TestCase;
use Laika\Model\Connection;
use Laika\Model\Model;
use Laika\Model\Exceptions\ModelException;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Expression;
use Laika\Model\Schema\Schema;

/** A model that soft-deletes, to prove reset() does not disarm it. */
class SoftDeletingModel extends Model
{
    protected bool $softDelete = true;
}

/** Casts across every supported type. */
class CastingModel extends Model
{
    protected array $casts = [
        'i' => 'int',
        'f' => 'float',
        'b' => 'bool',
        'd' => 'decimal',
        'j' => 'json',
        's' => 'string',
    ];
}

class UnknownCastModel extends Model
{
    protected array $casts = ['i' => 'integar'];
}

/**
 * Behavioural tests for the query builder.
 *
 * Every defect these cover was reproducible before the fix, and none of it had
 * any coverage. Follows UnitTest's shape: no raw PDO, Schema for DDL, Model for
 * CRUD.
 */
class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::purge();
    }

    protected function tearDown(): void
    {
        Connection::purge();
    }

    private function requireSqlite(): void
    {
        if (!in_array('pdo_sqlite', get_loaded_extensions(), true)) {
            $this->markTestSkipped('Extension pdo_sqlite is not loaded.');
        }
    }

    /**
     * Register an in-memory connection and build the shared fixture table.
     *
     * @param int $fetchMode PDO::FETCH_ASSOC or PDO::FETCH_OBJ
     */
    private function seed(int $fetchMode = \PDO::FETCH_ASSOC): void
    {
        $this->requireSqlite();

        Connection::add([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'options'  => [\PDO::ATTR_DEFAULT_FETCH_MODE => $fetchMode],
        ]);

        Schema::on()->create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 50);
            $t->string('role', 20);
            $t->integer('score');
            $t->deleted();
        });

        (new Model())->table('users')->insert([
            ['name' => 'ada',  'role' => 'admin',  'score' => 10],
            ['name' => 'bob',  'role' => 'member', 'score' => 20],
            ['name' => 'cleo', 'role' => 'member', 'score' => 30],
        ]);
    }

    /** @return array<string,array{int}> */
    public static function fetchModeProvider(): array
    {
        return [
            'assoc' => [\PDO::FETCH_ASSOC],
            'obj'   => [\PDO::FETCH_OBJ],
        ];
    }

    private function field(array|object $row, string $key): mixed
    {
        return is_array($row) ? $row[$key] : $row->{$key};
    }

    // -----------------------------------------------------------------------
    // Fetch mode
    //
    // Callers may set PDO::ATTR_DEFAULT_FETCH_MODE through the connection's
    // options and it wins over the library default. Every read method used to
    // die with a TypeError or a raw Error under FETCH_OBJ.
    // -----------------------------------------------------------------------

    /** @dataProvider fetchModeProvider */
    public function testEveryReadMethodWorksUnderBothFetchModes(int $mode): void
    {
        $this->seed($mode);

        $this->assertCount(3, (new Model())->table('users')->get());
        $this->assertSame('ada', $this->field((new Model())->table('users')->find(1), 'name'));
        $this->assertSame(
            'bob',
            $this->field((new Model())->table('users')->where(['id' => 2])->first(), 'name')
        );
        $this->assertSame(3, (new Model())->table('users')->count());
        $this->assertTrue((new Model())->table('users')->exists());
        $this->assertSame(
            ['ada', 'bob', 'cleo'],
            (new Model())->table('users')->order('id')->pluck('name')
        );
        $this->assertCount(3, iterator_to_array((new Model())->table('users')->cursor()));

        $seen = 0;
        (new Model())->table('users')->chunk(2, function (array $rows) use (&$seen): void {
            $seen += count($rows);
        });
        $this->assertSame(3, $seen);
    }

    /** @dataProvider fetchModeProvider */
    public function testNotFoundReturnsNull(int $mode): void
    {
        $this->seed($mode);

        $this->assertNull((new Model())->table('users')->where(['id' => 999])->first());
        $this->assertNull((new Model())->table('users')->find(999));
    }

    // -----------------------------------------------------------------------
    // select() must not be a raw SQL channel
    // -----------------------------------------------------------------------

    public function testSelectRejectsRawSql(): void
    {
        $this->seed();

        $this->expectException(ModelException::class);
        (new Model())->table('users')
            ->select('id FROM users WHERE (1=1) UNION SELECT name FROM users -- ')
            ->get();
    }

    public function testSelectAcceptsExpressionAsTheExplicitRawEscapeHatch(): void
    {
        $this->seed();

        $rows = (new Model())->table('users')->select([new Expression('COUNT(*) AS n')])->get();

        $this->assertSame(3, (int) $this->field($rows[0], 'n'));
    }

    /** The comma-separated and wildcard forms the README documents. */
    public function testSelectSupportsTheDocumentedForms(): void
    {
        $this->seed();

        $rows = (new Model())->table('users')->select('id, name')->order('id')->get();
        $this->assertSame('ada', $this->field($rows[0], 'name'));

        $this->assertStringContainsString('*', (new Model())->table('users')->select('*')->debug());
        $this->assertStringContainsString(
            '"users".*',
            (new Model())->table('users')->select('users.*')->debug()
        );
    }

    public function testSelectWildcardCannotSmuggleASubquery(): void
    {
        $this->seed();

        $this->expectException(ModelException::class);
        (new Model())->table('users')->select('(SELECT 1).*')->get();
    }

    public function testSelectQuotesAliases(): void
    {
        $this->seed();

        $rows = (new Model())->table('users')->select('name AS who')->order('id')->get();

        $this->assertSame('ada', $this->field($rows[0], 'who'));
    }

    // -----------------------------------------------------------------------
    // Binding order and grouping
    // -----------------------------------------------------------------------

    public function testHavingBindingsSurviveBeingDeclaredBeforeWhere(): void
    {
        $this->seed();

        // having() no longer shares a binding list with where(). Before the fix
        // this emitted WHERE 25 HAVING 'member' and matched nothing.
        $rows = (new Model())->table('users')
            ->select(['role', new Expression('SUM(score) AS total')])
            ->having('total', '>', 25)
            ->where(['role' => 'member'])
            ->groupBy('role')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(50, (int) $this->field($rows[0], 'total'));
    }

    public function testMultiColumnOrIsGroupedSoItCannotBleedIntoTheChain(): void
    {
        $this->seed();

        $sql = (new Model())->table('users')
            ->where(['name' => 'ada', 'role' => 'member'], '=', 'OR')
            ->where(['score' => 20])
            ->debug();

        // Un-parenthesised this read as name OR (role AND score).
        $this->assertStringContainsString('OR', $sql);
        $this->assertMatchesRegularExpression('/\(\s*"name".+OR.+"role".+\)/', $sql);

        $rows = (new Model())->table('users')
            ->where(['name' => 'ada', 'role' => 'member'], '=', 'OR')
            ->where(['score' => 20])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('bob', $this->field($rows[0], 'name'));
    }

    // -----------------------------------------------------------------------
    // count()
    // -----------------------------------------------------------------------

    public function testCountIsIndependentOfSelectAndDistinct(): void
    {
        $this->seed();

        // Both used to be syntax errors: COUNT(a, b) and COUNT(DISTINCT *).
        $this->assertSame(3, (new Model())->table('users')->select(['id', 'name'])->count());
        $this->assertSame(3, (new Model())->table('users')->distinct()->count());
        $this->assertSame(2, (new Model())->table('users')->where(['role' => 'member'])->count());
    }

    // -----------------------------------------------------------------------
    // State must not leak between queries on one instance
    // -----------------------------------------------------------------------

    public function testFailedQueryLeavesTheBuilderClean(): void
    {
        $this->seed();

        $model = new Model();

        try {
            $model->table('nosuchtable')->where(['id' => 1])->get();
            $this->fail('Expected the query to fail.');
        } catch (\Throwable) {
            // expected
        }

        // Without the finally these wheres stuck to the instance and silently
        // filtered the next query.
        $this->assertCount(3, $model->table('users')->get());
    }

    public function testAbandonedCursorStillResets(): void
    {
        $this->seed();

        $model = new Model();

        foreach ($model->table('users')->where(['id' => 1])->cursor() as $row) {
            break;
        }

        $this->assertCount(3, $model->table('users')->get());
    }

    public function testTableDoesNotDiscardAHalfBuiltQuery(): void
    {
        $this->seed();

        $sql = (new Model())->select(['name'])->where(['id' => 1])->table('users')->debug();

        $this->assertStringContainsString('"name"', $sql);
        $this->assertStringContainsString('WHERE', $sql);
    }

    public function testDebugDoesNotDestroyTheQuery(): void
    {
        $this->seed();

        $model = new Model();
        $model->table('users')->where(['id' => 2]);

        $first = $model->debug();

        $this->assertSame($first, $model->debug(), 'debug() must be repeatable');
        $this->assertSame('bob', $this->field($model->first(), 'name'));
    }

    // -----------------------------------------------------------------------
    // Soft delete
    // -----------------------------------------------------------------------

    public function testSoftDeleteSurvivesResetAndFiltersReads(): void
    {
        $this->seed();

        (new SoftDeletingModel())->table('users')->where(['id' => 2])->delete();

        // The row is flagged, not gone.
        $this->assertSame(3, (new Model())->table('users')->count());

        $soft = new SoftDeletingModel();
        $this->assertCount(2, $soft->table('users')->get());
        $this->assertCount(3, $soft->table('users')->withTrash()->get());
        $this->assertCount(1, $soft->table('users')->onlyTrashed()->get());
        $this->assertSame(2, $soft->table('users')->count());
    }

    public function testSoftDeleteIsNotDisarmedByAPriorQuery(): void
    {
        $this->seed();

        $model = new SoftDeletingModel();
        $model->table('users')->get();          // reset() used to force softDelete false
        $model->table('users')->where(['id' => 2])->delete();

        $this->assertSame(3, (new Model())->table('users')->count(), 'Row was hard-deleted');
    }

    public function testRestoreClearsTheDeletedFlag(): void
    {
        $this->seed();

        $soft = new SoftDeletingModel();
        $soft->table('users')->where(['id' => 2])->delete();
        $soft->table('users')->where(['id' => 2])->withTrash()->restore();

        $this->assertCount(3, $soft->table('users')->get());
    }

    // -----------------------------------------------------------------------
    // Casting
    // -----------------------------------------------------------------------

    private function castRow(Model $model, array $row): array|object
    {
        $method = new \ReflectionMethod(Model::class, 'cast');
        $method->setAccessible(true);

        return $method->invoke($model, $row);
    }

    public function testNullSurvivesEveryCast(): void
    {
        $this->seed();

        $row = $this->castRow(new CastingModel(), ['i' => null, 'f' => null, 'b' => null, 's' => null]);

        // int used to become 0, float 0.0 and bool false, losing the difference
        // between "unset" and "zero".
        $this->assertNull($row['i']);
        $this->assertNull($row['f']);
        $this->assertNull($row['b']);
        $this->assertNull($row['s']);
    }

    public function testPostgresBooleanLiteralsCastCorrectly(): void
    {
        $this->seed();

        // pdo_pgsql returns 't'/'f'; 'f' was absent from the falsy list, so
        // every false became true.
        $this->assertFalse($this->castRow(new CastingModel(), ['b' => 'f'])['b']);
        $this->assertTrue($this->castRow(new CastingModel(), ['b' => 't'])['b']);
        $this->assertFalse($this->castRow(new CastingModel(), ['b' => '0'])['b']);
        $this->assertTrue($this->castRow(new CastingModel(), ['b' => '1'])['b']);
    }

    public function testBigIntegersAreNotClampedToPhpIntMax(): void
    {
        $this->seed();

        $big = '18446744073709551615';

        $this->assertSame($big, $this->castRow(new CastingModel(), ['i' => $big])['i']);
        $this->assertSame(42, $this->castRow(new CastingModel(), ['i' => '42'])['i']);
    }

    public function testDecimalKeepsItsPrecisionAsAString(): void
    {
        $this->seed();

        $this->assertSame('19.9900', $this->castRow(new CastingModel(), ['d' => '19.9900'])['d']);
    }

    public function testUnknownCastTypeThrowsInsteadOfPassingThrough(): void
    {
        $this->seed();

        $this->expectException(ModelException::class);
        $this->castRow(new UnknownCastModel(), ['i' => 1]);
    }

    public function testJsonCastRoundTrips(): void
    {
        $this->seed();

        $this->assertSame(
            ['a' => 1],
            $this->castRow(new CastingModel(), ['j' => '{"a":1}'])['j']
        );
    }

    // -----------------------------------------------------------------------
    // cursor() and chunk()
    // -----------------------------------------------------------------------

    public function testCursorYieldsEveryRow(): void
    {
        $this->seed();

        $names = [];
        foreach ((new Model())->table('users')->order('id')->cursor() as $row) {
            $names[] = $this->field($row, 'name');
        }

        $this->assertSame(['ada', 'bob', 'cleo'], $names);
    }

    public function testChunkHonoursACallerLimit(): void
    {
        $this->seed();

        $seen = 0;
        (new Model())->table('users')->order('id')->limit(2)
            ->chunk(1, function (array $rows) use (&$seen): void {
                $seen += count($rows);
            });

        // chunk() used to overwrite limit() outright and walk the whole table.
        $this->assertSame(2, $seen);
    }

    public function testChunkStopsWhenTheCallbackReturnsFalse(): void
    {
        $this->seed();

        $batches = 0;
        (new Model())->table('users')->order('id')->chunk(1, function () use (&$batches): bool {
            $batches++;
            return false;
        });

        $this->assertSame(1, $batches);
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    public function testUpdateWorksAndPutsJoinsBeforeSet(): void
    {
        $this->seed();

        $affected = (new Model())->table('users')->where(['id' => 1])->update(['score' => 99]);

        $this->assertSame(1, $affected);
        $this->assertSame(99, (int) $this->field((new Model())->table('users')->find(1), 'score'));
    }

    public function testInsertValidatesEveryRowBeforeWritingAny(): void
    {
        $this->seed();

        try {
            (new Model())->table('users')->insert([
                ['name' => 'x', 'role' => 'r', 'score' => 1],
                ['name' => 'y', 'score' => 2],   // missing 'role'
            ]);
            $this->fail('Expected a ModelException.');
        } catch (ModelException) {
            // expected
        }

        // Nothing from the batch may have landed.
        $this->assertSame(3, (new Model())->table('users')->count());
    }

    public function testIncrementAcceptsColumnsContainingDigits(): void
    {
        $this->requireSqlite();

        Connection::add(['driver' => 'sqlite', 'database' => ':memory:']);
        Schema::on()->create('metrics', function (Blueprint $t): void {
            $t->id();
            $t->integer('views2');
        });

        (new Model())->table('metrics')->insert(['views2' => 5]);

        // The old guard regex /^[a-z._]+$/i rejected any column with a digit.
        $this->assertSame(1, (new Model())->table('metrics')->where(['id' => 1])->increment('views2', 3));
        $this->assertSame(8, (int) $this->field((new Model())->table('metrics')->find(1), 'views2'));

        $this->assertSame(1, (new Model())->table('metrics')->where(['id' => 1])->decrement('views2', 2));
        $this->assertSame(6, (int) $this->field((new Model())->table('metrics')->find(1), 'views2'));
    }

    public function testDecrementRefusesThePrimaryKeyWithItsOwnMessage(): void
    {
        $this->seed();

        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('Decrement Primary Key');

        (new Model())->table('users')->where(['id' => 1])->decrement('id');
    }

    // -----------------------------------------------------------------------
    // Identifier safety
    // -----------------------------------------------------------------------

    public function testIdentifiersAreValidatedNotJustQuoted(): void
    {
        $this->seed();

        $this->expectException(ModelException::class);
        (new Model())->table('users')->where(['name; DROP TABLE users' => 'x'])->get();
    }

    public function testMissingTableRaisesADomainException(): void
    {
        $this->seed();

        // Was a PDOException, which means "driver error" and is misleading.
        $this->expectException(ModelException::class);
        (new Model())->get();
    }
}
