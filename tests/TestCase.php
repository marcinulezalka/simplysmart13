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
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\Permissions\Role;
use Simplysmart\Polygon\Permissions\PermissionTables;

abstract class TestCase extends BaseTestCase
{
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
}
