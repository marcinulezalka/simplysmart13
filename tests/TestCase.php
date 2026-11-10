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

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\PendingCommand;
use Simplysmart\Polygon\Database\ColumnNames;
use Simplysmart\Polygon\Enums\ResourceKind;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\OrganizationResource;
use Simplysmart\Polygon\Models\Permissions\Permission;
use Simplysmart\Polygon\Models\Permissions\Role;
use Simplysmart\Polygon\Permissions\PermissionTables;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pliki instalacji zapisane przez installFile(), usuwane w tearDown().
     *
     * @var list<string>
     */
    private array $installFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->installFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->installFiles = [];

        parent::tearDown();
    }

    /**
     * Komenda artisan z asercjami PendingCommand (np. expectsOutput()); typ dla PHPStan.
     *
     * @param array<string, mixed> $arguments
     */
    protected function command(string $name, array $arguments = []): PendingCommand
    {
        $command = $this->artisan($name, $arguments);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /**
     * Rola spatie (model paczki polygon) w guardzie, zapisana przez model (token, checksum, historia).
     */
    protected function role(string $name, string $guard): Role
    {
        $role = new Role();
        $role->forceFill([ColumnNames::NAME => $name, PermissionTables::GUARD_NAME => $guard])->save();

        return $role;
    }

    /**
     * Aktywna organizacja (model paczki polygon), zapisana przez model (token, checksum, historia, id od 1000).
     *
     * @param array<string, mixed> $attributes
     */
    protected function organization(string $name, array $attributes = []): Organization
    {
        $organization = new Organization();
        $organization->forceFill([ColumnNames::NAME => $name, ColumnNames::IS_ACTIVE => true, ...$attributes])->save();

        return $organization;
    }

    /**
     * Uprawnienie spatie (model paczki polygon) w guardzie, zapisane przez model (token, checksum, historia).
     */
    protected function permission(string $name, string $guard): Permission
    {
        $permission = new Permission();
        $permission->forceFill([ColumnNames::NAME => $name, PermissionTables::GUARD_NAME => $guard])->save();

        return $permission;
    }

    /**
     * Aktywny zasób organizacji (model paczki polygon), zapisany przez model (token, checksum, historia).
     */
    protected function organizationResource(Organization $organization, string $name, ?string $code, ResourceKind $kind = ResourceKind::Room): OrganizationResource
    {
        $resource = new OrganizationResource();
        $resource->forceFill([
            Organization::FOREIGN_KEY => $organization->id,
            ColumnNames::NAME => $name,
            OrganizationResource::CODE => $code,
            OrganizationResource::KIND => $kind,
            ColumnNames::IS_ACTIVE => true,
        ])->save();

        return $resource;
    }

    /**
     * Plik instalacji JSON (format polygon `docs/instalacja.md`) w katalogu tymczasowym, usuwany po teście.
     *
     * @param array<string, mixed> $data
     */
    protected function installFile(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'install');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->installFiles[] = $path;

        return $path;
    }
}
