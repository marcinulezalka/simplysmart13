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

namespace Tests\Feature\Organizations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simplysmart\Polygon\Database\History;
use Simplysmart\Polygon\Database\IdSlots;
use Simplysmart\Polygon\Database\Seeders\OrganizationsSeeder;
use Simplysmart\Polygon\Enums\ResourceKind;
use Simplysmart\Polygon\Install\OrganizationInstaller;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\OrganizationResource;
use Simplysmart\Polygon\Queries\Organizations\OrganizationResources;
use Tests\TestCase;

/**
 * Zasoby organizacji z paczki polygon (`organization_resources`): standard tabeli danych z historią i pulą id, unikalny
 * kod w organizacji, dostęp jednostki do zasobów swoich i przodków (bez zasobów sąsiednich jednostek i nieaktywnych)
 * oraz zasoby w plikach instalacji — zakładane tylko brakujące, a błędne pliki niczego nie zapisują.
 */
class OrganizationResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_resources_get_the_standard_table_with_history_and_user_ids(): void
    {
        $resource = $this->organizationResource($this->organization('Siedziba'), 'Gabinet 1', 'G1');

        $this->assertTrue(Schema::hasTable(History::tableFor(OrganizationResource::TABLE)));
        $this->assertSame(IdSlots::FIRST_USER_ID, $resource->id);
        $this->assertSame(ResourceKind::Room, $resource->kind);
        $this->assertSame('G1 · Gabinet 1', $resource->label());
        $this->assertSame(1, DB::table(History::tableFor(OrganizationResource::TABLE))->where('action', 'insert')->count());
    }

    public function test_the_code_is_unique_in_an_organization_only(): void
    {
        $this->organizationResource($this->organization('A'), 'Gabinet', 'G1');
        $this->organizationResource($other = $this->organization('B'), 'Gabinet', 'G1');

        $this->expectException(QueryException::class);

        $this->organizationResource($other, 'Drugi', 'G1');
    }

    public function test_a_unit_uses_its_own_and_its_ancestors_active_resources(): void
    {
        $seat = $this->organization('Siedziba');
        $clinic = $this->organization('Poradnia', ['parent_id' => $seat->id]);
        $sibling = $this->organization('Inna poradnia', ['parent_id' => $seat->id]);
        $room = $this->organizationResource($seat, 'Gabinet 1', 'G1');
        $own = $this->organizationResource($clinic, 'Gabinet 2', 'G2');
        $this->organizationResource($clinic, 'Audiometr', 'A1', ResourceKind::Equipment);
        $this->organizationResource($sibling, 'Gabinet sąsiada', 'S1');
        $this->organizationResource($seat, 'Zamknięty', 'Z1')->forceFill(['is_active' => false])->save();
        $resources = $this->app->make(OrganizationResources::class);

        $this->assertSame(['Gabinet 1', 'Gabinet 2'], $resources->available($clinic, ResourceKind::Room)->pluck('name')->all());
        $this->assertSame(['Gabinet 1'], $resources->available($seat, ResourceKind::Room)->pluck('name')->all(), 'Siedziba nie widzi zasobów jednostek podrzędnych.');
        $this->assertCount(3, $resources->available($clinic));
        $this->assertSame($room->id, $resources->findByToken($clinic, $room->token, ResourceKind::Room)?->id);
        $this->assertTrue($resources->includes($clinic, $own->id, ResourceKind::Room));
        $this->assertFalse($resources->includes($sibling, $own->id), 'Zasób sąsiedniej jednostki jest niedostępny.');
        $this->assertSame([$clinic->id, $seat->id], $resources->lineage($clinic));
    }

    public function test_install_files_create_missing_resources_once(): void
    {
        $this->seed(OrganizationsSeeder::class);
        config(['polygon.install.files' => [$this->installFile($this->resourcesFile())]]);

        $this->command('polygon:install')->expectsOutput('Zasób założony: Gabinet 1.')->expectsOutput('Zasób założony: Audiometr.')->assertSuccessful();
        $this->installedResource('gabinet-1')->forceFill(['name' => 'Zmieniony w aplikacji'])->save();
        $this->command('polygon:install')->expectsOutput('Zasób już istnieje: Zmieniony w aplikacji (bez zmian).')->assertSuccessful();

        $room = $this->installedResource('gabinet-1');
        $this->assertSame([ResourceKind::Room, 'G1', 'Zmieniony w aplikacji'], [$room->kind, $room->code, $room->name]);
        $this->assertSame(ResourceKind::Equipment, $this->installedResource('audiometr')->kind);
        $this->assertSame(2, OrganizationResource::query()->count());
        $this->assertSame('Siedziba', $room->organization?->name);
    }

    public function test_invalid_resources_write_nothing(): void
    {
        $this->seed(OrganizationsSeeder::class);
        $organizations = Organization::query()->count();
        $broken = [
            'not a list' => ['gabinet-1' => ['name' => 'G']],
            'missing key' => [['name' => 'Gabinet']],
            'missing name' => [['key' => 'gabinet-1']],
            'unknown kind' => [['key' => 'gabinet-1', 'name' => 'G', 'kind' => 'car']],
            'unknown field' => [['key' => 'gabinet-1', 'name' => 'G', 'floor' => 2]],
            'repeated key' => [['key' => 'gabinet-1', 'name' => 'A'], ['key' => 'gabinet-1', 'name' => 'B']],
            'repeated code' => [['key' => 'a', 'name' => 'A', 'code' => 'G1'], ['key' => 'b', 'name' => 'B', 'code' => 'G1']],
            'too long code' => [['key' => 'a', 'name' => 'A', 'code' => str_repeat('X', OrganizationResource::CODE_MAX_LENGTH + 1)]],
        ];

        foreach ($broken as $case => $resources) {
            config(['polygon.install.files' => [$this->installFile(['organizations' => ['seat' => ['name' => 'Siedziba', 'resources' => $resources]]])]]);

            $this->command('polygon:install')->assertFailed();

            $this->assertSame(0, OrganizationResource::query()->count(), $case);
            $this->assertSame($organizations, Organization::query()->count(), $case);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resourcesFile(): array
    {
        return ['organizations' => [
            'seat' => ['name' => 'Siedziba', 'resources' => [['key' => 'gabinet-1', 'name' => 'Gabinet 1', 'code' => 'G1']]],
            'clinic' => ['name' => 'Poradnia', 'parent' => 'seat', 'resources' => [['key' => 'audiometr', 'name' => 'Audiometr', 'kind' => 'equipment']]],
        ]];
    }

    private function installedResource(string $key): OrganizationResource
    {
        return OrganizationResource::query()->where('json_attributes->' . OrganizationInstaller::INSTALL_KEY, $key)->sole();
    }
}
