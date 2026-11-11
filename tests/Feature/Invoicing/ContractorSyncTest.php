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

namespace Tests\Feature\Invoicing;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Simplysmart\Polygon\Contractors\ContractorData;
use Simplysmart\Polygon\Contractors\SaveContractor;
use Simplysmart\Polygon\Integrations\Fakturownia\FakturowniaFailed;
use Simplysmart\Polygon\Invoicing\ContractorFingerprint;
use Simplysmart\Polygon\Invoicing\ContractorPushGate;
use Simplysmart\Polygon\Invoicing\InvoicingAccounts;
use Simplysmart\Polygon\Invoicing\InvoicingRejected;
use Simplysmart\Polygon\Invoicing\SaveInvoicingAccount;
use Simplysmart\Polygon\Invoicing\SyncContractors;
use Simplysmart\Polygon\Jobs\PushContractorJob;
use Simplysmart\Polygon\Models\Contractor;
use Simplysmart\Polygon\Models\InvoicingAccount;
use Simplysmart\Polygon\Models\InvoicingContractorLink;
use Simplysmart\Polygon\Models\Organization;
use Tests\Fixtures\Fakturownia\FakturowniaStubs;
use Tests\TestCase;

/**
 * Konto Fakturowni organizacji głównej i dwukierunkowa synchronizacja kontrahentów z polygonu (docs/baza-danych.md §21)
 * na stubach odpowiedzi: zapis konta dopiero po udanym połączeniu, pobranie klientów (nowi, dopasowani po NIP-ie
 * w jednostce podrzędnej, bez zmian), konflikt — wygrywa nowsza zmiana w obie strony, wysyłka po zapisie kontrahenta
 * bez pętli zwrotnej, klient szukany po NIP-ie zamiast duplikatu, pominięcia w raporcie i błąd konta.
 */
class ContractorSyncTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'secret-api-token';

    private const string NIP = '5252344078';

    private Organization $root;

    private Organization $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Warsaw'));
        $this->root = $this->organization('NZOZ Test');
        $this->clinic = $this->organization('Poradnia', ['parent_id' => $this->root->id]);
    }

    public function test_the_account_is_saved_for_the_root_organization_only_after_a_successful_connection(): void
    {
        Http::fake(['nzoz-test.fakturownia.pl/account.json*' => FakturowniaStubs::response('account.ok'), 'bad.fakturownia.pl/*' => FakturowniaStubs::response('error.401')]);
        $save = $this->app->make(SaveInvoicingAccount::class);
        $accounts = $this->app->make(InvoicingAccounts::class);

        $this->assertSame([InvoicingAccount::API_TOKEN, text('polygon::invoicing.unauthorized')], $this->rejection(fn() => $save->save($this->clinic, 'bad', self::TOKEN)));
        $this->assertSame(InvoicingAccount::API_TOKEN, $this->rejection(fn() => $save->save($this->clinic, 'nzoz-test', ''))[0]);
        $this->assertSame(InvoicingAccount::SUBDOMAIN, $this->rejection(fn() => $save->save($this->clinic, 'evil.example.com', self::TOKEN))[0]);
        $this->assertSame(InvoicingAccount::DEPARTMENT_ID, $this->rejection(fn() => $save->save($this->clinic, 'nzoz-test', self::TOKEN, 'abc'))[0]);
        $this->assertNull($accounts->configured($this->root));

        $account = $save->save($this->clinic, 'https://NZOZ-Test.fakturownia.pl/', self::TOKEN, '77');
        $this->assertSame([$this->root->id, 'nzoz-test', self::TOKEN, '77', true], [$account->organization_id, $account->subdomain, $account->api_token, $account->department_id, $account->is_active]);
        $this->assertSame($account->id, $accounts->of($this->clinic)?->id, 'Jednostka podrzędna korzysta z konta organizacji głównej.');
        $this->assertSame([$this->root->id, $this->clinic->id], $accounts->organizationIds($account));

        $again = $save->save($this->root, 'nzoz-test', null, null, false);
        $this->assertSame([$account->id, self::TOKEN, null, 1], [$again->id, $again->api_token, $again->department_id, InvoicingAccount::query()->count()], 'Pusty token zostawia zapisany.');
        $this->assertNull($accounts->of($this->root), 'Wyłączone konto nie obsługuje organizacji.');
        $this->assertNotNull($accounts->configured($this->root));
    }

    public function test_pull_creates_contractors_links_them_and_does_not_send_them_back(): void
    {
        $account = $this->account();
        $this->fakeApi();

        $report = $this->sync()->pull($account);

        $this->assertSame([4, 0, 0, 0, []], [$report->created, $report->updated, $report->pushed, $report->unchanged, $report->skipped]);
        $budowlana = Contractor::query()->where(Contractor::NIP, self::NIP)->sole();
        $this->assertSame([$this->root->id, 'Firma Budowlana Sp. z o.o.', 'Budowlana', '123456785', 'biuro@budowlana.example', 'PL'], [$budowlana->organization_id, $budowlana->name, $budowlana->short_name, $budowlana->regon, $budowlana->email, $budowlana->country_code]);
        $this->assertSame([null, 'DE'], [Contractor::query()->where('name', 'Foreign GmbH')->sole()->nip, Contractor::query()->where('name', 'Foreign GmbH')->sole()->country_code]);
        $this->assertNull(Contractor::query()->where('name', 'Jan Nowak (osoba prywatna)')->sole()->nip);

        $link = InvoicingContractorLink::query()->where('contractor_id', $budowlana->id)->sole();
        $this->assertSame(['101', ContractorFingerprint::of($budowlana), 4], [$link->external_id, $link->synced_checksum, InvoicingContractorLink::query()->count()]);
        $this->assertSame(CarbonImmutable::parse('2026-11-01 10:00', 'Europe/Warsaw')->getTimestamp(), $link->external_updated_at?->getTimestamp());
        $this->assertNotNull($account->refresh()->contractors_synced_at);
        Queue::assertNothingPushed();
        Http::assertNotSent(static fn(Request $request): bool => $request->method() !== 'GET');

        $second = $this->sync()->sync($account);
        $this->assertSame([0, 0, 0, 4, 4], [$second->created, $second->updated, $second->pushed, $second->unchanged, Contractor::query()->count()], 'Druga synchronizacja niczego nie zmienia.');
        Http::assertNotSent(static fn(Request $request): bool => $request->method() !== 'GET');
    }

    public function test_pull_matches_a_contractor_of_a_unit_by_nip_and_the_newer_side_wins(): void
    {
        $account = $this->account();
        $local = $this->app->make(SaveContractor::class)->save($this->clinic, new ContractorData('Budowlana (stara nazwa)', nip: self::NIP, krs: '0000123456', city: 'Kraków'));
        $this->fakeApi();
        $report = $this->sync()->pull($account);

        $this->assertSame([3, 1, 0], [$report->created, $report->updated, $report->pushed], 'Klient z 1 listopada jest nowszy niż kontrahent z 20 października.');
        $this->assertSame([$this->clinic->id, 'Firma Budowlana Sp. z o.o.', 'Warszawa', '0000123456', 4], [$local->refresh()->organization_id, $local->name, $local->city, $local->krs, Contractor::query()->count()], 'Kontrahent zostaje w swojej jednostce, KRS-u Fakturownia nie nadpisuje.');

        $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00', 'Europe/Warsaw'));
        $this->app->make(SaveContractor::class)->save($this->clinic, new ContractorData('Firma Budowlana Sp. z o.o.', 'Budowlana', self::NIP, '123456785', '0000123456', 'ul. Nowa 5', '90-001', 'Łódź', 'PL', 'biuro@budowlana.example'), $local);

        $conflict = $this->sync()->pull($account);
        $this->assertSame([0, 0, 1, 3], [$conflict->created, $conflict->updated, $conflict->pushed, $conflict->unchanged], 'Kontrahent zmieniony 8 listopada wygrywa z klientem z 1 listopada.');
        $this->assertSame('Łódź', $local->refresh()->city);
        Http::assertSent(static fn(Request $request): bool => $request->method() === 'PUT' && str_contains($request->url(), '/clients/101.json') && data_get($request->data(), 'client.city') === 'Łódź' && data_get($request->data(), 'client.external_id') === $local->token);

        $this->fakeApi(['updated_at' => '2026-11-12T08:00:00.000+01:00', 'city' => 'Gdańsk']);
        $remoteWins = $this->sync()->pull($account);
        $this->assertSame([1, 0, 'Gdańsk'], [$remoteWins->updated, $remoteWins->pushed, $local->refresh()->city], 'Nowsza zmiana w Fakturowni nadpisuje kontrahenta bez zmian od synchronizacji.');
    }

    public function test_a_local_only_contractor_is_never_sent_to_the_invoicing_system(): void
    {
        $account = $this->account();
        $this->app->make(ContractorPushGate::class)->closed(function (): void {
            $local = $this->app->make(SaveContractor::class)->save($this->root, new ContractorData('Pracodawca z importu', nip: self::NIP));
            $local->markInvoicingLocalOnly();
            $local->save();
        });
        $local = Contractor::query()->sole();
        $this->assertTrue($local->isInvoicingLocalOnly());

        $this->app->make(SaveContractor::class)->save($this->root, new ContractorData('Pracodawca z importu (zmiana)', nip: self::NIP), $local);
        Queue::assertNotPushed(PushContractorJob::class);

        $this->fakeResponses([]);
        $this->runJob($local);
        $report = $this->sync()->pushPending($account);
        $this->assertSame(0, $report->pushed);
        Http::assertNothingSent();
    }

    public function test_a_saved_contractor_is_pushed_once_and_found_by_nip_instead_of_duplicated(): void
    {
        $save = $this->app->make(SaveContractor::class);
        $save->save($this->clinic, new ContractorData('Bez konta', nip: '8951234569'));
        Queue::assertNothingPushed();

        $account = $this->account();
        $contractor = $save->save($this->clinic, new ContractorData('Firma Budowlana', nip: self::NIP, city: 'Warszawa'));
        Queue::assertPushed(PushContractorJob::class, static fn(PushContractorJob $job): bool => $job->contractorId === $contractor->id);

        $this->fakeResponses([
            '*clients.json?*external_id=*' => FakturowniaStubs::response('clients.empty'),
            '*clients.json?*tax_no=*' => FakturowniaStubs::response('clients.empty'),
            '*clients.json' => FakturowniaStubs::response('client.created'),
        ]);
        $this->runJob($contractor);

        $link = InvoicingContractorLink::query()->where('contractor_id', $contractor->id)->sole();
        $this->assertSame(['201', $account->id, ContractorFingerprint::of($contractor)], [$link->external_id, $link->invoicing_account_id, $link->synced_checksum]);
        Http::assertSent(static fn(Request $request): bool => $request->method() === 'POST' && data_get($request->data(), 'client.tax_no') === self::NIP && data_get($request->data(), 'client.external_id') === $contractor->token);

        $this->fakeResponses([]);
        $this->runJob($contractor);
        Http::assertNothingSent();

        $other = $save->save($this->root, new ContractorData('Transport Kowalski', nip: '5261040828'));
        $this->fakeResponses([
            '*clients.json?*external_id=*' => FakturowniaStubs::response('clients.empty'),
            '*clients.json?*tax_no=5261040828*' => Http::response([FakturowniaStubs::data('client.updated', ['id' => 102, 'tax_no' => 'PL5261040828'])]),
            '*clients/102.json' => FakturowniaStubs::response('client.updated', ['id' => 102]),
        ]);
        $this->runJob($other);

        $this->assertSame('102', InvoicingContractorLink::query()->where('contractor_id', $other->id)->sole()->external_id);
        Http::assertSent(static fn(Request $request): bool => $request->method() === 'PUT' && str_contains($request->url(), '/clients/102.json'));
        Http::assertNotSent(static fn(Request $request): bool => $request->method() === 'POST');
    }

    public function test_skipped_clients_are_reported_and_an_account_error_is_remembered(): void
    {
        $account = $this->account();
        $deleted = $this->app->make(SaveContractor::class)->save($this->root, new ContractorData('Usunięta firma', nip: self::NIP));
        $deleted->delete();
        $this->fakeApi();

        $report = $this->sync()->pull($account);

        $this->assertSame([3, [['name' => 'Firma Budowlana Sp. z o.o.', 'reason' => text('polygon::contractors.nip_taken')]]], [$report->created, $report->skipped]);
        $this->command('polygon:invoicing:contractors-sync', ['organization' => $this->clinic->token])
            ->expectsOutputToContain('NZOZ Test: nowi 0, zaktualizowani 0, wysłani 0, bez zmian 3, pominięci 1.')
            ->assertSuccessful();

        $this->fakeResponses(['*' => FakturowniaStubs::response('error.401')]);
        try {
            $this->sync()->pull($account);
            $this->fail('Oczekiwano odmowy Fakturowni.');
        } catch (FakturowniaFailed $failed) {
            $this->assertTrue($failed->unauthorized());
        }

        $this->assertNotNull($account->refresh()->last_error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $account->last_error);
        $this->command('polygon:invoicing:contractors-sync')->assertFailed();
    }

    private function account(): InvoicingAccount
    {
        Http::fake(['*account.json*' => FakturowniaStubs::response('account.ok')]);

        return $this->app->make(SaveInvoicingAccount::class)->save($this->root, 'nzoz-test', self::TOKEN);
    }

    /**
     * Lista klientów ze stuba (pierwszy klient z podmienionymi polami) i potwierdzenie każdej zmiany klienta.
     *
     * @param array<string, mixed> $firstClient
     */
    private function fakeApi(array $firstClient = []): void
    {
        $clients = FakturowniaStubs::data('clients.page-1');
        $clients[0] = [...(is_array($clients[0]) ? $clients[0] : []), ...$firstClient];

        $this->fakeResponses([
            '*clients.json?*&page=1&*' => Http::response($clients),
            '*clients/*.json' => FakturowniaStubs::response('client.updated', ['updated_at' => '2026-11-08T09:00:05.000+01:00']),
        ]);
    }

    /**
     * Nowy zestaw odpowiedzi API: świeża fabryka HTTP zamiast dokładania stubów do poprzednich (pierwszy pasujący wygrywa).
     *
     * @param array<string, mixed> $responses
     */
    private function fakeResponses(array $responses): void
    {
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    private function sync(): SyncContractors
    {
        return $this->app->make(SyncContractors::class);
    }

    private function runJob(Contractor $contractor): void
    {
        $this->app->call([new PushContractorJob($contractor->id), 'handle']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function rejection(callable $action): array
    {
        try {
            $action();
        } catch (InvoicingRejected $rejected) {
            return [$rejected->field, $rejected->getMessage()];
        }

        $this->fail('Oczekiwano odmowy zapisu konta.');
    }
}
