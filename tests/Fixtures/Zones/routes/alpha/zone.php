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

use Illuminate\Support\Facades\Route;
use Simplysmart\Polygon\Contracts\CurrentOrganization;
use Simplysmart\Polygon\Organizations\OrganizationContext;
use Simplysmart\Polygon\Zones\ZonePermission;

Route::get('reports', fn(): string => 'reports')->middleware(ZonePermission::middleware('reports.view'))->name('reports');
Route::get('desk', fn(): string => 'desk')->middleware(ZonePermission::middleware('desk.manage', 'desk.admit'))->name('desk');
Route::get('organization', fn(CurrentOrganization $organization): array => [
    'id' => $organization->id(),
    'visible' => $organization->visible()?->name,
    'hero' => $organization->isHero(),
    'contexts' => array_map(static fn(OrganizationContext $context): array => [$context->organization->name, $context->current], $organization->contexts()),
])->name('organization');
