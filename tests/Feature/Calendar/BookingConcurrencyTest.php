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

namespace Tests\Feature\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Simplysmart\Polygon\Calendar\BookSlot;
use Simplysmart\Polygon\Calendar\SaveSchedule;
use Simplysmart\Polygon\Calendar\ScheduleInput;
use Simplysmart\Polygon\Calendar\SlotUnavailable;
use Simplysmart\Polygon\Enums\ResourceKind;
use Simplysmart\Polygon\Models\MainModel;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\OrganizationResource;
use Simplysmart\Polygon\Models\PolygonAccount;
use Simplysmart\Polygon\Models\ResourceBooking;
use Tests\TestCase;

/**
 * Równoległa rezerwacja tego samego slotu na prawdziwym MySQL (SQLite w pamięci nie blokuje wierszy): drugie
 * połączenie trzyma blokadę zasobu i niezatwierdzoną rezerwację tego slotu, więc rezerwacja z pierwszego połączenia
 * czeka na blokadę (przekroczenie innodb_lock_wait_timeout, a nie druga rezerwacja); po zatwierdzeniu tamtej ta sama
 * rezerwacja widzi zajęty termin (SlotUnavailable).
 *
 * Wymaga osobnej, pustej bazy MySQL (test robi w niej migrate:fresh) wskazanej zmiennymi środowiska:
 * POLYGON_TEST_MYSQL_DATABASE (wymagana), POLYGON_TEST_MYSQL_HOST, POLYGON_TEST_MYSQL_PORT, POLYGON_TEST_MYSQL_USERNAME,
 * POLYGON_TEST_MYSQL_PASSWORD. Bez nich test jest pomijany. Nigdy nie wskazuj bazy deweloperskiej ani serwera.
 */
class BookingConcurrencyTest extends TestCase
{
    private const string CONNECTION = 'polygon_concurrency';

    private const string SECOND = 'polygon_concurrency_second';

    private const string MONDAY = '2026-11-09';

    /** Kod MySQL przekroczenia czasu oczekiwania na blokadę. */
    private const int LOCK_WAIT_TIMEOUT = 1205;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('POLYGON_TEST_MYSQL_DATABASE');

        if (! is_string($database) || $database === '') {
            $this->markTestSkipped('Ustaw POLYGON_TEST_MYSQL_DATABASE (i dane dostępu) na pustą bazę MySQL do testu blokad.');
        }

        $connection = [
            'driver' => 'mysql',
            'host' => getenv('POLYGON_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('POLYGON_TEST_MYSQL_PORT') ?: '3306',
            'database' => $database,
            'username' => getenv('POLYGON_TEST_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('POLYGON_TEST_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
        config(['database.connections.' . self::CONNECTION => $connection, 'database.connections.' . self::SECOND => $connection, 'database.default' => self::CONNECTION]);
        DB::purge(self::CONNECTION);
        DB::purge(self::SECOND);
        $this->command('migrate:fresh', ['--database' => self::CONNECTION, '--force' => true])->assertSuccessful();
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::MONDAY . ' 07:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        if (config('database.connections.' . self::SECOND) !== null) {
            DB::connection(self::SECOND)->rollBack();
        }

        parent::tearDown();
    }

    public function test_a_parallel_booking_of_the_same_slot_waits_for_the_lock_and_is_then_rejected(): void
    {
        $clinic = $this->organization('Poradnia');
        $room = new OrganizationResource();
        $room->forceFill(['organization_id' => $clinic->id, 'name' => 'Gabinet 1', 'code' => 'G1', 'kind' => ResourceKind::Room, 'is_active' => true])->save();
        $this->command('polygon:account:create', ['email' => 'lekarz@example.com', 'name' => 'Lekarz'])->assertSuccessful();
        $performer = PolygonAccount::query()->sole();
        $this->app->make(SaveSchedule::class)->save(new ScheduleInput($clinic->id, $room->id, $performer, 1, '08:00', '09:00', 15, CarbonImmutable::parse(self::MONDAY)));

        $second = DB::connection(self::SECOND);
        $second->beginTransaction();
        $second->select('SELECT id FROM ' . OrganizationResource::TABLE . ' WHERE id = ? FOR UPDATE', [$room->id]);
        $second->table(ResourceBooking::TABLE)->insert([
            'token' => Str::random(MainModel::TOKEN_LENGTH), 'name' => 'równoległa rezerwacja', 'status' => 'booked', 'is_active' => true,
            'organization_id' => $clinic->id, 'resource_id' => $room->id, 'performer_type' => $performer->getMorphClass(), 'performer_id' => $performer->id,
            'kind' => 'test.visit', 'starts_at' => self::MONDAY . ' 08:15:00', 'ends_at' => self::MONDAY . ' 08:30:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection(self::CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->book($clinic, $performer);
            $this->fail('Rezerwacja nie może przejść, dopóki równoległa trzyma blokadę zasobu.');
        } catch (QueryException $waiting) {
            $this->assertSame(self::LOCK_WAIT_TIMEOUT, $waiting->errorInfo[1] ?? null, $waiting->getMessage());
        }

        $second->commit();
        $this->expectException(SlotUnavailable::class);

        try {
            $this->book($clinic, $performer);
        } finally {
            $this->assertSame(1, ResourceBooking::query()->count(), 'Termin zarezerwowany tylko raz.');
        }
    }

    private function book(Organization $clinic, PolygonAccount $performer): ResourceBooking
    {
        return $this->app->make(BookSlot::class)->book($clinic, $performer, CarbonImmutable::parse(self::MONDAY . ' 08:15'), 'test.visit');
    }
}
