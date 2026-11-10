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

namespace Tests\Feature\Leases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Simplysmart\Polygon\Calendar\BookSlot;
use Simplysmart\Polygon\Calendar\FreeSlots;
use Simplysmart\Polygon\Calendar\SaveSchedule;
use Simplysmart\Polygon\Calendar\ScheduleConflict;
use Simplysmart\Polygon\Calendar\ScheduleInput;
use Simplysmart\Polygon\Calendar\ScheduleRejected;
use Simplysmart\Polygon\Calendar\SlotUnavailable;
use Simplysmart\Polygon\Database\History;
use Simplysmart\Polygon\Database\IdSlots;
use Simplysmart\Polygon\Database\Seeders\OrganizationsSeeder;
use Simplysmart\Polygon\Enums\LeaseAccess;
use Simplysmart\Polygon\Enums\LeaseMode;
use Simplysmart\Polygon\Leases\LeaseConflict;
use Simplysmart\Polygon\Leases\LeaseInput;
use Simplysmart\Polygon\Leases\LeaseRejected;
use Simplysmart\Polygon\Leases\LeaseWindows;
use Simplysmart\Polygon\Leases\SaveLease;
use Simplysmart\Polygon\Leases\Wallboard;
use Simplysmart\Polygon\Leases\WallboardEntry;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\OrganizationResource;
use Simplysmart\Polygon\Models\ResourceBooking;
use Simplysmart\Polygon\Models\ResourceLease;
use Simplysmart\Polygon\Models\ResourceSchedule;
use Simplysmart\Polygon\Models\ZoneAccount;
use Simplysmart\Polygon\Queries\Leases\ResourceLeases;
use Simplysmart\Polygon\Queries\Organizations\OrganizationResources;
use Tests\Feature\Zones\ZoneTestCase;
use Tests\Fixtures\Calendar\CalendarWeek;
use Tests\Fixtures\Zones\AlphaAccount;

/**
 * Najem zasobu (`resource_leases`): standard tabeli z historią, okna z kilkoma przedziałami w dniu, odmowy umowy
 * (najemca w drzewie właściciela, brak kooperacji, zasób cudzy, nakładające się najmy, zajęte okno), grafik właściciela
 * w oknie (LeasedOut) i najemcy poza umową (OutsideLease), dwa bloki lekarza w jednym dniu, sloty i rezerwacja po
 * wypowiedzeniu, kanały właściciela według poziomu udostępnienia, publiczna tablica bez danych osób i umowa z pliku
 * instalacji. Lekarze to konta strefy testowej alpha.
 */
class ResourceLeaseTest extends ZoneTestCase
{
    private const string KIND = 'test.visit';

    private Organization $owner;

    private Organization $clinic;

    private Organization $lessee;

    private Organization $cooperation;

    private OrganizationResource $room;

    private ZoneAccount $doctor;

    private ZoneAccount $guest;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CalendarWeek::at('07:00'));
        $this->owner = $this->organization('Właściciel');
        $this->clinic = $this->organization('Poradnia', ['parent_id' => $this->owner->id]);
        $this->lessee = $this->organization('Najemca');
        $this->cooperation = $this->organization('Najemca — kooperacja', ['parent_id' => $this->lessee->id, Organization::IS_COOPERATION => true]);
        $this->room = $this->organizationResource($this->owner, 'Gabinet 3', 'G3');
        $this->doctor = $this->account(AlphaAccount::class, ['email' => 'lekarz@example.com', 'name' => 'Lekarz']);
        $this->guest = $this->account(AlphaAccount::class, ['email' => 'gosc@example.com', 'name' => 'Lekarz najemcy']);
    }

    public function test_the_lease_table_gets_the_standard_with_history_and_a_board_token(): void
    {
        $lease = $this->lease();

        $this->assertTrue(Schema::hasTable(History::tableFor(ResourceLease::TABLE)));
        $this->assertContains(Organization::IS_COOPERATION, $this->app->make(History::class)->trackedColumns(Organization::TABLE));
        $this->assertSame(IdSlots::FIRST_USER_ID, $lease->id);
        $this->assertSame(['UN/1', $this->owner->id, $this->lessee->id, $this->cooperation->id], [$lease->name, $lease->organization_id, $lease->lessee_organization_id, $lease->cooperation_organization_id]);
        $this->assertSame([[2, '08:00', '12:00'], [2, '18:00', '22:00']], $lease->refresh()->leaseWindows()->windows);
        $this->assertSame([LeaseAccess::Book, LeaseAccess::Wallboard], $lease->accessLevels());
        $this->assertSame(ResourceLease::WALLBOARD_TOKEN_LENGTH, strlen((string) $lease->wallboard_token));
        $this->assertTrue($lease->grants(LeaseAccess::View), 'Zapis wizyt daje też podgląd.');
    }

    public function test_windows_with_several_ranges_in_a_day(): void
    {
        $windows = new LeaseWindows([[2, '08:00', '12:00'], [2, '18:00', '22:00']]);

        $this->assertTrue($windows->covers(2, '08:00', '12:00'));
        $this->assertTrue($windows->covers(2, '18:30', '20:00'));
        $this->assertFalse($windows->covers(2, '11:00', '19:00'), 'Blok musi mieścić się w jednym oknie.');
        $this->assertFalse($windows->overlaps(2, '12:00', '18:00'));
        $this->assertTrue($windows->overlaps(2, '11:30', '12:30'));
        $this->assertFalse($windows->overlaps(3, '08:00', '12:00'));
        $this->assertTrue($windows->isValid());
        $this->assertFalse((new LeaseWindows([[2, '08:00', '12:00'], [2, '11:00', '13:00']]))->isValid());
        $this->assertFalse((new LeaseWindows([[8, '08:00', '12:00']]))->isValid());
        $this->assertTrue((new LeaseWindows())->covers(5, '00:00', '23:59'), 'Bez okien — cały zasób.');
    }

    public function test_lease_rejections(): void
    {
        $inTree = $this->organization('Dział kooperacji', ['parent_id' => $this->clinic->id, Organization::IS_COOPERATION => true]);
        $plain = $this->organization('Zwykła jednostka', ['parent_id' => $this->lessee->id]);
        $foreign = $this->organizationResource($this->lessee, 'Sala najemcy', 'S1');

        $this->assertLeaseConflicts([LeaseConflict::LesseeInOwnerTree], $this->input(lessee: $this->clinic, cooperation: $inTree));
        $this->assertLeaseConflicts([LeaseConflict::NotCooperation], $this->input(cooperation: $plain));
        $this->assertLeaseConflicts([LeaseConflict::ResourceNotOwned], $this->input(resourceId: $foreign->id));
        $this->assertLeaseConflicts([LeaseConflict::InvalidRange], $this->input(windows: [[2, '12:00', '08:00']]));

        $this->lease();
        $this->assertLeaseConflicts([LeaseConflict::Overlapping], $this->input(number: 'UN/2', windows: [[2, '11:00', '13:00']]));

        $this->schedule($this->clinic, $this->doctor, 3, '08:00', '12:00');
        $this->assertLeaseConflicts([LeaseConflict::ResourceScheduled], $this->input(number: 'UN/3', windows: [[3, '10:00', '14:00']]));
    }

    public function test_the_owner_cannot_schedule_in_leased_windows_and_the_lessee_only_inside_them(): void
    {
        $this->lease();

        $this->assertScheduleConflicts([ScheduleConflict::LeasedOut], new ScheduleInput($this->clinic->id, $this->room->id, $this->doctor, 2, '11:00', '13:00', 15, CarbonImmutable::parse(CalendarWeek::MONDAY)));
        $this->schedule($this->clinic, $this->doctor, 2, '12:00', '18:00');

        $this->schedule($this->cooperation, $this->guest, 2, '08:00', '12:00');
        $this->schedule($this->cooperation, $this->guest, 2, '18:00', '22:00');
        $this->assertSame(2, ResourceSchedule::query()->where('organization_id', $this->cooperation->id)->count(), 'Lekarz najemcy ma dwa bloki w jednym dniu z przerwą.');

        $this->assertScheduleConflicts([ScheduleConflict::OutsideLease], $this->cooperationInput(3, '08:00', '12:00'));
        $this->assertScheduleConflicts([ScheduleConflict::OutsideLease], new ScheduleInput($this->cooperation->id, $this->room->id, $this->guest, 4, '08:00', '09:00', 15, CarbonImmutable::parse(CalendarWeek::MONDAY)));
        $this->assertTrue($this->app->make(OrganizationResources::class)->includes($this->cooperation, $this->room->id), 'Wynajęty zasób jest dostępny w kooperacji.');
        $this->assertFalse($this->app->make(OrganizationResources::class)->owns($this->cooperation, $this->room->id));
    }

    public function test_after_termination_there_are_no_slots_and_booking_is_rejected(): void
    {
        $lease = $this->lease();
        $schedule = $this->schedule($this->cooperation, $this->guest, 2, '08:00', '09:00');
        $slots = $this->app->make(FreeSlots::class);
        $late = $this->app->make(BookSlot::class)->book($this->cooperation, $this->guest, CarbonImmutable::parse('2026-11-24 08:00'), self::KIND);

        $this->assertCount(4, $slots->on($this->cooperation, $this->guest, CarbonImmutable::parse(CalendarWeek::TUESDAY)));

        $left = $this->app->make(SaveLease::class)->terminate($lease, CarbonImmutable::parse('2026-11-20'));

        $this->assertSame([$late->id], $left->modelKeys(), 'Zapisy po wypowiedzeniu — do odwołania.');
        $this->assertSame('2026-11-20', $schedule->refresh()->valid_to?->toDateString());
        $this->assertSame('2026-11-20', $lease->refresh()->valid_to?->toDateString());
        $this->assertCount(4, $slots->on($this->cooperation, $this->guest, CarbonImmutable::parse('2026-11-17')));
        $this->assertSame([], $slots->on($this->cooperation, $this->guest, CarbonImmutable::parse('2026-12-01')));

        $lease->forceFill(['is_active' => false])->save();
        $this->assertSame([], $slots->on($this->cooperation, $this->guest, CarbonImmutable::parse('2026-11-17')), 'Bez obowiązującej umowy nie ma terminów.');
        $this->expectException(SlotUnavailable::class);
        $this->app->make(BookSlot::class)->book($this->cooperation, $this->guest, CarbonImmutable::parse('2026-11-17 08:15'), self::KIND);
    }

    public function test_channels_follow_the_sharing_levels(): void
    {
        $this->lease(access: [LeaseAccess::View]);
        $leases = $this->app->make(ResourceLeases::class);

        $this->assertSame([$this->cooperation->id], $leases->channels($this->clinic, LeaseAccess::View)->pluck('cooperation_organization_id')->all());
        $this->assertSame([], $leases->channels($this->clinic, LeaseAccess::Book)->all());
        $this->assertSame([], $leases->channels($this->lessee, LeaseAccess::View)->all(), 'Kanał ma tylko właściciel zasobu.');
    }

    public function test_the_public_board_shows_hours_without_personal_data(): void
    {
        $lease = $this->lease();
        $this->schedule($this->cooperation, $this->guest, 2, '08:00', '09:00');
        $this->app->make(BookSlot::class)->book($this->cooperation, $this->guest, CalendarWeek::at('08:15', CalendarWeek::TUESDAY), self::KIND);
        $leases = $this->app->make(ResourceLeases::class);
        $token = (string) $lease->wallboard_token;

        $entries = $this->app->make(Wallboard::class)->of($lease, CarbonImmutable::parse(CalendarWeek::TUESDAY));

        $this->assertCount(1, $entries);
        $this->assertSame(['08:00', '09:00', 'Lekarz najemcy', 'G3 · Gabinet 3', 1], [$entries[0]->startsAt->format('H:i'), $entries[0]->endsAt->format('H:i'), $entries[0]->performer, $entries[0]->resource, $entries[0]->booked]);
        $this->assertSame(['startsAt', 'endsAt', 'performer', 'resource', 'booked'], array_keys(get_object_vars($entries[0])), 'Wiersz tablicy nie ma pól osoby.');
        $this->assertTrue($leases->byWallboardToken($token)?->is($lease));

        $this->app->make(SaveLease::class)->renewWallboardToken($lease);
        $this->assertNull($leases->byWallboardToken($token), 'Stary link tablicy przestaje działać.');
        $this->assertInstanceOf(WallboardEntry::class, $entries[0]);
    }

    public function test_a_lease_every_two_weeks_frees_the_other_weeks(): void
    {
        $friday = CarbonImmutable::parse('2026-11-13');
        $this->app->make(SaveLease::class)->save(new LeaseInput($this->clinic, $this->room->id, $this->lessee, $this->cooperation, 'UN/2', $friday, null, new LeaseWindows([[5, '13:00', '17:00']]), [LeaseAccess::Book], null, 2));
        $save = $this->app->make(SaveSchedule::class);

        $this->assertScheduleConflicts([ScheduleConflict::OutsideLease], new ScheduleInput($this->cooperation->id, $this->room->id, $this->guest, 5, '13:00', '17:00', 15, $friday));
        $save->save(new ScheduleInput($this->cooperation->id, $this->room->id, $this->guest, 5, '13:00', '17:00', 15, $friday, null, 2));
        $this->assertScheduleConflicts([ScheduleConflict::LeasedOut, ScheduleConflict::ResourceTaken], new ScheduleInput($this->clinic->id, $this->room->id, $this->doctor, 5, '13:00', '17:00', 15, $friday));
        $save->save(new ScheduleInput($this->clinic->id, $this->room->id, $this->doctor, 5, '13:00', '17:00', 15, CarbonImmutable::parse('2026-11-20'), null, 2));

        $slots = $this->app->make(FreeSlots::class);
        $this->assertCount(16, $slots->on($this->cooperation, $this->guest, $friday));
        $this->assertSame([], $slots->on($this->cooperation, $this->guest, CarbonImmutable::parse('2026-11-20')));
        $this->assertCount(16, $slots->on($this->cooperation, $this->guest, CarbonImmutable::parse('2026-11-27')));
        $this->assertCount(16, $slots->on($this->clinic, $this->doctor, CarbonImmutable::parse('2026-11-20')), 'W tygodnie bez najmu gabinet ma właściciel.');
        $this->assertSame([], $slots->on($this->clinic, $this->doctor, CarbonImmutable::parse('2026-11-27')));
    }

    public function test_an_on_demand_lease_fills_only_the_free_time_of_the_room(): void
    {
        $this->lease();
        $this->schedule($this->clinic, $this->doctor, 2, '12:00', '14:00');
        $dietitian = $this->account(AlphaAccount::class, ['email' => 'dietetyk@example.com', 'name' => 'Dietetyk']);
        $other = $this->organization('Dietetyka');
        $flexible = $this->organization('Dietetyka — kooperacja', ['parent_id' => $other->id, Organization::IS_COOPERATION => true]);
        $windows = new LeaseWindows([[1, '08:00', '20:00'], [2, '08:00', '20:00']]);
        $lease = $this->app->make(SaveLease::class)->save(new LeaseInput($this->clinic, $this->room->id, $other, $flexible, 'UN/9', CarbonImmutable::parse(CalendarWeek::MONDAY), null, $windows, [LeaseAccess::Book], null, 1, LeaseMode::OnDemand));
        $save = $this->app->make(SaveSchedule::class);

        $this->assertScheduleConflicts([ScheduleConflict::OutsideLease, ScheduleConflict::ResourceTaken], new ScheduleInput($flexible->id, $this->room->id, $dietitian, 2, '08:00', '20:00', 30, CarbonImmutable::parse(CalendarWeek::MONDAY)));
        $save->save(new ScheduleInput($flexible->id, $this->room->id, $dietitian, 2, '08:00', '20:00', 30, CarbonImmutable::parse(CalendarWeek::MONDAY), null, 1, true));
        $save->save(new ScheduleInput($this->clinic->id, $this->room->id, $this->doctor, 1, '09:00', '10:00', 15, CarbonImmutable::parse(CalendarWeek::MONDAY)));

        $times = array_map(static fn($slot): string => $slot->startsAt->format('H:i'), $this->app->make(FreeSlots::class)->on($flexible, $dietitian, CarbonImmutable::parse(CalendarWeek::TUESDAY)));
        $this->assertNotContains('08:30', $times, 'Okno najmu na wyłączność innej kooperacji (wt 08:00–12:00).');
        $this->assertNotContains('13:00', $times, 'Grafik właściciela (wt 12:00–14:00).');
        $this->assertNotContains('19:00', $times, 'Okno na wyłączność wt 18:00–22:00.');
        $this->assertSame(['14:00', '14:30', '15:00', '15:30', '16:00', '16:30', '17:00', '17:30'], $times);

        $booking = $this->app->make(BookSlot::class)->book($flexible, $dietitian, CalendarWeek::at('14:00', CalendarWeek::TUESDAY), self::KIND);
        $this->app->make(BookSlot::class)->book($flexible, $dietitian, CalendarWeek::at('15:00', CalendarWeek::TUESDAY), self::KIND);
        $this->assertSame(['booked' => 2], $this->app->make(ResourceLeases::class)->bookingCounts($lease, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')));
        $this->assertSame(1, ResourceBooking::query()->whereKey($booking->id)->count());
    }

    public function test_install_files_create_the_lease_and_the_lessee_schedule_once(): void
    {
        $this->seed(OrganizationsSeeder::class);
        config(['polygon.install.files' => [$this->installFile($this->leaseFile())]]);

        $this->command('polygon:install')->expectsOutputToContain('Najem założony: UN/7 · G3 · Gabinet 3')->assertSuccessful();
        $this->command('polygon:install')->expectsOutputToContain('Najem już istnieje: UN/7')->assertSuccessful();

        $lease = ResourceLease::query()->sole();
        $this->assertSame([[2, '08:00', '12:00'], [2, '18:00', '22:00'], [4, '08:00', '12:00']], $lease->leaseWindows()->windows);
        $this->assertSame([LeaseAccess::Book], $lease->accessLevels());
        $this->assertTrue($lease->cooperation?->isCooperation());
        $this->assertSame('2026-11-01', $lease->signed_on?->toDateString(), 'Data zawarcia z pola `signed` — niezależna od rozpoczęcia cyklu.');
        $this->assertSame(CalendarWeek::MONDAY, $lease->valid_from->toDateString());
        $this->assertSame(2, ResourceSchedule::query()->where('organization_id', $lease->cooperation_organization_id)->count());
    }

    public function test_an_install_lease_without_a_cooperation_writes_nothing(): void
    {
        $this->seed(OrganizationsSeeder::class);
        config(['polygon.install.files' => [$this->installFile($this->leaseFile(cooperation: false))]]);

        $this->command('polygon:install')->assertFailed();

        $this->assertSame(0, ResourceLease::query()->count());
        $this->assertSame(0, ResourceBooking::query()->count());
    }

    /**
     * Plik: właściciel z gabinetem 3, najemca z kooperacją, umowa na wtorki (dwa okna) i czwartki z dostępem `book`
     * oraz grafik lekarza najemcy we wtorki 08:00–12:00 i 18:00–22:00; bez $cooperation podorganizacja nie ma flagi
     * kooperacji.
     *
     * @return array<string, mixed>
     */
    private function leaseFile(bool $cooperation = true): array
    {
        $performer = ['zone' => 'alpha', 'email' => 'zew@example.com'];

        return [
            'organizations' => [
                'owner' => ['name' => 'Właściciel', 'resources' => [['key' => 'gabinet-3', 'name' => 'Gabinet 3', 'code' => 'G3']], 'leases' => [[
                    'resource' => 'gabinet-3', 'lessee' => 'lessee', 'cooperation' => 'lessee-coop', 'number' => 'UN/7', 'signed' => '2026-11-01', 'from' => CalendarWeek::MONDAY, 'to' => '2027-12-31',
                    'windows' => [['days' => [2], 'from' => '08:00', 'to' => '12:00'], ['days' => [2], 'from' => '18:00', 'to' => '22:00'], ['days' => [4], 'from' => '08:00', 'to' => '12:00']],
                    'access' => ['book'],
                ]]],
                'lessee' => ['name' => 'Najemca'],
                'lessee-coop' => ['name' => 'Najemca — kooperacja', 'parent' => 'lessee', 'is_cooperation' => $cooperation, 'schedule' => [
                    ['resource' => 'gabinet-3', 'performer' => $performer, 'weekday' => 2, 'from' => '08:00', 'to' => '12:00', 'slot' => 20, 'valid_from' => CalendarWeek::MONDAY, 'valid_to' => '2027-12-31'],
                    ['resource' => 'gabinet-3', 'performer' => $performer, 'weekday' => 2, 'from' => '18:00', 'to' => '22:00', 'slot' => 20, 'valid_from' => CalendarWeek::MONDAY, 'valid_to' => '2027-12-31'],
                ]],
            ],
            'zones' => ['alpha' => ['accounts' => [['email' => 'zew@example.com', 'name' => 'Lekarz zewnętrzny', 'organizations' => ['lessee-coop' => []]]]]],
        ];
    }

    /**
     * @param list<array{int, string, string}> $windows
     * @param list<LeaseAccess> $access
     */
    private function input(?Organization $lessee = null, ?Organization $cooperation = null, ?int $resourceId = null, string $number = 'UN/1', array $windows = [[2, '08:00', '12:00'], [2, '18:00', '22:00']], array $access = [LeaseAccess::Book, LeaseAccess::Wallboard]): LeaseInput
    {
        return new LeaseInput($this->clinic, $resourceId ?? $this->room->id, $lessee ?? $this->lessee, $cooperation ?? $this->cooperation, $number, CarbonImmutable::parse(CalendarWeek::MONDAY), CarbonImmutable::parse('2026-12-31'), new LeaseWindows($windows), $access);
    }

    /**
     * @param list<LeaseAccess> $access
     */
    private function lease(array $access = [LeaseAccess::Book, LeaseAccess::Wallboard]): ResourceLease
    {
        return $this->app->make(SaveLease::class)->save($this->input(access: $access));
    }

    private function cooperationInput(int $weekday, string $from, string $to): ScheduleInput
    {
        return new ScheduleInput($this->cooperation->id, $this->room->id, $this->guest, $weekday, $from, $to, 15, CarbonImmutable::parse(CalendarWeek::MONDAY), CarbonImmutable::parse('2026-12-31'));
    }

    private function schedule(Organization $unit, ZoneAccount $performer, int $weekday, string $from, string $to): ResourceSchedule
    {
        $validTo = $unit->is($this->cooperation) ? CarbonImmutable::parse('2026-12-31') : null;

        return $this->app->make(SaveSchedule::class)->save(new ScheduleInput($unit->id, $this->room->id, $performer, $weekday, $from, $to, 15, CarbonImmutable::parse(CalendarWeek::MONDAY), $validTo));
    }

    /**
     * @param list<LeaseConflict> $expected
     */
    private function assertLeaseConflicts(array $expected, LeaseInput $input): void
    {
        try {
            $this->app->make(SaveLease::class)->save($input);
            $this->fail('Najem powinien zostać odrzucony.');
        } catch (LeaseRejected $rejected) {
            $this->assertSame($expected, $rejected->conflicts);
        }
    }

    /**
     * @param list<ScheduleConflict> $expected
     */
    private function assertScheduleConflicts(array $expected, ScheduleInput $input): void
    {
        try {
            $this->app->make(SaveSchedule::class)->save($input);
            $this->fail('Grafik powinien zostać odrzucony.');
        } catch (ScheduleRejected $rejected) {
            $this->assertSame($expected, $rejected->conflicts);
        }
    }
}
