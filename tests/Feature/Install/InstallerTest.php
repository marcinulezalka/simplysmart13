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
use Simplysmart\Polygon\Database\Seeders\OrganizationsSeeder;
use Simplysmart\Polygon\Install\Installer;
use Simplysmart\Polygon\Install\InstallFileParser;
use Simplysmart\Polygon\Install\InvalidInstallFile;
use Simplysmart\Polygon\Install\OrganizationInstaller;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\OrganizationMember;
use Simplysmart\Polygon\Models\Permissions\Permission;
use Simplysmart\Polygon\Models\Permissions\Role;
use Simplysmart\Polygon\Models\PolygonAccount;
use Simplysmart\Polygon\Organizations\DefaultOrganization;
use Simplysmart\Polygon\Permissions\BaseRole;
use Simplysmart\Polygon\Permissions\OrganizationRoles;
use Simplysmart\Polygon\Zones\PolygonPermission;
use Simplysmart\Polygon\Zones\PolygonZone;
use Tests\TestCase;

/**
 * Pliki instalacji JSON (Install\*): domyślny plik paczki zakłada uprawnienia i role strefy administratorów
 * (support, owner) na katalogu ról każdej strefy (guest, support, owner, hero — root ze wszystkimi uprawnieniami, także
 * dodanymi przez projekt — oraz registration, doctor, accountant, analyst, manager) i konto support z losowym
 * id poniżej 1000; enumy BaseRole/PolygonPermission są zgodne z plikiem i mają etykiety pl/en; plik projektu (POLYGON_INSTALL_FILES) dokłada role i konta; błędny plik niczego nie zapisuje;
 * ponowna instalacja nic nie dubluje. Organizacje z sekcji `organizations` (klucz, drzewo, typ jednostki, dane firmy)
 * powstają tylko brakujące, a konta z `organizations` zostają ich członkami (personelem) z rolami w każdej organizacji:
 * `roles` konta w każdej jego organizacji (bez organizacji i dla hero — globalnie) oraz role z mapy
 * `organizations: {klucz: [role]}` tylko w danej organizacji.
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
        $file = $this->installFile(['zones' => ['polygon' => [
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
        config(['polygon.install.files' => [$this->installFile(['zones' => ['polygon' => ['accounts' => [['email' => 'x@example.com', 'name' => 'X', 'roles' => ['missing']]]]]])]]);

        $this->command('polygon:install')->assertFailed();

        $this->assertSame([0, 0, 0], $this->counts());
    }

    public function test_invalid_file_writes_nothing(): void
    {
        config(['polygon.install.files' => [$this->installFile(['zones' => ['polygon' => ['roles' => ['broken' => ['permissions' => ['missing.permission']]]]]])]]);

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
            ['zones' => ['polygon' => ['accounts' => [['email' => 'a@example.com', 'name' => 'X', 'organizations' => ['clinic' => 'owner']]]]]],
            ['zones' => ['polygon' => ['accounts' => [['email' => 'a@example.com', 'name' => 'X', 'organizations' => ['Bad Key' => ['owner']]]]]]],
            ['zones' => ['polygon' => ['accounts' => [['email' => 'a@example.com', 'name' => 'X', 'organizations' => [Organization::HERO_KEY => ['owner']]]]]]],
        ];

        foreach ($files as $data) {
            try {
                $parser->parse($this->installFile($data));
                $this->fail('Plik powinien zostać odrzucony: ' . json_encode($data));
            } catch (InvalidInstallFile) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_catalog_roles_reach_every_zone_and_the_zone_sets_their_permissions(): void
    {
        $file = $this->installFile([
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
        config(['polygon.install.files' => [$this->installFile(['zones' => ['polygon' => ['roles' => ['owner' => ['permissions' => []]]]]])]]);
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

    public function test_organizations_are_created_as_a_tree_and_accounts_join_them_as_staff(): void
    {
        $this->seed(OrganizationsSeeder::class);
        config(['polygon.install.files' => [$this->installFile($this->organizationFile())]]);

        $this->command('polygon:install')
            ->expectsOutput('Organizacja założona: Centrum Medyczne.')
            ->expectsOutput('Organizacja założona: Rejestracja.')
            ->assertSuccessful();

        $clinic = $this->installed('clinic');
        $desk = $this->installed('clinic-desk');
        $this->assertSame(['Centrum Medyczne', 'CM', '1234567890', 'Warszawa', true], [$clinic->name, $clinic->short_name, $clinic->nip, $clinic->city, $clinic->is_active]);
        $this->assertSame('placowka', $clinic->unitType?->code);
        $this->assertSame($clinic->id, $desk->parent_id);
        $this->assertTrue($desk->is_department);
        $this->assertSame(['anna@example.com', 'jan@example.com'], $this->staff($clinic));
        $this->assertSame(['anna@example.com'], $this->staff($desk));
    }

    public function test_staff_roles_are_given_per_organization(): void
    {
        $this->seed(OrganizationsSeeder::class);
        $file = $this->organizationFile(extraAccount: ['email' => 'ewa@example.com', 'name' => 'Ewa Lis', 'organizations' => ['clinic' => ['owner'], 'clinic-desk' => ['doctor']]]);
        config(['polygon.install.files' => [$this->installFile($file)]]);

        $this->command('polygon:install')->assertSuccessful();

        $roles = $this->app->make(OrganizationRoles::class);
        $clinic = $this->installed('clinic')->id;
        $desk = $this->installed('clinic-desk')->id;
        $ewa = $this->zoneAccount('ewa@example.com');
        $this->assertSame([['owner'], ['doctor'], []], [$roles->names($ewa, $clinic), $roles->names($ewa, $desk), $roles->names($ewa, null)]);
        $anna = $this->zoneAccount('anna@example.com');
        $this->assertSame([['registration'], ['registration'], []], [$roles->names($anna, $clinic), $roles->names($anna, $desk), $roles->names($anna, null)], '`roles` konta z organizacjami trafiają do każdej z nich.');
        $this->assertSame(['ewa@example.com'], array_values(array_filter($this->staff($this->installed('clinic-desk')), static fn(string $email): bool => $email === 'ewa@example.com')));
        $this->assertSame(['hero'], $roles->names(PolygonAccount::query()->get()->sole(static fn(PolygonAccount $account): bool => $account->emailAddress() === self::SUPPORT), null), 'Hero ma role globalne.');
    }

    public function test_an_undefined_role_of_an_organization_writes_nothing(): void
    {
        $this->seed(OrganizationsSeeder::class);
        $organizations = Organization::query()->count();
        $file = $this->organizationFile(extraAccount: ['email' => 'ewa@example.com', 'name' => 'Ewa Lis', 'organizations' => ['clinic' => ['missing']]]);
        config(['polygon.install.files' => [$this->installFile($file)]]);

        $this->command('polygon:install')->expectsOutputToContain('has the undefined role [missing]')->assertFailed();

        $this->assertSame([0, 0, 0], $this->counts());
        $this->assertSame($organizations, Organization::query()->count());
    }

    public function test_running_again_keeps_organizations_and_adds_memberships_to_existing_accounts(): void
    {
        $this->seed(OrganizationsSeeder::class);
        config(['polygon.install.files' => [$this->installFile($this->organizationFile(secondAccountJoins: false))]]);
        $this->command('polygon:install')->assertSuccessful();
        $this->installed('clinic')->forceFill(['name' => 'Zmieniona w aplikacji'])->save();
        $members = OrganizationMember::query()->count();

        config(['polygon.install.files' => [$this->installFile($this->organizationFile())]]);
        $this->command('polygon:install')->expectsOutput('Organizacja już istnieje: Zmieniona w aplikacji (bez zmian).')->assertSuccessful();

        $this->assertSame(2, Organization::query()->where('json_attributes->' . OrganizationInstaller::INSTALL_KEY, '!=', '')->count());
        $this->assertSame('Zmieniona w aplikacji', $this->installed('clinic')->name);
        $this->assertSame($members + 1, OrganizationMember::query()->count(), 'Istniejące konto dostaje członkostwo z pliku.');
        $this->assertSame(['anna@example.com', 'jan@example.com'], $this->staff($this->installed('clinic')));
    }

    public function test_an_organization_can_sit_under_the_hidden_hero_organization(): void
    {
        $this->seed(OrganizationsSeeder::class);
        config(['polygon.install.files' => [$this->installFile(['organizations' => ['client' => ['name' => 'Klient', 'parent' => Organization::HERO_KEY]]])]]);

        $this->command('polygon:install')->expectsOutput('Organizacja założona: Klient.')->assertSuccessful();

        $client = $this->installed('client');
        $this->assertSame(Organization::HERO_ID, $client->parent_id);
        $this->assertSame([$client->id], Organization::query()->visible()->pluck('id')->all(), 'Frontend stref pokazuje klienta, a organizacji hero nie.');
    }

    public function test_a_hero_parent_needs_the_hero_organization(): void
    {
        config(['polygon.install.files' => [$this->installFile(['organizations' => ['client' => ['name' => 'Klient', 'parent' => Organization::HERO_KEY]]])]]);

        $this->command('polygon:install')->expectsOutputToContain('the hero organization does not exist')->assertFailed();

        $this->assertSame(0, Organization::query()->count());
    }

    public function test_invalid_organizations_write_nothing(): void
    {
        $this->seed(OrganizationsSeeder::class);
        $organizations = Organization::query()->count();
        $broken = [
            'undefined organization of an account' => ['zones' => ['polygon' => ['accounts' => [['email' => 'x@example.com', 'name' => 'X', 'organizations' => ['missing']]]]]],
            'undefined parent' => ['organizations' => ['a' => ['name' => 'A', 'parent' => 'missing']]],
            'cycle' => ['organizations' => ['a' => ['name' => 'A', 'parent' => 'b'], 'b' => ['name' => 'B', 'parent' => 'a']]],
            'unknown unit type' => ['organizations' => ['a' => ['name' => 'A', 'unit_type' => 'missing']], 'zones' => ['polygon' => ['accounts' => [['email' => 'x@example.com', 'name' => 'X', 'organizations' => ['a']]]]]],
            'unknown field' => ['organizations' => ['a' => ['name' => 'A', 'website' => 'x']]],
            'missing name' => ['organizations' => ['a' => ['city' => 'Kraków']]],
            'too long NIP' => ['organizations' => ['a' => ['name' => 'A', 'nip' => '12345678901']]],
            'bad key' => ['organizations' => ['Bad Key' => ['name' => 'A']]],
            'reserved hero key' => ['organizations' => [Organization::HERO_KEY => ['name' => 'Inna']]],
            'hero organization for an account' => ['zones' => ['polygon' => ['accounts' => [['email' => 'x@example.com', 'name' => 'X', 'organizations' => [Organization::HERO_KEY]]]]]],
        ];

        foreach ($broken as $case => $data) {
            config(['polygon.install.files' => [$this->installFile($data)]]);

            $this->command('polygon:install')->assertFailed();

            $this->assertSame($organizations, Organization::query()->count(), $case);
            $this->assertSame([0, 0, 0], $this->counts(), $case);
        }
    }

    public function test_attributes_reach_new_and_existing_organizations_and_accounts(): void
    {
        $this->seed(OrganizationsSeeder::class);
        config(['polygon.install.files' => [$this->installFile($this->attributesFile('000000001234', 'lekarz'))]]);

        $this->command('polygon:install')->assertSuccessful();

        $clinic = $this->installed('clinic');
        $this->assertSame(['000000001234', 'clinic'], [data_get($clinic->json_attributes, 'medical_registry.registry_book'), data_get($clinic->json_attributes, OrganizationInstaller::INSTALL_KEY)]);
        $this->assertSame(['pwz' => '3123456', 'title' => 'lekarz'], data_get($this->zoneAccount('ewa@example.com')->getAttribute('json_attributes'), 'doctor'));

        $clinic->forceFill(['json_attributes' => [...$clinic->json_attributes ?? [], 'icon' => 'heart']])->save();
        config(['polygon.install.files' => [$this->installFile($this->attributesFile('000000001235', 'lekarz dentysta'))]]);
        $this->command('polygon:install')->assertSuccessful();

        $clinic->refresh();
        $this->assertSame(['000000001235', 'heart', 'clinic'], [data_get($clinic->json_attributes, 'medical_registry.registry_book'), data_get($clinic->json_attributes, 'icon'), data_get($clinic->json_attributes, OrganizationInstaller::INSTALL_KEY)]);
        $this->assertSame('lekarz dentysta', data_get($this->zoneAccount('ewa@example.com')->getAttribute('json_attributes'), 'doctor.title'));
    }

    public function test_reserved_or_malformed_attributes_write_nothing(): void
    {
        $this->seed(OrganizationsSeeder::class);

        foreach ([['install_key' => 'x'], ['Bad-Key' => 1], ['deep' => ['a' => ['b' => ['c' => ['d' => 1]]]]]] as $attributes) {
            config(['polygon.install.files' => [$this->installFile($this->organizationFile(clinicAttributes: $attributes))]]);

            $this->command('polygon:install')->assertFailed();
            $this->assertSame(0, Organization::query()->where('name', 'Centrum Medyczne')->count());
        }
    }

    public function test_default_organization_from_the_file_is_set_once_and_the_own_choice_stays(): void
    {
        $this->seed(OrganizationsSeeder::class);
        $file = $this->organizationFile(extraAccount: ['email' => 'ewa@example.com', 'name' => 'Ewa Lis', 'organizations' => ['clinic' => [], 'clinic-desk' => ['registration']], 'defaults' => ['organization' => 'clinic-desk']]);
        config(['polygon.install.files' => [$this->installFile($file)]]);

        $this->command('polygon:install')->assertSuccessful();

        $ewa = $this->zoneAccount('ewa@example.com');
        $this->assertSame($this->installed('clinic-desk')->id, $ewa->defaultSetting(DefaultOrganization::SETTING));

        $this->app->make(DefaultOrganization::class)->save($ewa, $this->installed('clinic'));
        $this->command('polygon:install')->assertSuccessful();

        $this->assertSame($this->installed('clinic')->id, $ewa->refresh()->defaultSetting(DefaultOrganization::SETTING), 'Instalacja nie nadpisuje wyboru osoby.');
    }

    public function test_default_organization_outside_the_account_organizations_writes_nothing(): void
    {
        $this->seed(OrganizationsSeeder::class);

        foreach ([['organization' => 'clinic-desk'], ['organization' => 'unknown'], ['theme' => 'dark'], ['clinic']] as $defaults) {
            config(['polygon.install.files' => [$this->installFile($this->organizationFile(extraAccount: ['email' => 'ewa@example.com', 'name' => 'Ewa Lis', 'organizations' => ['clinic' => []], 'defaults' => $defaults]))]]);

            $this->command('polygon:install')->assertFailed();
            $this->assertSame(0, Organization::query()->where('name', 'Centrum Medyczne')->count());
        }
    }

    /**
     * Plik organizacji z księgą rejestrową placówki i kontem Ewy z tytułem lekarza w atrybutach.
     *
     * @return array<string, mixed>
     */
    private function attributesFile(string $registryBook, string $title): array
    {
        return $this->organizationFile(
            extraAccount: ['email' => 'ewa@example.com', 'name' => 'Ewa Lis', 'organizations' => ['clinic' => []], 'attributes' => ['doctor' => ['pwz' => '3123456', 'title' => $title]]],
            clinicAttributes: ['medical_registry' => ['registry_book' => $registryBook]],
        );
    }

    /**
     * Plik z placówką „Centrum Medyczne” i jej działem „Rejestracja” oraz dwoma kontami strefy polygon (personel).
     *
     * @param bool $secondAccountJoins Czy drugie konto (Jan) jest członkiem placówki.
     * @param array<string, mixed>|null $extraAccount Trzecie konto strefy polygon (np. z rolami per organizacja).
     * @param array<string, mixed>|null $clinicAttributes Atrybuty placówki (`attributes`).
     *
     * @return array<string, mixed>
     */
    private function organizationFile(bool $secondAccountJoins = true, ?array $extraAccount = null, ?array $clinicAttributes = null): array
    {
        return [
            'organizations' => [
                'clinic-desk' => ['name' => 'Rejestracja', 'parent' => 'clinic', 'unit_type' => 'rejestracja', 'is_department' => true],
                'clinic' => ['name' => 'Centrum Medyczne', 'short_name' => 'CM', 'unit_type' => 'placowka', 'nip' => '1234567890', 'city' => 'Warszawa', ...($clinicAttributes === null ? [] : ['attributes' => $clinicAttributes])],
            ],
            'zones' => ['polygon' => ['accounts' => [
                ['email' => 'anna@example.com', 'name' => 'Anna Nowak', 'roles' => ['registration'], 'organizations' => ['clinic', 'clinic-desk']],
                ['email' => 'jan@example.com', 'name' => 'Jan Kowalski', 'roles' => ['doctor'], 'organizations' => $secondAccountJoins ? ['clinic'] : []],
                ...($extraAccount === null ? [] : [$extraAccount]),
            ]]],
        ];
    }

    private function zoneAccount(string $email): PolygonAccount
    {
        return PolygonAccount::query()->get()->sole(static fn(PolygonAccount $account): bool => $account->emailAddress() === $email);
    }

    private function installed(string $key): Organization
    {
        return Organization::query()->where('json_attributes->' . OrganizationInstaller::INSTALL_KEY, $key)->sole();
    }

    /**
     * Adresy kont strefy polygon będących członkami organizacji, posortowane.
     *
     * @return list<string>
     */
    private function staff(Organization $organization): array
    {
        $emails = OrganizationMember::query()->where(Organization::FOREIGN_KEY, $organization->id)->get()
            ->map(static fn(OrganizationMember $member): string => PolygonAccount::query()->findOrFail($member->model_id)->emailAddress())
            ->all();
        sort($emails);

        return $emails;
    }

    /**
     * @return list<int>
     */
    private function counts(): array
    {
        return [Permission::query()->count(), Role::query()->count(), PolygonAccount::withTrashed()->count()];
    }
}
