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

namespace Tests\Fixtures\Database;

use Simplysmart\Polygon\Database\ColumnNames;
use Simplysmart\Polygon\Enums\Continent;
use Simplysmart\Polygon\Models\DataModel;

/**
 * Model testowy ze stanem dziedzinowym w `status` (własny cast enuma zamiast RecordStatus).
 */
class DomainStatusItem extends DataModel
{
    protected $table = DemoItem::TABLE;

    public function usesRecordStatus(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [ColumnNames::STATUS => Continent::class];
    }
}
