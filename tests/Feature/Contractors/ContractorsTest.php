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

namespace Tests\Feature\Contractors;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Simplysmart\Polygon\Contractors\ContractorData;
use Simplysmart\Polygon\Contractors\ContractorRejected;
use Simplysmart\Polygon\Contractors\SaveContact;
use Simplysmart\Polygon\Contractors\SaveContract;
use Simplysmart\Polygon\Contractors\SaveContractor;
use Simplysmart\Polygon\Database\History;
use Simplysmart\Polygon\Enums\ContractStatus;
use Simplysmart\Polygon\Identifiers\Nip;
use Simplysmart\Polygon\Identifiers\Pesel;
use Simplysmart\Polygon\Identifiers\Regon;
use Simplysmart\Polygon\Models\Contractor;
use Simplysmart\Polygon\Models\ContractorContact;
use Simplysmart\Polygon\Models\ContractorContract;
use Simplysmart\Polygon\Queries\Contractors\ContractorSearch;
use Simplysmart\Polygon\Rules\Nip as NipRule;
use Simplysmart\Polygon\Rules\Pesel as PeselRule;
use Simplysmart\Polygon\Rules\Regon as RegonRule;
use Tests\TestCase;

/**
 * Kontrahenci organizacji z polygonu (`contractors`, `contractor_contacts`, `contractor_contracts`) i polskie
 * identyfikatory: sumy kontrolne NIP, REGON i PESEL (z datą urodzenia i płcią) oraz reguły walidacji; zapis kontrahenta
 * z normalizacją NIP/REGON i NIP-em unikalnym w organizacji; wyszukiwanie po nazwie i początku NIP-u w organizacji
 * i jej przodkach; jeden kontakt główny; stan umowy z okresu obowiązywania.
 */
class ContractorsTest extends TestCase
{
    use RefreshDatabase;

    /** Poprawny NIP (suma kontrolna). */
    private const string NIP = '5252344078';

    private const string REGON = '123456785';

    public function test_identifiers_check_their_checksums(): void
    {
        $this->assertTrue(Nip::isValid('PL 525-234-40-78'));
        $this->assertFalse(Nip::isValid('5252344079'));
        $this->assertFalse(Nip::isValid('0252344078'), 'Bez 0 na początku.');
        $this->assertSame('525-234-40-78', Nip::format(self::NIP));
        $this->assertTrue(Regon::isValid(self::REGON));
        $this->assertFalse(Regon::isValid('123456786'));
        $this->assertTrue(Regon::isValid('12345678512347'));
        $pesel = Pesel::tryFrom('44051401359');
        $this->assertNotNull($pesel);
        $this->assertSame(['1944-05-14', 'M'], [$pesel->birthDate->toDateString(), $pesel->sex()]);
        $this->assertNull(Pesel::tryFrom('44051401358'));
        $this->assertNull(Pesel::tryFrom('44133101354'), 'Miesiąc 13 bez przesunięcia stulecia nie istnieje.');

        $this->assertTrue(Validator::make(['nip' => self::NIP, 'regon' => self::REGON, 'pesel' => '44051401359'], ['nip' => [new NipRule()], 'regon' => [new RegonRule()], 'pesel' => [new PeselRule()]])->passes());
        $this->assertSame(['nip', 'regon', 'pesel'], Validator::make(['nip' => '1234567890', 'regon' => '1', 'pesel' => '1'], ['nip' => [new NipRule()], 'regon' => [new RegonRule()], 'pesel' => [new PeselRule()]])->errors()->keys());
    }

    public function test_contractor_tables_have_history(): void
    {
        foreach ([Contractor::TABLE, ContractorContact::TABLE, ContractorContract::TABLE] as $table) {
            $this->assertTrue(Schema::hasTable(History::tableFor($table)), $table);
        }
    }

    public function test_saving_normalises_identifiers_and_keeps_the_nip_unique_in_the_organization(): void
    {
        $seat = $this->organization('Siedziba');
        $save = $this->app->make(SaveContractor::class);

        $contractor = $save->save($seat, new ContractorData('Firma Budowlana Sp. z o.o.', 'Budowlana', 'PL 525-234-40-78', '123-456-785', city: 'Łódź', countryCode: 'pl'));

        $this->assertSame([self::NIP, self::REGON, 'PL', 'Budowlana', '525-234-40-78'], [$contractor->nip, $contractor->regon, $contractor->country_code, $contractor->displayName(), $contractor->formattedNip()]);
        $this->assertSame($contractor->id, $save->save($seat, new ContractorData('Zmieniona nazwa', nip: self::NIP), $contractor)->id, 'Zapis tego samego kontrahenta nie koliduje z jego NIP-em.');
        $this->assertSame(self::NIP, $save->save($this->organization('Inna organizacja'), new ContractorData('Ta sama firma', nip: self::NIP))->nip, 'NIP jest unikalny tylko w organizacji.');
        $this->assertContractorRejected('nip', fn() => $save->save($seat, new ContractorData('Duplikat', nip: self::NIP)));
        $this->assertContractorRejected('nip', fn() => $save->save($seat, new ContractorData('Zły NIP', nip: '5252344079')));
        $this->assertContractorRejected('regon', fn() => $save->save($seat, new ContractorData('Zły REGON', regon: '123456786')));
        $this->assertSame(2, Contractor::query()->count());
    }

    public function test_search_finds_contractors_of_the_organization_and_its_ancestors_by_name_or_nip(): void
    {
        $seat = $this->organization('Siedziba');
        $clinic = $this->organization('Poradnia', ['parent_id' => $seat->id]);
        $save = $this->app->make(SaveContractor::class);
        $save->save($seat, new ContractorData('Firma Budowlana', nip: self::NIP));
        $save->save($clinic, new ContractorData('Piekarnia Kowalski'));
        $save->save($seat, new ContractorData('Zamknięta hurtownia', active: false));
        $search = $this->app->make(ContractorSearch::class);

        $this->assertSame(['Firma Budowlana', 'Piekarnia Kowalski'], $search->in($clinic)->pluck('name')->all());
        $this->assertSame(['Firma Budowlana'], $search->in($seat)->pluck('name')->all(), 'Siedziba nie widzi kontrahentów poradni.');
        $this->assertSame(['Firma Budowlana'], $search->in($clinic, '525-23')->pluck('name')->all());
        $this->assertSame(['Piekarnia Kowalski'], $search->in($clinic, 'kowal')->pluck('name')->all());
        $this->assertCount(3, $search->in($clinic, withInactive: true));
    }

    public function test_one_primary_contact_and_contract_status(): void
    {
        $contractor = $this->app->make(SaveContractor::class)->save($this->organization('Siedziba'), new ContractorData('Firma'));
        $contacts = $this->app->make(SaveContact::class);
        $first = $contacts->save($contractor, 'Anna Kadrowa', 'Kadry', '600100200', 'kadry@example.com', true);
        $second = $contacts->save($contractor, 'Jan BHP', 'BHP', null, null, true);

        $this->assertSame([false, true], [$first->refresh()->is_primary, $second->refresh()->is_primary]);

        $contracts = $this->app->make(SaveContract::class);
        $contract = $contracts->save($contractor, 'U/1/2026', CarbonImmutable::parse('2026-01-02'), CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));
        $this->assertSame([ContractStatus::Upcoming, ContractStatus::Active, ContractStatus::Expired], [
            $contract->status(CarbonImmutable::parse('2025-12-31')), $contract->status(CarbonImmutable::parse('2026-06-01')), $contract->status(CarbonImmutable::parse('2027-01-01')),
        ]);
        $this->assertContractorRejected('valid_to', fn() => $contracts->save($contractor, 'U/2', null, CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-01-01')));
        $this->assertSame(1, ContractorContract::query()->count());
    }

    /**
     * Zapis kontrahenta albo umowy odrzucony wyjątkiem ContractorRejected dla pola $field.
     *
     * @param callable(): mixed $action
     */
    private function assertContractorRejected(string $field, callable $action): void
    {
        try {
            $action();
            $this->fail("Zapis powinien zostać odrzucony ({$field}).");
        } catch (ContractorRejected $rejected) {
            $this->assertSame($field, $rejected->field);
        }
    }
}
