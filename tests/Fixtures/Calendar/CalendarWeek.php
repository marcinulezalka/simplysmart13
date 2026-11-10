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

namespace Tests\Fixtures\Calendar;

use Carbon\CarbonImmutable;

/**
 * Tydzień testów kalendarza (grafik, rezerwacje, najem): wspólne dni i chwile w nich. Zegar testu ustawiony przez
 * CarbonImmutable::setTestNow() zeruje Laravel po każdym teście.
 */
final class CalendarWeek
{
    /** Poniedziałek tygodnia testowego. */
    public const string MONDAY = '2026-11-09';

    /** Wtorek tygodnia testowego. */
    public const string TUESDAY = '2026-11-10';

    /**
     * Chwila „GG:MM” w dniu tygodnia testowego (domyślnie w poniedziałek).
     */
    public static function at(string $time, string $day = self::MONDAY): CarbonImmutable
    {
        return CarbonImmutable::parse($day . ' ' . $time);
    }
}
