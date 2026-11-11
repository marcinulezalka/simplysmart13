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
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Simplysmart\Polygon\Contractors\ContractorData;
use Simplysmart\Polygon\Contractors\SaveContractor;
use Simplysmart\Polygon\Database\ColumnNames;
use Simplysmart\Polygon\Enums\InvoiceKind;
use Simplysmart\Polygon\Enums\InvoiceSource;
use Simplysmart\Polygon\Enums\InvoiceStatus;
use Simplysmart\Polygon\Enums\InvoiceTax;
use Simplysmart\Polygon\Enums\InvoicingSyncTrigger;
use Simplysmart\Polygon\Enums\PaymentMethod;
use Simplysmart\Polygon\Integrations\Fakturownia\TaxRateCode;
use Simplysmart\Polygon\Invoicing\ImportInvoices;
use Simplysmart\Polygon\Invoicing\InvoiceData;
use Simplysmart\Polygon\Invoicing\InvoiceItemData;
use Simplysmart\Polygon\Invoicing\InvoicingSyncReport;
use Simplysmart\Polygon\Invoicing\IssueInvoice;
use Simplysmart\Polygon\Invoicing\SaveInvoicingAccount;
use Simplysmart\Polygon\Invoicing\SendInvoice;
use Simplysmart\Polygon\Invoicing\SyncInvoicing;
use Simplysmart\Polygon\Jobs\PushContractorJob;
use Simplysmart\Polygon\Jobs\SendInvoiceJob;
use Simplysmart\Polygon\Jobs\SyncInvoicingJob;
use Simplysmart\Polygon\Models\Contractor;
use Simplysmart\Polygon\Models\Invoice;
use Simplysmart\Polygon\Models\InvoiceItem;
use Simplysmart\Polygon\Models\InvoicingAccount;
use Simplysmart\Polygon\Models\InvoicingContractorLink;
use Simplysmart\Polygon\Models\Organization;
use Tests\Fixtures\Fakturownia\FakturowniaStubs;
use Tests\TestCase;

/**
 * Pobieranie faktur VAT i pro forma z Fakturowni oraz synchronizacja w dwóch trybach (docs/baza-danych.md §21) na stubach
 * odpowiedzi: zakres od 1 stycznia poprzedniego roku, pozycje i stawki (23, 8, zw, np), pominięta korekta i nieznana
 * stawka, faktura wystawiona w aplikacji rozpoznana po `oid` (tylko stan, bez duplikatu), nabywca dopasowany po NIP-ie
 * albo założony z klienta Fakturowni i z danych faktury bez wysyłki zwrotnej, pobranie przyrostowe, pobrane faktury
 * tylko do odczytu; pełna synchronizacja z raportem w koncie, nocna komenda z przełącznikiem automatycznej, zadanie na
 * żądanie i wystawienie pro formy.
 */
class InvoiceImportTest extends TestCase
{
    use RefreshDatabase;

    private const string NIP = '5252344078';

    private Organization $root;

    private Organization $clinic;

    private Contractor $contractor;

    private InvoicingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        Sleep::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Warsaw'));
        $this->root = $this->organization('NZOZ Test', ['nip' => '1234563218', 'city' => 'Łódź', 'country_code' => 'PL']);
        $this->clinic = $this->organization('Poradnia', ['parent_id' => $this->root->id]);
        $this->contractor = $this->app->make(SaveContractor::class)->save($this->clinic, new ContractorData('Firma Budowlana', nip: self::NIP, city: 'Warszawa', countryCode: 'PL'));
        $this->fakeResponses(['*account.json*' => FakturowniaStubs::response('account.ok')]);
        $this->account = $this->app->make(SaveInvoicingAccount::class)->save($this->root, 'nzoz-test', 'secret-api-token');
    }

    public function test_remote_tax_codes_translate_back_to_the_item_taxation(): void
    {
        $this->assertSame([InvoiceTax::Rate, '23.00'], TaxRateCode::parse('23'));
        $this->assertSame([InvoiceTax::Rate, '8.00'], TaxRateCode::parse(8));
        $this->assertSame([InvoiceTax::Rate, '7.50'], TaxRateCode::parse('7,5'));
        $this->assertSame([InvoiceTax::Exempt, null], TaxRateCode::parse('zw'));
        $this->assertSame([InvoiceTax::NotApplicable, null], TaxRateCode::parse('np'));
        $this->assertSame([InvoiceTax::NotApplicable, null], TaxRateCode::parse('disabled'));
        $this->assertNull(TaxRateCode::parse('oo'));
        $this->assertNull(TaxRateCode::parse('150'));
        $this->assertNull(TaxRateCode::parse(null));
    }

    public function test_vat_and_pro_forma_invoices_are_downloaded_with_items_buyers_and_report(): void
    {
        $local = $this->issueLocal();
        $this->fakeInvoices($this->page($local));

        $report = $this->import()->import($this->account);

        $this->assertSame([3, 1, 0, 2], [$report->created, $report->updated, $report->unchanged, $report->contractorsCreated]);
        $this->assertSame(['KOR 1/11/2026', 'FV 4/11/2026'], array_column($report->skipped, 'name'), 'Korekta i pozycja z nieobsługiwaną stawką są pominięte.');
        $this->assertSame(4, Invoice::query()->count(), 'Faktura wystawiona w aplikacji nie ma duplikatu.');
        Http::assertSent(static fn(Request $request): bool => str_contains($request->url(), '/invoices.json?') && str_contains($request->url(), 'period=more&date_from=2025-01-01&date_to=2027-11-20&kinds%5B%5D=vat&kinds%5B%5D=proforma&include_positions=true&order=updated_at.desc&page=1&per_page=100'));
        Http::assertNotSent(static fn(Request $request): bool => $request->method() !== 'GET');
        Queue::assertNotPushed(PushContractorJob::class);

        $vat = Invoice::query()->where(Invoice::EXTERNAL_ID, '9101')->sole();
        $this->assertSame([InvoiceKind::Vat, InvoiceSource::Fakturownia, InvoiceStatus::Sent, $this->root->id, $this->contractor->id, 'FV 1/11/2026', 'FV 1/11/2026'], [$vat->kind, $vat->source, $vat->status, $vat->organization_id, $vat->contractor_id, $vat->name, $vat->number()]);
        $this->assertSame(['250.00', '46.00', '296.00', 'paid', '2026-11-15', '2026-11-02', 'https://nzoz-test.fakturownia.pl/invoice/vatInvoiceToken9101', 'Badania okresowe pracowników'], [$vat->total_net, $vat->total_vat, $vat->total_gross, $vat->external_status, $vat->paid_at?->toDateString(), $vat->sell_date->toDateString(), $vat->external_url, $vat->notes]);
        $this->assertTrue($vat->isImported() && $vat->isPaid());
        $this->assertSame([['Badanie okresowe', '2.000', 'szt', '100.00', InvoiceTax::Rate, '23.00', '200.00', '46.00', '246.00'], ['Szkolenie BHP', '1.000', 'usł', '50.00', InvoiceTax::Exempt, null, '50.00', '0.00', '50.00']], $this->items($vat));

        $proforma = Invoice::query()->where(Invoice::EXTERNAL_ID, '9102')->sole();
        $buyer = $proforma->contractor;
        $this->assertNotNull($buyer);
        $this->assertSame([InvoiceKind::Proforma, PaymentMethod::Cash, 'issued', null], [$proforma->kind, $proforma->payment_method, $proforma->external_status, $proforma->paid_at]);
        $this->assertSame(['Nowa Firma Sp. z o.o.', '7811767696', 'Poznań', $this->root->id], [$buyer->name, $buyer->nip, $buyer->city, $buyer->organization_id], 'Nabywca spoza kartoteki jest zakładany z klienta Fakturowni.');
        $this->assertSame('205', InvoicingContractorLink::query()->where(Contractor::FOREIGN_KEY, $buyer->id)->sole()->external_id);
        $this->assertSame([[InvoiceTax::Rate, '8.00', '8.00'], [InvoiceTax::NotApplicable, null, '0.00']], array_map(static fn(array $item): array => [$item[4], $item[5], $item[7]], $this->items($proforma)));

        $private = Invoice::query()->where(Invoice::EXTERNAL_ID, '9104')->sole()->contractor;
        $this->assertSame(['Jan Kowalski', null, 'ul. Polna 3'], [$private?->name, $private?->nip, $private?->street], 'Faktura bez klienta zakłada kontrahenta z danych nabywcy.');

        $local->refresh();
        $this->assertSame([InvoiceSource::Local, '9001', 'FV 2/11/2026', 'FV 2/11/2026', 'paid', '2026-11-12', 'Badanie okresowe'], [$local->source, $local->external_id, $local->external_number, $local->name, $local->external_status, $local->paid_at?->toDateString(), $local->items->first()?->name], 'Faktura z aplikacji dostaje stan i numer, ale nie treść z Fakturowni.');
        $this->assertSame('2026-11-20 10:00', $this->account->refresh()->invoices_synced_at?->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'));
    }

    public function test_later_downloads_take_only_invoices_changed_since_the_previous_one(): void
    {
        $this->fakeInvoices($this->page());
        $this->import()->import($this->account);

        $this->travelTo(CarbonImmutable::parse('2026-11-22 08:00', 'Europe/Warsaw'));
        $again = $this->import()->import($this->account);
        $this->assertSame([0, 0, 0, []], [$again->created, $again->updated, $again->unchanged, $again->skipped], 'Bez zmian w Fakturowni pobranie kończy się na pierwszej fakturze.');

        $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00', 'Europe/Warsaw'));
        $page = $this->page();
        $page[0] = [...$page[0], 'updated_at' => '2026-11-22T09:00:00.000+01:00', 'price_net' => '100.0', 'price_tax' => '23.0', 'price_gross' => '123.0', 'positions' => [['name' => 'Badanie okresowe', 'quantity' => '1', 'price_net' => '100.0', 'tax' => '23', 'total_price_net' => '100.0', 'total_price_gross' => '123.0']]];
        $this->fakeInvoices($page);
        $changed = $this->import()->import($this->account);

        $vat = Invoice::query()->where(Invoice::EXTERNAL_ID, '9101')->sole();
        $this->assertSame([0, 1, 0], [$changed->created, $changed->updated, $changed->unchanged]);
        $this->assertSame(['123.00', 1, 4], [$vat->total_gross, $vat->items()->count(), Invoice::query()->count()], 'Zmieniona faktura dostaje nowe pozycje, bez nowych faktur.');
    }

    public function test_a_downloaded_invoice_is_read_only(): void
    {
        $this->fakeInvoices($this->page());
        $this->import()->import($this->account);
        $vat = Invoice::query()->where(Invoice::EXTERNAL_ID, '9101')->sole();
        $this->fakeResponses([]);

        $this->app->make(SendInvoice::class)->send($vat);
        $this->app->make(SendInvoice::class)->resend($vat);

        $this->assertSame(InvoiceStatus::Sent, $vat->refresh()->status);
        Http::assertNothingSent();
        Queue::assertNotPushed(SendInvoiceJob::class);
    }

    public function test_the_synchronization_runs_contractors_then_invoices_and_stores_the_report(): void
    {
        $local = $this->issueLocal(InvoiceStatus::Queued);
        $this->fakeResponses([
            '*clients.json?*&page=1&*' => FakturowniaStubs::response('clients.empty'),
            '*clients.json?*' => FakturowniaStubs::response('clients.empty'),
            '*clients.json' => FakturowniaStubs::response('client.created'),
            '*invoices.json?*' => FakturowniaStubs::response('invoices.empty'),
        ]);

        $report = $this->app->make(SyncInvoicing::class)->run($this->account, InvoicingSyncTrigger::Manual);

        $this->assertTrue($report->succeeded());
        $this->assertSame([1, 1], [$report->contractors->pushed, $report->invoicesQueued], 'Kontrahent bez klienta jest wysyłany, faktura z kolejki — zlecana do wysyłki.');
        Queue::assertPushed(SendInvoiceJob::class, static fn(SendInvoiceJob $job): bool => $job->invoiceId === $local->id);
        $saved = InvoicingSyncReport::fromArray($this->account->refresh()->last_sync_report);
        $this->assertSame([InvoicingSyncTrigger::Manual, 1, null], [$saved?->trigger, $saved?->contractors->pushed, $saved?->error]);
        $this->assertNotNull($saved?->finishedAt);

        $this->fakeResponses(['*' => FakturowniaStubs::response('error.401')]);
        $failed = $this->app->make(SyncInvoicing::class)->run($this->account, InvoicingSyncTrigger::Automatic);
        $this->assertFalse($failed->succeeded());
        $this->assertSame([InvoicingSyncTrigger::Automatic, $failed->error], [InvoicingSyncReport::fromArray($this->account->refresh()->last_sync_report)?->trigger, $this->account->last_error]);
    }

    public function test_the_nightly_command_skips_accounts_without_automatic_synchronization_unless_forced(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())->first(static fn(Event $event): bool => str_contains((string) $event->command, 'polygon:invoicing:sync'));
        $this->assertSame('30 2 * * *', $event?->expression);

        $this->account->forceFill([InvoicingAccount::AUTO_SYNC => false])->save();
        $this->fakeResponses([]);
        $this->command('polygon:invoicing:sync')->assertSuccessful();
        Http::assertNothingSent();

        $this->fakeResponses(['*clients.json?*' => FakturowniaStubs::response('clients.empty'), '*clients.json' => FakturowniaStubs::response('client.created'), '*invoices.json?*' => FakturowniaStubs::response('invoices.empty')]);
        $this->command('polygon:invoicing:sync', ['--force' => true])->assertSuccessful();
        $this->assertSame(InvoicingSyncTrigger::Automatic, InvoicingSyncReport::fromArray($this->account->refresh()->last_sync_report)?->trigger);
    }

    public function test_the_on_demand_job_synchronizes_an_active_account_only(): void
    {
        $this->fakeResponses(['*clients.json?*' => FakturowniaStubs::response('clients.empty'), '*clients.json' => FakturowniaStubs::response('client.created'), '*invoices.json?*' => FakturowniaStubs::response('invoices.empty')]);
        $this->app->call([new SyncInvoicingJob($this->account->id), 'handle']);
        $this->assertSame(InvoicingSyncTrigger::Manual, InvoicingSyncReport::fromArray($this->account->refresh()->last_sync_report)?->trigger);

        $this->account->forceFill([ColumnNames::IS_ACTIVE => false, InvoicingAccount::LAST_SYNC_REPORT => null])->save();
        $this->fakeResponses([]);
        $this->app->call([new SyncInvoicingJob($this->account->id), 'handle']);
        $this->assertNull($this->account->refresh()->last_sync_report);
        Http::assertNothingSent();
    }

    public function test_a_pro_forma_issued_in_the_application_is_sent_as_a_pro_forma(): void
    {
        $today = CarbonImmutable::today();
        $invoice = $this->app->make(IssueInvoice::class)->issue($this->clinic, $this->contractor, new InvoiceData($today, $today, [new InvoiceItemData('Badanie', '1', '100', InvoiceTax::Rate, '23')], kind: InvoiceKind::Proforma));
        $this->fakeResponses([
            '*clients.json?*' => FakturowniaStubs::response('clients.empty'),
            '*clients.json' => FakturowniaStubs::response('client.created'),
            '*invoices.json?*oid=*' => FakturowniaStubs::response('invoices.by-oid.empty'),
            '*invoices.json' => FakturowniaStubs::response('invoice.created', ['kind' => 'proforma', 'oid' => $invoice->token]),
        ]);

        $this->app->call([new SendInvoiceJob($invoice->id), 'handle']);

        $this->assertSame([InvoiceKind::Proforma, InvoiceStatus::Sent], [$invoice->refresh()->kind, $invoice->status]);
        Http::assertSent(static fn(Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices.json') && data_get($request->data(), 'invoice.kind') === 'proforma');
    }

    /**
     * Faktura wystawiona w aplikacji, która (po wysyłce bez zapisanych identyfikatorów) jest już w Fakturowni.
     */
    private function issueLocal(InvoiceStatus $status = InvoiceStatus::Sent): Invoice
    {
        $today = CarbonImmutable::today();
        $invoice = $this->app->make(IssueInvoice::class)->issue($this->clinic, $this->contractor, new InvoiceData($today, $today, [new InvoiceItemData('Badanie okresowe', '1', '100', InvoiceTax::Rate, '23')]));
        $invoice->forceFill(['status' => $status])->save();

        return $invoice;
    }

    /**
     * Strona faktur ze stuba; faktura 9001 ma `oid` podanej faktury lokalnej.
     *
     * @return list<array<array-key, mixed>>
     */
    private function page(?Invoice $local = null): array
    {
        $page = array_values(array_filter(FakturowniaStubs::data('invoices.page-1'), is_array(...)));
        $page[3]['oid'] = $local === null ? 'unknown-oid' : $local->token;

        return $page;
    }

    /**
     * @param list<array<array-key, mixed>> $page
     */
    private function fakeInvoices(array $page): void
    {
        $this->fakeResponses([
            '*invoices.json?*' => Http::response($page),
            '*clients/205.json*' => FakturowniaStubs::response('client.by-id'),
        ]);
    }

    /**
     * Pozycje faktury: nazwa, ilość, jednostka, cena netto, opodatkowanie, procent, netto, VAT, brutto.
     *
     * @return list<array{0: string, 1: string, 2: string|null, 3: string, 4: InvoiceTax, 5: string|null, 6: string, 7: string, 8: string}>
     */
    private function items(Invoice $invoice): array
    {
        return array_values($invoice->items()->get()->map(static fn(InvoiceItem $item): array => [$item->name, $item->quantity, $item->unit, $item->unit_price_net, $item->tax, $item->tax_percent, $item->net, $item->vat, $item->gross])->all());
    }

    private function import(): ImportInvoices
    {
        return $this->app->make(ImportInvoices::class);
    }

    /**
     * @param array<string, mixed> $responses
     */
    private function fakeResponses(array $responses): void
    {
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        Http::fake($responses);
    }
}
