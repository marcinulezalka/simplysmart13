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
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simplysmart\Polygon\Calendar\BookingStatusChange;
use Simplysmart\Polygon\Calendar\BookSlot;
use Simplysmart\Polygon\Calendar\RoomTaken;
use Simplysmart\Polygon\Calendar\DayBookings;
use Simplysmart\Polygon\Calendar\FreeSlots;
use Simplysmart\Polygon\Calendar\SaveSchedule;
use Simplysmart\Polygon\Calendar\ScheduleConflict;
use Simplysmart\Polygon\Calendar\ScheduleInput;
use Simplysmart\Polygon\Calendar\ScheduleRejected;
use Simplysmart\Polygon\Calendar\SlotUnavailable;
use Simplysmart\Polygon\Database\History;
use Simplysmart\Polygon\Database\IdSlots;
use Simplysmart\Polygon\Database\Seeders\CountriesSeeder;
use Simplysmart\Polygon\Database\Seeders\CurrenciesSeeder;
use Simplysmart\Polygon\Database\Seeders\HolidaysSeeder;
use Simplysmart\Polygon\Database\Seeders\OrganizationsSeeder;
use Simplysmart\Polygon\Enums\BookingStatus;
use Simplysmart\Polygon\Enums\RoomTakenBy;
use Simplysmart\Polygon\Enums\ResourceKind;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\OrganizationResource;
use Simplysmart\Polygon\Models\ResourceBooking;
use Simplysmart\Polygon\Models\ResourceSchedule;
use Simplysmart\Polygon\Models\ZoneAccount;
use Simplysmart\Polygon\Queries\Organizations\OrganizationUnits;
use Tests\Feature\Zones\ZoneTestCase;
use Tests\Fixtures\Zones\AlphaAccount;

/**
 * Kalendarz zasobów polygonu (`resource_schedules`, `resource_bookings`): standard tabel z historią, kolizje grafiku
 * (zasób, wykonawca, zasób obcej jednostki, okres ważności, błędny przedział), wolne sloty (miniony czas, zajęte przez
 * wykonawcę albo zasób, dzień wolny), rezerwacja zajętego slotu odrzucona, zmiany stanu, lista dnia, jednostki
 * organizacji oraz grafik z pliku instalacji (`organizations.<klucz>.schedule`, idempotentnie, błędne wpisy nic nie
 * zapisują). Wykonawcy to konta strefy testowej alpha.
 */
class CalendarTest extends ZoneTestCase
{
    /** Poniedziałek; grafik w poniedziałki 08:00–09:00, sloty po 15 minut. */
    private const string MONDAY = '2026-11-09';

    private const string KIND = 'test.visit';

    private Organization $seat;

    private Organization $clinic;

    private Organization $sibling;

    private OrganizationResource $room;

    private OrganizationResource $siblingRoom;

    private ZoneAccount $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::MONDAY . ' 07:00'));
        $this->seat = $this->organization('Siedziba');
        $this->clinic = $this->organization('Poradnia', ['parent_id' => $this->seat->id]);
        $this->sibling = $this->organization('Pracownia', ['parent_id' => $this->seat->id]);
        $this->room = $this->resource($this->seat, 'Gabinet 1', 'G1');
        $this->siblingRoom = $this->resource($this->sibling, 'Gabinet 2', 'G2');
        $this->doctor = $this->account(AlphaAccount::class, ['email' => 'lekarz@example.com', 'name' => 'Lekarz']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_calendar_tables_get_the_standard_with_history_and_user_ids(): void
    {
        $schedule = $this->save($this->input());
        $booking = $this->book('08:15');

        foreach ([ResourceSchedule::TABLE, ResourceBooking::TABLE] as $table) {
            $this->assertTrue(Schema::hasTable(History::tableFor($table)), $table);
            $this->assertSame(1, DB::table(History::tableFor($table))->where('action', 'insert')->count(), $table);
        }

        $this->assertSame([IdSlots::FIRST_USER_ID, IdSlots::FIRST_USER_ID], [$schedule->id, $booking->id]);
        $this->assertSame(['08:00–09:00', true], [$schedule->name, $schedule->performer?->is($this->doctor)]);
        $this->assertSame([BookingStatus::Booked, self::KIND, '08:15', '08:30'], [$booking->status, $booking->kind, $booking->starts_at->format('H:i'), $booking->ends_at->format('H:i')]);
    }

    public function test_schedule_conflicts(): void
    {
        $this->save($this->input());
        $other = $this->account(AlphaAccount::class, ['email' => 'drugi@example.com', 'name' => 'Drugi']);
        $ownRoom = $this->resource($this->clinic, 'Gabinet 3', 'G3');

        $this->assertConflicts([ScheduleConflict::ResourceTaken], $this->input(performer: $other, startsAt: '08:45', endsAt: '10:00'));
        $this->assertConflicts([ScheduleConflict::PerformerBusy], $this->input(resourceId: $ownRoom->id, startsAt: '08:30'));
        $this->assertConflicts([ScheduleConflict::ResourceUnavailable], $this->input(performer: $other, resourceId: $this->siblingRoom->id, weekday: 2));
        $this->assertConflicts([ScheduleConflict::InvalidRange], $this->input(weekday: 4, endsAt: '08:10'));

        $this->save($this->input(weekday: 5, validTo: '2026-12-31'));
        $this->save($this->input(performer: $other, weekday: 5, validFrom: '2027-01-01'));
        $this->assertSame(3, ResourceSchedule::query()->count(), 'W kolejnym okresie ważności zasób może przejąć inny wykonawca.');
    }

    public function test_free_slots_skip_past_taken_slots_and_days_off(): void
    {
        $this->seed([CurrenciesSeeder::class, CountriesSeeder::class, HolidaysSeeder::class]);
        $this->save($this->input());
        $this->save($this->input(weekday: 3));
        $slots = $this->app->make(FreeSlots::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::MONDAY . ' 08:20'));

        $this->assertSame(['08:30', '08:45'], $this->times(self::MONDAY));
        $this->book('08:45');
        $this->assertSame(['08:30'], $this->times(self::MONDAY));
        $this->assertSame([], $slots->on($this->clinic, $this->doctor, CarbonImmutable::parse('2026-11-11')), 'Święto Niepodległości to dzień wolny.');
        $this->assertCount(4, $slots->on($this->clinic, $this->doctor, CarbonImmutable::parse('2026-11-18')));
        $this->assertSame([], $slots->on($this->sibling, $this->doctor, CarbonImmutable::parse(self::MONDAY)));
        $this->assertSame([self::MONDAY, '2026-11-16', '2026-11-18'], array_map(static fn(CarbonImmutable $day): string => $day->toDateString(), $slots->days($this->clinic, $this->doctor, CarbonImmutable::parse(self::MONDAY), 10)));
    }

    public function test_a_resource_booked_by_another_performer_is_not_free_and_a_taken_slot_is_rejected(): void
    {
        $this->save($this->input());
        $other = $this->account(AlphaAccount::class, ['email' => 'drugi@example.com', 'name' => 'Drugi']);
        (new ResourceBooking())->forceFill([
            'name' => 'starsza rezerwacja', 'status' => BookingStatus::Booked, 'organization_id' => $this->sibling->id, 'resource_id' => $this->room->id,
            'performer_type' => $other->getMorphClass(), 'performer_id' => $other->id, 'kind' => self::KIND,
            'starts_at' => self::MONDAY . ' 08:15', 'ends_at' => self::MONDAY . ' 08:30',
        ])->save();

        $this->assertSame(['08:00', '08:30', '08:45'], $this->times(self::MONDAY));
        $this->book('08:30');
        $this->expectException(SlotUnavailable::class);
        $this->book('08:30');
    }

    public function test_an_overflow_booking_goes_to_the_end_of_the_block_without_taking_a_slot(): void
    {
        $this->save($this->input());
        $this->save($this->input(startsAt: '09:00', endsAt: '10:00'));
        $book = $this->app->make(BookSlot::class);
        $first = $book->bookOverflow($this->clinic, $this->doctor, CarbonImmutable::parse(self::MONDAY . ' 09:00'), self::KIND);
        $second = $book->bookOverflow($this->clinic, $this->doctor, CarbonImmutable::parse(self::MONDAY . ' 09:00'), self::KIND);

        $this->assertTrue($first->isOverflow());
        $this->assertTrue($first->starts_at->equalTo($first->ends_at));
        $this->assertSame(self::MONDAY . ' 09:00:00', $first->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->room->id, $second->resource_id);
        $this->assertFalse($this->book('08:15')->isOverflow());
        $this->assertSame(['08:00', '08:30', '08:45', '09:00', '09:15', '09:30', '09:45'], $this->times(self::MONDAY), 'Nadliczbowe nie zajmują slotów, także pierwszego slotu następnego bloku.');

        $this->expectException(SlotUnavailable::class);
        $book->bookOverflow($this->clinic, $this->doctor, CarbonImmutable::parse(self::MONDAY . ' 08:30'), self::KIND);
    }

    public function test_the_room_after_a_block_is_taken_by_another_schedule_or_free(): void
    {
        $block = $this->save($this->input());
        $slots = $this->app->make(FreeSlots::class);
        $free = $slots->roomTakenAfter($this->clinic, $block, CarbonImmutable::parse(self::MONDAY));
        $this->assertNull($free);

        $other = $this->account(AlphaAccount::class, ['email' => 'drugi@example.com', 'name' => 'Drugi']);
        $this->save($this->input($other, startsAt: '09:00', endsAt: '10:00'));
        $taken = $slots->roomTakenAfter($this->clinic, $block, CarbonImmutable::parse(self::MONDAY . ' 00:00'));

        $this->assertInstanceOf(RoomTaken::class, $taken);
        $this->assertSame(RoomTakenBy::Schedule, $taken->by);
        $this->assertSame('09:00', $taken->from->format('H:i'));
        $this->assertTrue($other->is($taken->performer));
        $this->assertNull($slots->roomTakenAfter($this->clinic, $block, CarbonImmutable::parse('2026-11-10')), 'Inny dzień tygodnia — gabinet wolny.');
    }

    public function test_an_overflow_booking_needs_a_block_ending_in_the_future(): void
    {
        $this->save($this->input());
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::MONDAY . ' 09:30'));

        $this->expectException(SlotUnavailable::class);
        $this->app->make(BookSlot::class)->bookOverflow($this->clinic, $this->doctor, CarbonImmutable::parse(self::MONDAY . ' 09:00'), self::KIND);
    }

    public function test_status_changes_and_the_day_list(): void
    {
        $this->save($this->input());
        $booking = $this->book('08:15');
        $change = $this->app->make(BookingStatusChange::class);

        $change->change($booking, BookingStatus::Confirmed, $this->doctor);
        $this->assertSame([BookingStatus::Confirmed, $this->doctor->id, self::MONDAY . ' 07:00'], [$booking->refresh()->status, $booking->confirmed_by_id, $booking->confirmed_at?->format('Y-m-d H:i')]);
        $this->assertSame([$booking->id], $this->app->make(DayBookings::class)->of([$this->clinic->id], CarbonImmutable::parse(self::MONDAY), self::KIND)->modelKeys());
        $this->assertSame([], $this->app->make(DayBookings::class)->of([$this->clinic->id], CarbonImmutable::parse(self::MONDAY), 'other.kind')->all());
        $change->change($booking, BookingStatus::Cancelled, $this->doctor);
        $this->assertContains('08:15', $this->times(self::MONDAY), 'Odwołana rezerwacja zwalnia termin.');

        $this->expectException(DomainException::class);
        $change->change($booking, BookingStatus::Confirmed, $this->doctor);
    }

    public function test_a_completed_booking_keeps_its_slot_and_is_final(): void
    {
        $this->save($this->input());
        $booking = $this->book('08:15');
        $change = $this->app->make(BookingStatusChange::class);

        $change->change($booking, BookingStatus::Confirmed, $this->doctor);
        $change->change($booking, BookingStatus::Completed, $this->doctor);
        $this->assertSame(BookingStatus::Completed, $booking->refresh()->status);
        $this->assertNotContains('08:15', $this->times(self::MONDAY), 'Wykonana wizyta dalej zajmuje termin.');
        $this->assertTrue(BookingStatus::Booked->allows(BookingStatus::Completed));
        $this->assertSame('wykonana', BookingStatus::Completed->label());

        $this->expectException(DomainException::class);
        $change->change($booking, BookingStatus::Cancelled, $this->doctor);
    }

    public function test_a_schedule_with_future_bookings_cannot_be_deleted(): void
    {
        $schedule = $this->save($this->input());
        $this->book('08:15');

        $this->expectException(ScheduleRejected::class);
        $this->app->make(SaveSchedule::class)->delete($schedule);
    }

    public function test_units_of_an_organization(): void
    {
        $this->organization('Zamknięta', ['parent_id' => $this->seat->id, 'is_active' => false]);

        $this->assertSame(['Siedziba', 'Poradnia', 'Pracownia'], $this->app->make(OrganizationUnits::class)->of($this->seat)->pluck('name')->all());
        $this->assertSame(['Poradnia'], $this->app->make(OrganizationUnits::class)->of($this->clinic)->pluck('name')->all());
    }

    public function test_install_files_create_the_schedule_once(): void
    {
        $this->seed(OrganizationsSeeder::class);
        config(['polygon.install.files' => [$this->file($this->scheduleFile())]]);

        $this->command('polygon:install')->expectsOutputToContain('Grafik założony: Anna · Poradnia ·')->assertSuccessful();
        $this->command('polygon:install')->expectsOutputToContain('Grafik już istnieje: Anna · Poradnia ·')->assertSuccessful();

        $schedule = ResourceSchedule::query()->sole();
        $this->assertSame([2, '13:30:00', '15:30:00', 15, CarbonImmutable::today()->toDateString()], [$schedule->weekday, $schedule->starts_at, $schedule->ends_at, $schedule->slot_minutes, $schedule->valid_from->toDateString()]);
        $this->assertSame(['Anna', 'Gabinet A'], [$schedule->performer?->name, $schedule->resource?->name]);
    }

    public function test_invalid_schedule_entries_write_nothing(): void
    {
        $this->seed(OrganizationsSeeder::class);
        $entry = ['resource' => 'gabinet-a', 'performer' => ['zone' => 'alpha', 'email' => 'anna@example.com'], 'weekday' => 2, 'from' => '13:30', 'to' => '15:30', 'slot' => 15];
        $broken = [
            'unknown resource' => [['resource' => 'missing'] + $entry],
            'unknown account' => [['performer' => ['zone' => 'alpha', 'email' => 'nikt@example.com']] + $entry],
            'unknown zone' => [['performer' => ['zone' => 'missing', 'email' => 'anna@example.com']] + $entry],
            'bad weekday' => [['weekday' => 8] + $entry],
            'reversed hours' => [['from' => '15:30', 'to' => '13:30'] + $entry],
            'unknown field' => [['room' => 'x'] + $entry],
            'collision' => [$entry, ['from' => '14:00', 'to' => '16:00'] + $entry],
        ];

        foreach ($broken as $case => $schedule) {
            config(['polygon.install.files' => [$this->file($this->scheduleFile($schedule))]]);

            $this->command('polygon:install')->assertFailed();

            $this->assertSame(0, ResourceSchedule::query()->count(), $case);
            $this->assertSame(0, OrganizationResource::query()->where('name', 'Gabinet A')->count(), $case);
        }
    }

    /**
     * Plik: siedziba z gabinetem A, poradnia pod nią z grafikiem konta Anna (strefa alpha) we wtorki 13:30–15:30.
     *
     * @param list<array<string, mixed>>|null $schedule
     *
     * @return array<string, mixed>
     */
    private function scheduleFile(?array $schedule = null): array
    {
        return [
            'organizations' => [
                'seat' => ['name' => 'Siedziba', 'resources' => [['key' => 'gabinet-a', 'name' => 'Gabinet A', 'code' => 'GA']]],
                'clinic' => ['name' => 'Poradnia', 'parent' => 'seat', 'schedule' => $schedule ?? [
                    ['resource' => 'gabinet-a', 'performer' => ['zone' => 'alpha', 'email' => 'Anna@Example.com'], 'weekday' => 2, 'from' => '13:30', 'to' => '15:30', 'slot' => 15],
                ]],
            ],
            'zones' => ['alpha' => ['accounts' => [['email' => 'anna@example.com', 'name' => 'Anna', 'organizations' => ['clinic' => []]]]]],
        ];
    }

    /**
     * @param list<ScheduleConflict> $expected
     */
    private function assertConflicts(array $expected, ScheduleInput $input): void
    {
        try {
            $this->save($input);
            $this->fail('Grafik powinien zostać odrzucony.');
        } catch (ScheduleRejected $rejected) {
            $this->assertSame($expected, $rejected->conflicts);
        }
    }

    private function input(?ZoneAccount $performer = null, ?int $resourceId = null, int $weekday = 1, string $startsAt = '08:00', string $endsAt = '09:00', string $validFrom = self::MONDAY, ?string $validTo = null): ScheduleInput
    {
        return new ScheduleInput($this->clinic->id, $resourceId ?? $this->room->id, $performer ?? $this->doctor, $weekday, $startsAt, $endsAt, 15, CarbonImmutable::parse($validFrom), $validTo === null ? null : CarbonImmutable::parse($validTo));
    }

    private function save(ScheduleInput $input): ResourceSchedule
    {
        return $this->app->make(SaveSchedule::class)->save($input);
    }

    private function book(string $time): ResourceBooking
    {
        return $this->app->make(BookSlot::class)->book($this->clinic, $this->doctor, CarbonImmutable::parse(self::MONDAY . ' ' . $time), self::KIND);
    }

    /**
     * @return list<string>
     */
    private function times(string $day): array
    {
        return array_map(static fn($slot): string => $slot->startsAt->format('H:i'), $this->app->make(FreeSlots::class)->on($this->clinic, $this->doctor, CarbonImmutable::parse($day)));
    }

    private function resource(Organization $organization, string $name, string $code): OrganizationResource
    {
        $resource = new OrganizationResource();
        $resource->forceFill(['organization_id' => $organization->id, 'name' => $name, 'code' => $code, 'kind' => ResourceKind::Room, 'is_active' => true])->save();

        return $resource;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function file(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'install');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

        return $path;
    }
}
