<?php

namespace BuybackManager\Tests\Structure;

use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\DevBuildUpgrade;
use BuybackManager\Tests\RouteTable;
use PHPUnit\Framework\TestCase;

/**
 * Things a mistake in the source would break on a live install that nothing
 * else catches before then, read from the files themselves.
 */
class SourceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';
    private const SRC = self::ROOT . 'src/';
    private const MIGRATIONS = 'Database/migrations';

    /**
     * The one exception to the two guards on other tables, agreed for moving
     * off a development build from before 1.0.0: the upgrade drops the old
     * builds' own tables and deletes their dead migration and schedule
     * records, each named one by one. The statements are matched exactly, so
     * a wider one still fails.
     */
    private const DEV_BUILD_UPGRADE = 'Services/DevBuildUpgrade.php';
    private const DEV_BUILD_WRITES = [
        'migrations' => "DB::table('migrations')->whereIn('migration', self::OLD_MIGRATIONS)->delete()",
        'schedules' => "DB::table('schedules')->whereIn('id', \$stale)->delete()",
    ];
    private const DEV_BUILD_DROP = "foreach (self::OLD_TABLES as \$table) {\n                if (\$this->ours(\$table)) {\n                    Schema::drop(\$table);";

    /** @return string[] paths of the files under src/ with this ending */
    private static function files(string $ending, string $under = ''): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC . $under, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getPathname(), $ending)) {
                $found[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Blade reads a directive with \B@\w+, so counting @if and @endif proves
     * nothing: {{ $h }}h@endif is not a directive, and leaves its @if open.
     */
    public function test_every_blade_directive_is_closed_the_way_blade_reads_it(): void
    {
        $opens = [
            'if' => 'endif', 'unless' => 'endunless', 'isset' => 'endisset', 'foreach' => 'endforeach',
            'forelse' => 'endforelse', 'for' => 'endfor', 'while' => 'endwhile', 'push' => 'endpush',
            'error' => 'enderror', 'can' => 'endcan',
        ];
        $closes = array_flip($opens) + ['endsection' => 'section', 'stop' => 'section', 'endphp' => 'php'];

        foreach (self::files('.blade.php') as $path) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));
            // @@if is Blade's escape for a literal @if.
            $source = preg_replace('/@@\w+/', '', $source);
            $source = preg_replace('/\B@php\b(?!\s*\().*?\B@endphp\b/s', '', $source);
            // A section with its content in the call has nothing to close.
            $source = preg_replace("/\B@section\(\s*'[^']*'\s*,.*\)\s*$/m", '', $source);

            preg_match_all('/\B@(\w+)/', $source, $matches);
            $stack = [];
            foreach ($matches[1] as $directive) {
                if ($directive === 'section') {
                    $stack[] = 'section';
                } elseif (isset($opens[$directive])) {
                    $stack[] = $directive;
                } elseif (isset($closes[$directive])) {
                    $this->assertSame($closes[$directive], array_pop($stack), basename($path) . ": @$directive closes the wrong block");
                }
            }

            $this->assertSame([], $stack, basename($path) . ': left open');
        }
    }

    public function test_every_language_key_the_plugin_names_exists(): void
    {
        $lang = [];
        foreach (self::files('.php', 'resources/lang/en') as $path) {
            $lang[basename($path, '.php')] = require $path;
        }

        // A permission's label is a language key too: SeAT translates it.
        foreach (self::files('.php') as $path) {
            preg_match_all("/(?:\b(?:trans|__|trans_choice)\(\s*|@lang\(\s*|'label' => )'buyback-manager::([a-z_]+)\.([a-zA-Z0-9_.]+)'/", file_get_contents($path), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $file, $key]) {
                $value = $lang[$file] ?? null;
                foreach (explode('.', $key) as $part) {
                    $value = is_array($value) ? ($value[$part] ?? null) : null;
                }
                $this->assertNotNull($value, basename($path) . " names $file.$key, which is not there");
            }
        }
    }

    public function test_every_view_the_plugin_renders_exists(): void
    {
        foreach (self::files('.php') as $path) {
            preg_match_all("/(?:\bview\(|@include(?:If)?\(|@extends\()\s*'buyback-manager::([a-z0-9_.-]+)'/", file_get_contents($path), $matches);
            foreach ($matches[1] as $view) {
                $this->assertFileExists(self::SRC . 'resources/views/' . str_replace('.', '/', $view) . '.blade.php', basename($path) . " renders $view");
            }
        }
    }

    public function test_every_route_is_answered_by_a_controller_method_that_exists(): void
    {
        $routes = RouteTable::read(self::SRC . 'Http/routes.php');

        $this->assertNotEmpty($routes->actions);
        foreach ($routes->actions as [$controller, $method]) {
            $file = self::SRC . str_replace('\\', '/', substr($controller, strlen('BuybackManager\\'))) . '.php';
            $this->assertFileExists($file, $controller);
            $this->assertMatchesRegularExpression("/\bpublic function $method\(/", file_get_contents($file), "$controller@$method");
        }
    }

    /** A route name nobody defined is a 500 the first time the page links to it. */
    public function test_every_route_the_plugin_links_to_exists(): void
    {
        $named = RouteTable::read(self::SRC . 'Http/routes.php')->named;

        // The sidebar names its routes in its config.
        foreach (self::files('.php') as $path) {
            preg_match_all("/(?:\broute\(|'route' => )\s*'(buyback[a-z0-9_.-]*)'/", file_get_contents($path), $matches);
            foreach ($matches[1] as $name) {
                $this->assertArrayHasKey($name, $named, basename($path) . " links to $name");
            }
        }
    }

    public function test_migrations_are_named_classes_matching_their_file(): void
    {
        foreach (self::files('.php', self::MIGRATIONS) as $path) {
            $source = file_get_contents($path);
            $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path, '.php'));
            $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $suffix)));

            $this->assertStringNotContainsString('return new class', $source, basename($path));
            $this->assertMatchesRegularExpression('/\bclass ' . $class . ' extends Migration\b/', $source, basename($path));
            $this->assertStringContainsString('buyback_manager', $suffix, basename($path) . ' has to name the plugin: migrations share one namespace');
        }
    }

    public function test_migration_numbers_run_in_order_with_none_repeated(): void
    {
        $numbers = array_map(fn ($path) => (int) substr(basename($path), 11, 6), self::files('.php', self::MIGRATIONS));

        $this->assertSame(range(1, count($numbers)), $numbers);
    }

    public function test_index_names_fit_in_what_mysql_allows(): void
    {
        foreach (self::files('.php', self::MIGRATIONS) as $path) {
            preg_match_all("/'((?:bb|buyback)_[a-z0-9_]+)'/", file_get_contents($path), $matches);
            foreach ($matches[1] as $name) {
                $this->assertLessThanOrEqual(64, strlen($name), $name);
            }
        }
    }

    /**
     * SeAT's tables are read, never written: our data lives in our own tables.
     * Even the schedule row is SeAT's seeder's to write.
     */
    public function test_nothing_writes_a_table_that_is_not_the_plugins(): void
    {
        $written = [];
        foreach (self::files('.php') as $path) {
            preg_match_all("/DB::table\('([a-zA-Z_]+)(?: as \w+)?'\)((?:(?!DB::table|;).)*?)->(insert|insertOrIgnore|insertUsing|insertGetId|update|updateOrInsert|upsert|delete|truncate|increment|decrement)\(/s", file_get_contents($path), $matches, PREG_SET_ORDER);
            foreach ($matches as [$statement, $table]) {
                $written[] = $table;
                if (str_ends_with($path, self::DEV_BUILD_UPGRADE) && isset(self::DEV_BUILD_WRITES[$table])) {
                    $this->assertSame(self::DEV_BUILD_WRITES[$table], $statement . ')', "DevBuildUpgrade writes $table beyond the old builds' records");
                    continue;
                }
                $this->assertStringStartsWith('buyback_manager_', $table, basename($path) . " writes $table");
            }
        }

        // The ledger's insertOrIgnore is a known write: missing it would mean
        // the pattern no longer reads the code.
        $this->assertContains('buyback_manager_subscribed_types', $written);
    }

    /** Not a column, not an index: SeAT's schema is SeAT's, and Manager Core's is its own. */
    public function test_nothing_changes_a_table_that_is_not_the_plugins(): void
    {
        foreach (self::files('.php', self::MIGRATIONS) as $path) {
            $source = file_get_contents($path);

            preg_match_all("/Schema::(?:create|table|drop|dropIfExists|rename)\(\s*'([a-zA-Z_]+)'/", $source, $named);
            preg_match_all('/\b(?:ALTER TABLE|CREATE (?:UNIQUE )?INDEX \S+ ON|DROP TABLE(?: IF EXISTS)?)\s+`?([a-zA-Z_]+)/i', $source, $statements);

            foreach (array_merge($named[1], $statements[1]) as $table) {
                $this->assertStringStartsWith('buyback_manager_', $table, basename($path));
            }
        }

        foreach (self::files('.php') as $path) {
            if (!str_contains($path, self::MIGRATIONS)) {
                $source = str_replace("\r\n", "\n", file_get_contents($path));
                if (str_ends_with($path, self::DEV_BUILD_UPGRADE)) {
                    $this->assertSame(1, substr_count($source, self::DEV_BUILD_DROP), 'DevBuildUpgrade drops only the old builds\' tables');
                    $source = str_replace(self::DEV_BUILD_DROP, '', $source);
                }
                $this->assertDoesNotMatchRegularExpression('/Schema::(create|table|drop|dropIfExists|rename)\(/', $source, basename($path) . ' changes a schema outside a migration');
            }
        }
    }

    /** What the exception above lets the upgrade reach, and nothing more. */
    public function test_the_dev_build_upgrade_reaches_only_what_the_old_builds_left(): void
    {
        foreach (DevBuildUpgrade::OLD_TABLES as $table) {
            $this->assertMatchesRegularExpression('/^buyback_(?!manager_)[a-z_]+$/', $table);
        }
        foreach (DevBuildUpgrade::CARRIED as $old => $new) {
            $this->assertContains($old, DevBuildUpgrade::OLD_TABLES);
            $this->assertStringStartsWith('buyback_manager_', $new);
        }

        $current = array_map(fn ($path) => basename($path, '.php'), self::files('.php', self::MIGRATIONS));
        $this->assertSame([], array_values(array_intersect(DevBuildUpgrade::OLD_MIGRATIONS, $current)));

        $source = file_get_contents(self::SRC . self::DEV_BUILD_UPGRADE);
        $this->assertStringContainsString("DB::table('schedules')->where('command', 'like', 'buyback-manager:%')", $source);
    }

    public function test_every_command_is_registered(): void
    {
        $provider = file_get_contents(self::SRC . 'BuybackManagerServiceProvider.php');

        foreach (glob(self::SRC . 'Console/Commands/*Command.php') as $path) {
            $class = basename($path, '.php');
            $this->assertStringContainsString("use BuybackManager\\Console\\Commands\\$class;", $provider, $class);
            $this->assertStringContainsString("$class::class", $provider, $class);
        }
    }

    public function test_the_schedule_and_the_health_check_expect_the_same_commands_on_the_same_cadence(): void
    {
        $schedules = function (string $file): array {
            preg_match_all("/'command' => '([^']+)',\s*'expression' => '([^']+)'/", file_get_contents(self::SRC . $file), $matches, PREG_SET_ORDER);

            return array_map(fn ($match) => "$match[1] $match[2]", $matches);
        };

        $seeded = $schedules('Database/Seeders/ScheduleSeeder.php');

        $this->assertNotEmpty($seeded);
        $this->assertEqualsCanonicalizing($seeded, $schedules('Http/Controllers/DiagnosticController.php'));
    }

    public function test_the_docs_cover_every_command_and_option_and_name_none_that_is_gone(): void
    {
        $known = [];
        foreach (glob(self::SRC . 'Console/Commands/*Command.php') as $path) {
            $source = file_get_contents($path);
            $this->assertSame(1, preg_match("/signature = 'buyback-manager:([a-z-]+)(.*?)';/s", $source, $signature), basename($path));
            preg_match_all('/\{--([a-z-]+)/', $signature[2], $options);
            $known[$signature[1]] = $options[1];
        }

        foreach (['README.md', 'src/resources/views/help/index.blade.php'] as $file) {
            $docs = file_get_contents(self::ROOT . $file);

            foreach ($known as $command => $options) {
                $this->assertStringContainsString("buyback-manager:$command", $docs, "$file leaves out $command");
                foreach ($options as $option) {
                    $this->assertStringContainsString("--$option", $docs, "$file leaves out $command --$option");
                }
            }

            preg_match_all('/buyback-manager:([a-z-]+)/', $docs, $named);
            foreach (array_unique($named[1]) as $command) {
                $this->assertArrayHasKey($command, $known, "$file names $command, which is not a command");
            }
        }
    }

    /** A package name with a branch in it stops working the day the branch goes, and people copy what the docs say. */
    public function test_the_docs_name_the_package_and_no_branch(): void
    {
        foreach (['README.md', 'CHANGELOG.md', 'src/resources/views/help/index.blade.php'] as $file) {
            $source = file_get_contents(self::ROOT . $file);
            $this->assertDoesNotMatchRegularExpression('/buyback-manager:dev|dev-Dev-|\bDev-2\.0\b/i', $source, $file);
        }
    }

    public function test_user_facing_text_has_no_em_dashes(): void
    {
        $paths = array_merge([self::ROOT . 'README.md', self::ROOT . 'CHANGELOG.md'], self::files('.php', 'resources/lang/en'), self::files('.blade.php'));

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('—', file_get_contents($path), basename($path));
        }
    }

    /** The settings page says whether a Janice key is saved, and never prints it. */
    public function test_no_page_prints_the_janice_api_key(): void
    {
        $this->assertContains('janice_api_key', (new BuybackSetting())->getHidden());

        foreach (self::files('.blade.php') as $path) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));
            preg_match_all('/\{\{(.*?)\}\}|\{!!(.*?)!!\}/s', $source, $echoes);
            foreach (array_merge($echoes[1], $echoes[2]) as $echo) {
                if (str_contains($echo, 'janice_api_key')) {
                    $this->assertMatchesRegularExpression('/janice_api_key\s*\?/', $echo, basename($path) . " prints$echo");
                }
            }
        }
    }

    public function test_the_manifest_the_code_and_diagnostics_agree_on_manager_core(): void
    {
        $manifest = json_decode(file_get_contents(self::ROOT . 'ecosystem.json'), true);
        $integration = file_get_contents(self::SRC . 'Integrations/ManagerCoreIntegration.php');
        $this->assertMatchesRegularExpression("/MINIMUM_VERSION = '" . preg_quote($manifest['manager_core']['minimum'], '/') . "'/", $integration,
            'ecosystem.json and ManagerCoreIntegration name the same minimum Manager Core');

        // Diagnostics checks every capability Buyback calls, and only those.
        preg_match('/REQUIRED_MC_CAPABILITIES = \[(.*?)\];/s', file_get_contents(self::SRC . 'Http/Controllers/DiagnosticController.php'), $block);
        preg_match_all("/'([a-zA-Z]+\.[a-zA-Z]+)'/", $block[1] ?? '', $checked);
        $checked = $checked[1];
        sort($checked);
        $called = array_map(fn (string $call) => substr($call, strlen('ManagerCore:')), $manifest['capabilities']['calls']);
        sort($called);
        $this->assertSame($called, $checked);

        // Each is called through the bridge, and events go through Topics.
        $source = implode("\n", array_map('file_get_contents', self::files('.php')));
        foreach ($called as $capability) {
            $this->assertMatchesRegularExpression("/->call\(\s*'ManagerCore',\s*'" . preg_quote($capability, '/') . "'/", $source, $capability);
        }
        $this->assertStringNotContainsString("'events.publish'", $source);
    }
}
