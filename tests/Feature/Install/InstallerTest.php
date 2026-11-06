<?php

declare(strict_types=1);

/**
 * This file is part of simplysmart/simplysmart13.
 *
 * Copyright (c) 2026 Marcin Ulezalka <marcin.ulezalka@simplysmart.pl>
 *
 * Licensed under the Simplysmart Restricted Source License 1.0.
 * See the LICENSE file distributed with this source code.
 */

namespace Tests\Feature\Install;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Simplysmart\Polygon\Database\IdSlots;
use Simplysmart\Polygon\Install\InstallFileParser;
use Simplysmart\Polygon\Install\Installer;
use Simplysmart\Polygon\Install\InvalidInstallFile;
use Simplysmart\Polygon\Models\Permissions\Permission;
use Simplysmart\Polygon\Models\Permissions\Role;
use Simplysmart\Polygon\Models\PolygonAccount;
use Simplysmart\Polygon\Zones\PolygonPermission;
use Simplysmart\Polygon\Permissions\BaseRole;
use Simplysmart\Polygon\Zones\PolygonZone;
use Tests\TestCase;

/**
 * Pliki instalacji JSON (Install\*): domyślny plik paczki zakłada uprawnienia i role jak strefa administratorów admiro
 * (support, owner) na katalogu ról każdej strefy (guest, support, owner, hero — root ze wszystkimi uprawnieniami, także
 * dodanymi przez projekt — oraz registration, doctor, accountant, analyst, manager) i konto support z losowym
 * id poniżej 1000; enumy BaseRole/PolygonPermission są zgodne z plikiem i mają etykiety pl/en; plik projektu (POLYGON_INSTALL_FILES) dokłada role i konta; błędny plik niczego nie zapisuje;
 * ponowna instalacja nic nie dubluje.
 */
class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private const string SUPPORT = 'support@simplysmart.pl';

    public function test_package_file_creates_roles_and_the_support_account_with_a_seed_id(): void
    {
        $this->command('polygon:install')->expectsOutput('Konto założone: ' . self::SUPPORT . ' (PIN przy pierwszym logowaniu).')->assertSuccessful();

        $support = PolygonAccount::query()->sole();
        $this->assertSame(self::SUPPORT, $support->emailAddress());
        $this->assertLessThan(IdSlots::FIRST_USER_ID, $support->id);
        $this->assertTrue($support->hasRole('hero'));
        $this->assertTrue($support->hasPermissionTo('system.maintenance'));
        $this->assertSame(['accountant', 'analyst', 'doctor', 'guest', 'hero', 'manager', 'owner', 'registration', 'support'], Role::query()->where('guard_name', PolygonZone::GUARD)->orderBy('name')->pluck('name')->all());
        $this->assertFalse(Role::findByName('owner', PolygonZone::GUARD)->hasPermissionTo('heroes.manage'));
        $this->assertSame([], Role::findByName('guest', PolygonZone::GUARD)->permissions->pluck('name')->all());
    }

    public function test_running_again_changes_nothing(): void
    {
        $this->command('polygon:install')->assertSuccessful();
        $counts = $this->counts();

        $this->command('polygon:install')->expectsOutput('Konto już istnieje: ' . self::SUPPORT . ' (role uzupełnione).')->assertSuccessful();

        $this->assertSame($counts, $this->counts());
    }

    public function test_project_file_adds_its_roles_and_accounts(): void
    {
        $file = $this->file(['zones' => ['polygon' => [
            'permissions' => ['reports.view'],
            'roles' => ['analyst' => ['permissions' => ['reports.view']], 'lead' => ['inherits' => ['analyst'], 'permissions' => []]],
            'accounts' => [['email' => 'Anna@Example.com', 'name' => 'Anna Nowak', 'roles' => ['lead']], ['email' => 'root@example.com', 'name' => 'Root', 'roles' => ['hero']]],
        ]]]);
        config(['polygon.install.files' => [$file]]);

        $this->command('polygon:install')->assertSuccessful();

        $anna = PolygonAccount::query()->where('id', '>=', IdSlots::FIRST_USER_ID)->get()->sole(static fn(PolygonAccount $account): bool => $account->emailAddress() === 'anna@example.com');
        $this->assertSame('anna@example.com', $anna->emailAddress());
        $this->assertTrue($anna->hasPermissionTo('reports.view'));
        $this->assertTrue(Role::findByName(BaseRole::Hero->value, PolygonZone::GUARD)->hasPermissionTo('reports.view'), 'Root dostaje też uprawnienia z plików projektu.');
        $this->assertSame(3, PolygonAccount::query()->count());
        $this->assertTrue(PolygonAccount::query()->where('id', '>=', IdSlots::FIRST_USER_ID)->get()->contains(static fn(PolygonAccount $account): bool => $account->hasRole('hero')), 'Konto projektu może dostać rolę z pliku paczki.');
    }

    public function test_account_with_an_undefined_role_writes_nothing(): void
    {
        config(['polygon.install.files' => [$this->file(['zones' => ['polygon' => ['accounts' => [['email' => 'x@example.com', 'name' => 'X', 'roles' => ['missing']]]]]])]]);

        $this->command('polygon:install')->assertFailed();

        $this->assertSame([0, 0, 0], $this->counts());
    }

    public function test_invalid_file_writes_nothing(): void
    {
        config(['polygon.install.files' => [$this->file(['zones' => ['polygon' => ['roles' => ['broken' => ['permissions' => ['missing.permission']]]]]])]]);

        $this->command('polygon:install')->assertFailed();

        $this->assertSame([0, 0, 0], $this->counts());
    }

    public function test_parser_rejects_unknown_zones_cycles_and_bad_accounts(): void
    {
        $parser = $this->app->make(InstallFileParser::class);
        $files = [
            ['zones' => ['shop' => []]],
            ['zones' => ['polygon' => ['roles' => ['a' => ['inherits' => ['b']], 'b' => ['inherits' => ['a']]]]]],
            ['zones' => ['polygon' => ['accounts' => [['email' => 'nie-adres', 'name' => 'X']]]]],
            ['zones' => ['polygon' => ['accounts' => [['email' => 'a@example.com', 'name' => 'X', 'id' => 5]]]]],
            ['zones' => ['polygon' => ['permissions' => ['a.b'], 'roles' => ['hero' => ['permissions' => ['a.b']]]]]],
            [],
            ['roles' => ['nurse' => ['permissions' => ['a.b']]]],
            ['roles' => ['hero' => ['permissions' => []]]],
            ['roles' => ['nurse' => ['permissions' => [], 'inherits' => ['guest']]]],
        ];

        foreach ($files as $data) {
            try {
                $parser->parse($this->file($data));
                $this->fail('Plik powinien zostać odrzucony: ' . json_encode($data));
            } catch (InvalidInstallFile) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_catalog_roles_reach_every_zone_and_the_zone_sets_their_permissions(): void
    {
        $file = $this->file([
            'roles' => ['nurse' => ['permissions' => []], 'auditor' => ['permissions' => '*']],
            'zones' => ['polygon' => ['permissions' => ['reports.view'], 'roles' => ['nurse' => ['permissions' => ['reports.view']]]]],
        ]);
        config(['polygon.install.files' => [$file]]);

        $this->command('polygon:install')->assertSuccessful();

        $this->assertSame(['reports.view'], Role::findByName('nurse', PolygonZone::GUARD)->permissions->pluck('name')->all(), 'Rola strefy nadpisuje uprawnienia roli katalogu.');
        $this->assertTrue(Role::findByName('auditor', PolygonZone::GUARD)->hasPermissionTo('system.maintenance'), '"*" w katalogu = wszystkie uprawnienia strefy.');
        $this->assertSame([], Role::findByName(BaseRole::Doctor->value, PolygonZone::GUARD)->permissions->pluck('name')->all());
    }

    public function test_later_file_replaces_a_role(): void
    {
        config(['polygon.install.files' => [$this->file(['zones' => ['polygon' => ['roles' => ['owner' => ['permissions' => []]]]]])]]);
        $this->command('polygon:install')->assertSuccessful();

        $this->assertSame([], Role::findByName('owner', PolygonZone::GUARD)->permissions->pluck('name')->all(), 'Późniejszy plik zastępuje rolę.');
        $this->assertInstanceOf(Installer::class, $this->app->make(Installer::class));
    }

    public function test_enums_match_the_package_file_and_have_labels(): void
    {
        $file = $this->app->make(InstallFileParser::class)->parse(dirname(__DIR__, 3) . '/vendor/simplysmart/polygon/database/install/polygon.json');

        $this->assertEqualsCanonicalizing(array_column(PolygonPermission::cases(), 'value'), $file->zones[0]->permissions);
        $this->assertEqualsCanonicalizing(array_column(BaseRole::cases(), 'value'), array_keys($file->catalog));

        foreach (['pl', 'en'] as $locale) {
            $this->app->setLocale($locale);

            foreach (PolygonPermission::cases() as $permission) {
                $this->assertNotSame('permissions.polygon.' . $permission->value, $permission->label(), "{$locale}: {$permission->value}");
            }

            foreach (BaseRole::cases() as $role) {
                $this->assertNotSame('roles.' . $role->value, $role->label(), "{$locale}: {$role->value}");
            }
        }
    }

    /**
     * @return list<int>
     */
    private function counts(): array
    {
        return [Permission::query()->count(), Role::query()->count(), PolygonAccount::withTrashed()->count()];
    }


    /**
     * @param array<string, mixed> $data
     */
    private function file(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'install');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

        return $path;
    }
}
