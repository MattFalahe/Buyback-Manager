<?php

namespace BuybackManager\Tests\Structure;

use BuybackManager\Tests\Sqlite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * A fresh install: every migration run in order on an empty database.
 */
class MigrationsTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src/';

    protected function setUp(): void
    {
        Sqlite::migrate();
    }

    protected function tearDown(): void
    {
        Sqlite::close();
    }

    /** @return string[] */
    private static function names(string $type): array
    {
        $names = array_column(DB::select('select name from sqlite_master where type = ?', [$type]), 'name');

        return array_values(array_filter($names, fn ($name) => !str_starts_with($name, 'sqlite_')));
    }

    public function test_the_health_check_expects_exactly_the_tables_the_migrations_make(): void
    {
        preg_match('/const PLUGIN_TABLES = \[(.*?)\];/s', file_get_contents(self::SRC . 'Http/Controllers/DiagnosticController.php'), $block);
        preg_match_all("/'([a-z_]+)'/", $block[1], $expected);

        $this->assertEqualsCanonicalizing($expected[1], self::names('table'));
    }

    /** MySQL refuses an index name over 64 characters, and Laravel builds one from the table and columns when none is given. */
    public function test_every_index_name_fits_in_what_mysql_allows(): void
    {
        $indexes = self::names('index');

        $this->assertNotEmpty($indexes);
        foreach ($indexes as $index) {
            $this->assertLessThanOrEqual(64, strlen($index), $index);
        }
    }

    public function test_every_column_a_model_fills_or_casts_exists(): void
    {
        foreach (glob(self::SRC . 'Models/*.php') as $path) {
            $class = 'BuybackManager\\Models\\' . basename($path, '.php');
            $model = new $class();
            $columns = Schema::getColumnListing($model->getTable());

            $this->assertNotSame([], $columns, "$class reads {$model->getTable()}, which no migration makes");
            foreach (array_merge($model->getFillable(), array_keys($model->getCasts())) as $column) {
                $this->assertContains($column, $columns, "$class names $column");
            }
        }
    }
}
