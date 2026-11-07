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

namespace Tests\Fixtures\Zones;

use Simplysmart\Polygon\Models\ZoneAccount;

/**
 * Konto strefy testowej gamma.
 */
class GammaAccount extends ZoneAccount
{
    protected $table = 'gamma_accounts';

    protected string $guard_name = 'gamma';
}
