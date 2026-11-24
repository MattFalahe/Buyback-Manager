<?php

namespace BuybackManager\Tests\Unit;

use BuybackManager\Integrations\ManagerCoreIntegration;
use BuybackManager\Services\Ecosystem\PluginStatus;
use BuybackManager\Tests\Sqlite;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class PluginStatusTest extends TestCase
{
    private const NONE = [
        'manager-core' => false,
        'hr-manager' => false,
        'seat-broadcast' => false,
        'seat-connector' => false,
        'warlof-discord' => false,
    ];

    private static function labels(array $badges): array
    {
        return array_map(fn (array $b) => $b['label'] . '/' . $b['tone'] . ($b['pulse'] ? '/pulse' : ''), $badges);
    }

    public function test_the_manager_core_box_follows_manager_core(): void
    {
        $without = PluginStatus::resolve(self::NONE);
        $this->assertSame(['state' => 'red', 'banner' => 'Not installed', 'coming_soon' => false, 'installed' => false], $without['hub']);
        // Buyback Manager works without it, so it is never pressed as Recommended.
        $this->assertSame(['Not installed/warning'], self::labels($without['plugins']['manager-core']['badges']));

        $with = PluginStatus::resolve(['manager-core' => true] + self::NONE);
        $this->assertSame(['state' => 'on', 'banner' => null, 'coming_soon' => false, 'installed' => true], $with['hub']);
        $this->assertSame(['Installed/success'], self::labels($with['plugins']['manager-core']['badges']));
    }

    public function test_pricing_through_manager_core_is_yellow_without_it(): void
    {
        $without = PluginStatus::resolve(self::NONE)['features']['mc-pricing'];
        $this->assertSame(['yellow', 'Plugin not installed', 'Manager Core'], [$without['state'], $without['banner'], $without['needs']]);

        $with = PluginStatus::resolve(['manager-core' => true] + self::NONE)['features']['mc-pricing'];
        $this->assertSame(['on', null, 'Manager Core'], [$with['state'], $with['banner'], $with['using']]);
    }

    public function test_hr_credit_needs_manager_core_first_then_hr_manager(): void
    {
        $none = PluginStatus::resolve(self::NONE);
        $this->assertSame('red', $none['features']['hr-credit']['state']);
        $this->assertSame('Manager Core and HR Manager', $none['features']['hr-credit']['needs']);
        $this->assertSame(['Not installed/warning'], self::labels($none['plugins']['hr-manager']['badges']));

        $hrOnly = PluginStatus::resolve(['hr-manager' => true] + self::NONE);
        $this->assertSame('red', $hrOnly['features']['hr-credit']['state']);
        $this->assertSame('Manager Core', $hrOnly['features']['hr-credit']['needs']);
        $this->assertSame(['Installed/success', 'Needs Manager Core/danger'], self::labels($hrOnly['plugins']['hr-manager']['badges']));

        $mcOnly = PluginStatus::resolve(['manager-core' => true] + self::NONE);
        $this->assertSame('yellow', $mcOnly['features']['hr-credit']['state']);
        $this->assertSame('HR Manager', $mcOnly['features']['hr-credit']['needs']);

        $both = PluginStatus::resolve(['manager-core' => true, 'hr-manager' => true] + self::NONE);
        $this->assertSame('on', $both['features']['hr-credit']['state']);
        $this->assertSame('Manager Core and HR Manager', $both['features']['hr-credit']['using']);
        $this->assertSame(['Installed/success'], self::labels($both['plugins']['hr-manager']['badges']));
    }

    public function test_role_names_need_one_discord_plugin_and_say_which(): void
    {
        $none = PluginStatus::resolve(self::NONE);
        $this->assertSame('yellow', $none['features']['role-names']['state']);
        $this->assertSame('SeAT Broadcast or SeAT Connector', $none['features']['role-names']['needs']);
        $this->assertSame(['Not installed/warning'], self::labels($none['plugins']['seat-broadcast']['badges']));

        foreach (['seat-broadcast' => 'SeAT Broadcast', 'seat-connector' => 'SeAT Connector', 'warlof-discord' => 'Warlof Discord'] as $key => $name) {
            $status = PluginStatus::resolve([$key => true] + self::NONE);
            $this->assertSame('on', $status['features']['role-names']['state'], $key);
            $this->assertSame($name, $status['features']['role-names']['using'], $key);
        }
    }

    public function test_a_missing_discord_plugin_whose_job_another_already_does_is_grey(): void
    {
        foreach (['seat-broadcast', 'warlof-discord'] as $key) {
            $status = PluginStatus::resolve([$key => true] + self::NONE);
            $this->assertSame(['Not installed/secondary'], self::labels($status['plugins']['seat-connector']['badges']), $key);
        }

        $connector = PluginStatus::resolve(['seat-connector' => true] + self::NONE);
        $this->assertSame(['Not installed/secondary'], self::labels($connector['plugins']['seat-broadcast']['badges']));
        $this->assertSame(['Installed/success'], self::labels($connector['plugins']['seat-connector']['badges']));
    }

    public function test_warlof_is_found_but_not_listed(): void
    {
        $this->assertSame(
            ['manager-core', 'hr-manager', 'seat-broadcast', 'seat-connector'],
            array_keys(PluginStatus::resolve(self::NONE)['plugins'])
        );
    }

    public function test_a_connection_not_built_yet_is_coming_soon(): void
    {
        $plugins = ['manager-core' => ['ready' => false] + PluginStatus::PLUGINS['manager-core']] + PluginStatus::PLUGINS;
        $status = PluginStatus::resolve(['manager-core' => true] + self::NONE, $plugins);

        $this->assertSame(['red', 'Coming soon'], [$status['hub']['state'], $status['hub']['banner']]);
        $this->assertSame(['red', 'Coming soon'], [$status['features']['mc-pricing']['state'], $status['features']['mc-pricing']['banner']]);
        $this->assertSame(['Coming soon/danger/pulse', 'Installed/success'], self::labels($status['plugins']['manager-core']['badges']));
    }

    public function test_detect_finds_the_role_sources_by_the_tables_the_role_picker_reads(): void
    {
        Sqlite::migrate();

        $found = PluginStatus::detect();
        $this->assertFalse($found['seat-broadcast']);
        $this->assertFalse($found['seat-connector']);
        $this->assertFalse($found['warlof-discord']);
        $this->assertSame(ManagerCoreIntegration::isAvailable(), $found['manager-core']);
        $this->assertFalse($found['hr-manager']);

        // A discord_roles table without the column the picker reads is not a source.
        Schema::create('discord_roles', function (Blueprint $t) {
            $t->id();
        });
        $this->assertFalse(PluginStatus::detect()['seat-broadcast']);

        Schema::table('discord_roles', function (Blueprint $t) {
            $t->string('role_id')->nullable();
        });
        Schema::create('discord_connector_roles', function (Blueprint $t) {
            $t->id();
        });
        $found = PluginStatus::detect();
        $this->assertTrue($found['seat-broadcast']);
        $this->assertFalse($found['seat-connector']);
        $this->assertTrue($found['warlof-discord']);
    }

    public function test_manager_core_older_than_1_2_0_is_not_one_buyback_works_with(): void
    {
        $this->assertSame('1.2.0', ManagerCoreIntegration::MINIMUM_VERSION);
        foreach (['1.2.0' => true, '1.2.1' => true, '1.10.0' => true, '2.0.0' => true, '1.1.0' => false, '1.1.9' => false, '1.0.3' => false] as $version => $works) {
            $this->assertSame($works, ManagerCoreIntegration::supports($version), $version);
        }
        $this->assertFalse(ManagerCoreIntegration::supports(null), 'a Manager Core that does not say its version');
    }

    public function test_without_manager_core_nothing_reaches_for_it(): void
    {
        $this->assertFalse(ManagerCoreIntegration::isInstalled());
        $this->assertFalse(ManagerCoreIntegration::isAvailable());
        $this->assertFalse(ManagerCoreIntegration::isTooOld());
        $this->assertNull(ManagerCoreIntegration::version());
        $this->assertNull(ManagerCoreIntegration::bridge());
        $this->assertSame('Manager Core is not installed.', ManagerCoreIntegration::unavailableReason());
    }

    /**
     * A Manager Core stand-in can only be declared once per process, so each
     * version is tried in a process of its own.
     */
    public function test_an_installed_manager_core_counts_only_from_1_2_0(): void
    {
        $integration = var_export(realpath(__DIR__ . '/../../src/Integrations/ManagerCoreIntegration.php'), true);
        foreach (['1.1.0' => [false, true], '1.2.0' => [true, false], '1.3.0' => [true, false]] as $version => [$available, $tooOld]) {
            $script = tempnam(sys_get_temp_dir(), 'bbmc');
            file_put_contents($script, "<?php\nnamespace ManagerCore { class ManagerCore { const VERSION = '$version'; } }\n"
                . "namespace ManagerCore\\Services { class PluginBridge {} }\n"
                . "namespace { require $integration; \$i = BuybackManager\\Integrations\\ManagerCoreIntegration::class;\n"
                . "echo json_encode([\$i::isInstalled(), \$i::isAvailable(), \$i::isTooOld(), \$i::version(), \$i::unavailableReason(), \$i::isAvailable() ? 'skip' : \$i::bridge()]); }\n");
            $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($process);
            unlink($script);

            $this->assertSame([true, $available, $tooOld, $version,
                $tooOld ? "Manager Core $version is installed, but Buyback Manager needs 1.2.0 or later, so it works without it until Manager Core is updated." : null,
                $available ? 'skip' : null,
            ], json_decode($out, true), "Manager Core $version: $out $err");
        }
    }

    public function test_help_says_why_a_manager_core_too_old_to_use_counts_as_missing(): void
    {
        $status = PluginStatus::resolve(self::NONE);
        $this->assertSame($status, PluginStatus::withManagerCoreNote($status, null));

        $note = 'Manager Core 1.1.0 is installed, but Buyback Manager needs 1.2.0 or later, so it works without it until Manager Core is updated.';
        $old = PluginStatus::withManagerCoreNote($status, $note);
        $this->assertSame($note, $old['plugins']['manager-core']['note']);
        $this->assertSame($note, $old['hub']['note']);
        $this->assertSame('red', $old['hub']['state']);
        $this->assertArrayNotHasKey('note', $old['plugins']['hr-manager']);
    }
}
