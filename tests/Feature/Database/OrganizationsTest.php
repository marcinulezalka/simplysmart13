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

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Simplysmart\Polygon\Database\Seeders\OrganizationsSeeder;
use Simplysmart\Polygon\Database\Seeders\OrganizationUnitTypeSeeder;
use Simplysmart\Polygon\Models\Dictionaries\Country;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\OrganizationMember;
use Simplysmart\Polygon\Models\OrganizationUnitType;
use Simplysmart\Polygon\Models\PolygonAccount;
use Tests\TestCase;

/**
 * Organizacje z paczki polygon (docs/baza-danych.md §16): migracje bez danych, typy jednostek i organizacja hero
 * z seedera (`polygon:organizations:seed`, idempotentnie), drzewo, typ, kraj i członkostwo kont stref oraz kolor i nazwy.
 */
class OrganizationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_create_the_tables_without_data(): void
    {
        $this->assertSame(0, Organization::withTrashed()->count());
        $this->assertSame(0, OrganizationUnitType::withTrashed()->count());
    }

    public function test_seeder_writes_the_default_unit_types_in_seeder_slots(): void
    {
        $this->seed(OrganizationsSeeder::class);
        $types = OrganizationUnitType::query()->orderBy('sort_order')->get();
        $clinic = OrganizationUnitType::query()->where('code', 'poradnia')->sole();

        $this->assertSame(array_keys(OrganizationUnitTypeSeeder::DEFAULTS), $types->pluck('code')->all());
        $this->assertLessThan(1000, $types->max('id'));
        $this->assertSame(['Specjalność', 'Kod resortowy (część VIII)', 'Godziny przyjęć'], $clinic->fields());
        $this->assertSame('POR-1160', $clinic->displayNumber('1160'));
        $this->assertSame(['administracja', 'dzial'], OrganizationUnitType::query()->where('is_internal', true)->orderBy('sort_order')->pluck('code')->all());
    }

    public function test_seed_command_registers_the_hero_organization_once_and_restores_it(): void
    {
        $this->command('polygon:organizations:seed')->assertSuccessful();
        $this->command('polygon:organizations:seed')->assertSuccessful();
        $hero = Organization::query()->sole();
        $this->assertSame(Organization::HERO_ID, $hero->id);
        $this->assertSame(Organization::HERO_NAME, $hero->name);
        $this->assertTrue($hero->isHero());
        $this->assertTrue($hero->is_active);

        $hero->forceFill(['name' => 'Operator', 'is_active' => false])->save();
        $hero->delete();
        $this->command('polygon:organizations:seed')->assertSuccessful();

        $hero = Organization::query()->sole();
        $this->assertSame('Operator', $hero->name, 'Zmieniona nazwa zostaje.');
        $this->assertTrue($hero->is_active);
        $this->assertSame(count(OrganizationUnitTypeSeeder::DEFAULTS), OrganizationUnitType::query()->count());
        $this->assertSame([], Organization::query()->visible()->pluck('id')->all(), 'Organizacja hero nie jest widoczna we frontendzie.');
    }

    public function test_user_organizations_get_ids_from_1000_and_link_parent_type_and_country(): void
    {
        $country = (new Country())->forceFill(['name' => 'Polska', 'alpha_code_2' => 'PL']);
        $country->save();
        $this->seed(OrganizationsSeeder::class);
        $type = OrganizationUnitType::query()->where('code', 'oddzial')->sole();
        $company = $this->organization('Firma', ['country_code' => 'PL']);
        $department = $this->organization('Kadry', ['parent_id' => $company->id, 'is_department' => true, 'unit_type_id' => $type->id]);

        $this->assertGreaterThanOrEqual(1000, $company->id);
        $this->assertTrue($department->parent?->is($company));
        $this->assertTrue($department->unitType?->is($type));
        $this->assertTrue($company->country?->is($country));
        $this->assertTrue($department->isDepartment());
        $this->assertFalse($company->isDepartment());

        $type->forceDelete();
        $this->assertNull($department->refresh()->unit_type_id, 'Usunięcie typu zostawia jednostkę bez typu.');
    }

    public function test_zone_accounts_are_members_independent_of_roles(): void
    {
        $this->command('polygon:account:create', ['email' => 'jan@example.com', 'name' => 'Jan'])->assertSuccessful();
        $account = PolygonAccount::query()->sole();
        $organization = $this->organization('Firma');

        $account->organizations()->attach($organization);

        $member = OrganizationMember::query()->sole();
        $this->assertTrue($member->organization?->is($organization));
        $this->assertTrue($member->model?->is($account));
        $this->assertSame([$organization->id], $account->organizations()->pluck('organizations.id')->all());
        $this->assertSame(1, $organization->members()->count());
        $this->assertSame([], $account->getRoleNames()->all());

        $this->expectException(QueryException::class);
        $account->organizations()->attach($organization);
    }

    public function test_deleting_an_organization_removes_its_members(): void
    {
        $this->command('polygon:account:create', ['email' => 'jan@example.com', 'name' => 'Jan'])->assertSuccessful();
        $organization = $this->organization('Firma');
        PolygonAccount::query()->sole()->organizations()->attach($organization);

        $organization->forceDelete();

        $this->assertSame(0, OrganizationMember::query()->count());
    }

    public function test_names_address_icon_and_color_fall_back_to_defaults(): void
    {
        $company = $this->organization('Przychodnia Centrum', ['short_name' => 'Centrum', 'street' => 'Długa 1', 'postal_code' => '00-001', 'city' => 'Warszawa']);
        $department = $this->organization('Rejestracja', ['parent_id' => $company->id, 'is_department' => true, 'color' => '#123456', 'json_attributes' => ['icon' => 'cross']]);
        $plain = $this->organization('Bez danych', ['json_attributes' => ['icon' => 'nieznana']]);

        $this->assertSame('Centrum', $company->shortName());
        $this->assertSame('Bez danych', $plain->shortName());
        $this->assertSame('Centrum · Rejestracja', $department->contextName());
        $this->assertSame('Przychodnia Centrum', $company->contextName());
        $this->assertSame('Długa 1, 00-001 Warszawa', $company->address());
        $this->assertSame('', $plain->address());
        $this->assertSame('cross', $department->icon());
        $this->assertSame(Organization::ICONS[0], $plain->icon());
        $this->assertSame('#123456', $department->color());
        $this->assertSame(Organization::COLOR_PALETTE[$plain->id % count(Organization::COLOR_PALETTE)], $plain->color());
    }

}
