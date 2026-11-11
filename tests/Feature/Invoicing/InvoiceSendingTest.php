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
use Illuminate\Support\Sleep;
use Simplysmart\Polygon\Contractors\ContractorData;
use Simplysmart\Polygon\Contractors\SaveContractor;
use Simplysmart\Polygon\Enums\InvoiceStatus;
use Simplysmart\Polygon\Enums\InvoiceTax;
use Simplysmart\Polygon\Enums\PaymentMethod;
use Simplysmart\Polygon\Integrations\Fakturownia\FakturowniaFailed;
use Simplysmart\Polygon\Invoicing\InvoiceAmounts;
use Simplysmart\Polygon\Invoicing\InvoiceData;
use Simplysmart\Polygon\Invoicing\InvoiceItemData;
use Simplysmart\Polygon\Invoicing\InvoicePdf;
use Simplysmart\Polygon\Invoicing\InvoicingRejected;
use Simplysmart\Polygon\Invoicing\IssueInvoice;
use Simplysmart\Polygon\Invoicing\SaveInvoicingAccount;
use Simplysmart\Polygon\Invoicing\SendInvoice;
use Simplysmart\Polygon\Jobs\SendInvoiceJob;
use Simplysmart\Polygon\Models\Contractor;
use Simplysmart\Polygon\Models\Invoice;
use Simplysmart\Polygon\Models\InvoicingAccount;
use Simplysmart\Polygon\Models\Organization;
use Tests\Fixtures\Fakturownia\FakturowniaStubs;
use Tests\TestCase;

/**
 * Faktury organizacji głównej z polygonu (docs/baza-danych.md §21) na stubach odpowiedzi Fakturowni: kwoty pozycji
 * i sumy na liczbach dziesiętnych, migawka stron, odmowy przy wystawieniu, wysyłka z identyfikatorami zewnętrznymi,
 * ponowiona wysyłka bez drugiej faktury (wyszukanie po `oid`, zajęty `oid`), błąd przejściowy w kolejce, błąd trwały,
 * ponowienie ręczne i komendą, PDF przez serwer.
 */
class InvoiceSendingTest extends TestCase
{
    use RefreshDatabase;

    private const string NIP = '5252344078';

    private Organization $root;

    private Organization $clinic;

    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        Sleep::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', 'Europe/Warsaw'));
        $this->root = $this->organization('NZOZ Test', ['nip' => '5261040828', 'street' => 'ul. Zdrowa 2', 'postal_code' => '90-001', 'city' => 'Łódź', 'country_code' => 'PL']);
        $this->clinic = $this->organization('Poradnia', ['parent_id' => $this->root->id]);
        $this->fakeResponses(['*account.json*' => FakturowniaStubs::response('account.ok')]);
        $this->app->make(SaveInvoicingAccount::class)->save($this->root, 'nzoz-test', 'secret-api-token', '77');
        $this->contractor = $this->app->make(SaveContractor::class)->save($this->clinic, new ContractorData('Firma Budowlana', nip: self::NIP, street: 'ul. Prosta 1', postalCode: '00-001', city: 'Warszawa', countryCode: 'PL'));
    }

    public function test_item_amounts_round_to_grosze_without_floats(): void
    {
        $this->assertSame(['net' => '200.00', 'vat' => '0.00', 'gross' => '200.00'], InvoiceAmounts::of('2', '100', InvoiceTax::Exempt, null));
        $this->assertSame(['net' => '10.00', 'vat' => '2.30', 'gross' => '12.30'], InvoiceAmounts::of('1', '10', InvoiceTax::Rate, '23'));
        $this->assertSame(['net' => '0.33', 'vat' => '0.08', 'gross' => '0.41'], InvoiceAmounts::of('3', '0.11', InvoiceTax::Rate, '23'), '0.0759 VAT-u zaokrągla się w górę.');
        $this->assertSame(['net' => '1234.50', 'vat' => '98.76', 'gross' => '1333.26'], InvoiceAmounts::of('1,5', '823', InvoiceTax::Rate, '8'));
        $this->assertSame(['1234.50', null, null], [InvoiceAmounts::decimal('1 234,50'), InvoiceAmounts::decimal('-1'), InvoiceAmounts::decimal('1e3')]);
        $this->assertSame('3.03', InvoiceAmounts::sum(['1.01', '2.02']));
    }

    public function test_issuing_saves_snapshots_and_totals_and_queues_the_sending(): void
    {
        $invoice = $this->issue();

        $this->assertSame([InvoiceStatus::Queued, $this->root->id, $this->contractor->id, 'PLN'], [$invoice->status, $invoice->organization_id, $invoice->contractor_id, $invoice->currency_code]);
        $this->assertStringStartsWith(IssueInvoice::DRAFT_PREFIX . '20261110/', $invoice->number());
        $this->assertSame(['210.00', '2.30', '212.30'], [$invoice->total_net, $invoice->total_vat, $invoice->total_gross]);
        $this->assertSame(['NZOZ Test', '5261040828', 'Łódź', 'Firma Budowlana', self::NIP, 'Warszawa'], [$invoice->seller_name, $invoice->seller_nip, $invoice->seller_city, $invoice->buyer_name, $invoice->buyer_nip, $invoice->buyer_city]);
        $this->assertSame([['Badanie okresowe', 'usł.', InvoiceTax::Exempt, null, '200.00'], ['Druk', null, InvoiceTax::Rate, '23.00', '12.30']], $invoice->items->map(static fn($item): array => [$item->name, $item->unit, $item->tax, $item->tax_percent, $item->gross])->all());
        Queue::assertPushed(SendInvoiceJob::class, static fn(SendInvoiceJob $job): bool => $job->invoiceId === $invoice->id);

        $this->contractor->forceFill(['name' => 'Nowa nazwa', 'city' => 'Kraków'])->save();
        $this->assertSame(['Firma Budowlana', 'Warszawa'], [$invoice->refresh()->buyer_name, $invoice->buyer_city], 'Faktura trzyma dane nabywcy z dnia wystawienia.');
    }

    public function test_issuing_refuses_invalid_data_with_the_form_field(): void
    {
        $issue = $this->app->make(IssueInvoice::class);
        $today = CarbonImmutable::today();
        $item = new InvoiceItemData('Usługa', '1', '100', InvoiceTax::Rate, '23');
        $cases = [
            'items' => new InvoiceData($today, $today, []),
            'items.0.name' => new InvoiceData($today, $today, [new InvoiceItemData(' ', '1', '10')]),
            'items.1.quantity' => new InvoiceData($today, $today, [$item, new InvoiceItemData('Druk', '0', '10', InvoiceTax::Rate, '23')]),
            'items.0.unit_price_net' => new InvoiceData($today, $today, [new InvoiceItemData('Usługa', '1', 'dużo', InvoiceTax::Rate, '23')]),
            'items.0.tax_percent' => new InvoiceData($today, $today, [new InvoiceItemData('Usługa', '1', '10', InvoiceTax::Rate, '123')]),
            'payment_due' => new InvoiceData($today, $today, [$item], $today->subDay()),
            'currency_code' => new InvoiceData($today, $today, [$item], currencyCode: 'złote'),
        ];

        foreach ($cases as $field => $data) {
            $this->assertSame($field, $this->rejectedField(fn() => $issue->issue($this->clinic, $this->contractor, $data)), $field);
        }

        $stranger = $this->app->make(SaveContractor::class)->save($this->organization('Inna firma'), new ContractorData('Obcy kontrahent'));
        $this->assertSame('contractor_id', $this->rejectedField(fn() => $issue->issue($this->clinic, $stranger, new InvoiceData($today, $today, [$item]))));
        $this->assertSame('account', $this->rejectedField(fn() => $issue->issue($this->organization('Bez konta'), $stranger, new InvoiceData($today, $today, [$item]))));
        $this->assertSame(0, Invoice::query()->count());
        Queue::assertNotPushed(SendInvoiceJob::class);
    }

    public function test_sending_creates_the_client_and_the_invoice_and_stores_the_external_identifiers(): void
    {
        $invoice = $this->issue();
        $this->fakeResponses([
            '*clients.json?*' => FakturowniaStubs::response('clients.empty'),
            '*clients.json' => FakturowniaStubs::response('client.created'),
            '*invoices.json?*oid=*' => FakturowniaStubs::response('invoices.by-oid.empty'),
            '*invoices.json' => FakturowniaStubs::response('invoice.created', ['oid' => $invoice->token]),
        ]);

        $this->runJob($invoice);

        $invoice->refresh();
        $this->assertSame([InvoiceStatus::Sent, '9001', 'aBcD1234invoiceToken', '12/11/2026', '12/11/2026', 'https://nzoz-test.fakturownia.pl/invoice/aBcD1234invoiceToken', null], [$invoice->status, $invoice->external_id, $invoice->external_token, $invoice->external_number, $invoice->number(), $invoice->external_url, $invoice->error]);
        $this->assertNotNull($invoice->sent_at);
        Http::assertSent(static fn(Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices.json')
            && data_get($request->data(), 'invoice.oid') === $invoice->token && data_get($request->data(), 'invoice.oid_unique') === 'yes'
            && data_get($request->data(), 'invoice.client_id') === 201 && data_get($request->data(), 'invoice.department_id') === 77
            && data_get($request->data(), 'invoice.positions.0.tax') === 'zw' && data_get($request->data(), 'invoice.positions.1.tax') === 23);

        $this->fakeResponses([]);
        $this->runJob($invoice);
        Http::assertNothingSent();
    }

    public function test_a_retried_sending_does_not_create_a_second_invoice(): void
    {
        $invoice = $this->issue();
        $this->fakeResponses([
            '*clients.json?*' => FakturowniaStubs::response('clients.empty'),
            '*clients.json' => FakturowniaStubs::response('client.created'),
            '*invoices.json?*oid=*' => Http::response([FakturowniaStubs::data('invoice.created', ['oid' => $invoice->token])]),
        ]);

        $this->runJob($invoice);

        $this->assertSame([InvoiceStatus::Sent, '9001'], [$invoice->refresh()->status, $invoice->external_id], 'Faktura znaleziona po oid — poprzednia próba doszła.');
        Http::assertNotSent(static fn(Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices.json'));

        $other = $this->issue();
        $this->fakeResponses([
            '*clients.json?*' => FakturowniaStubs::response('clients.empty'),
            '*invoices.json?*oid=*' => Http::sequence()->pushResponse(FakturowniaStubs::response('invoices.by-oid.empty'))->pushResponse(Http::response([FakturowniaStubs::data('invoice.created', ['id' => 9002, 'oid' => $other->token, 'number' => '13/11/2026'])])),
            '*invoices.json' => FakturowniaStubs::response('invoice.oid-taken'),
        ]);

        $this->runJob($other);

        $this->assertSame([InvoiceStatus::Sent, '9002', '13/11/2026'], [$other->refresh()->status, $other->external_id, $other->number()], 'Zajęty oid kończy się odczytem istniejącej faktury.');
    }

    public function test_a_temporary_failure_keeps_the_invoice_queued_and_a_permanent_one_marks_it_failed(): void
    {
        $invoice = $this->issue();
        $this->fakeResponses([
            '*clients.json?*' => FakturowniaStubs::response('clients.empty'),
            '*clients.json' => FakturowniaStubs::response('client.created'),
            '*invoices.json?*oid=*' => FakturowniaStubs::response('invoices.by-oid.empty'),
            '*invoices.json' => FakturowniaStubs::response('error.500'),
        ]);

        try {
            $this->app->make(SendInvoice::class)->send($invoice);
            $this->fail('Błąd serwera powinien wrócić do kolejki.');
        } catch (FakturowniaFailed $failed) {
            $this->assertTrue($failed->retryable());
        }

        $this->assertSame([InvoiceStatus::Queued, 'Internal server error'], [$invoice->refresh()->status, $invoice->error]);

        $this->runJob($invoice);
        $this->assertSame(InvoiceStatus::Queued, $invoice->refresh()->status, 'Bez prawdziwej kolejki (sync) zadanie nie przerywa żądania wyjątkiem — faktura czeka w kolejce.');

        $this->fakeResponses([
            '*invoices.json?*oid=*' => FakturowniaStubs::response('invoices.by-oid.empty'),
            '*invoices.json' => FakturowniaStubs::response('error.422'),
        ]);
        $this->runJob($invoice);
        $this->assertSame([InvoiceStatus::Failed, 'buyer_name: nie może być puste; positions: nie może być puste'], [$invoice->refresh()->status, $invoice->error]);

        (new SendInvoiceJob($invoice->id))->failed(null);
        $this->assertSame(InvoiceStatus::Failed, $invoice->refresh()->status);

        $this->app->make(SendInvoice::class)->resend($invoice);
        $this->assertSame([InvoiceStatus::Queued, null], [$invoice->refresh()->status, $invoice->error]);
        Queue::assertPushed(SendInvoiceJob::class, 2);

        $this->fakeResponses([
            '*invoices.json?*oid=*' => FakturowniaStubs::response('invoices.by-oid.empty'),
            '*invoices.json' => FakturowniaStubs::response('invoice.created', ['oid' => $invoice->token]),
        ]);
        $this->command('polygon:invoicing:invoices-send')->expectsOutputToContain('Wysłane: 1, z błędem: 0.')->assertSuccessful();
        $this->assertSame(InvoiceStatus::Sent, $invoice->refresh()->status);
    }

    public function test_the_job_marks_a_queued_invoice_failed_when_retries_run_out(): void
    {
        $invoice = $this->issue();

        (new SendInvoiceJob($invoice->id))->failed(null);

        $this->assertSame([InvoiceStatus::Failed, text('polygon::invoicing.send_failed')], [$invoice->refresh()->status, $invoice->error]);
    }

    public function test_the_pdf_is_downloaded_by_the_server_only_for_a_sent_invoice(): void
    {
        $invoice = $this->issue();
        $this->assertNull($this->app->make(InvoicePdf::class)->of($invoice));

        $invoice->forceFill([Invoice::EXTERNAL_ID => '9001', 'status' => InvoiceStatus::Sent])->save();
        $this->fakeResponses(['*invoices/9001.pdf*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);
        $pdf = $this->app->make(InvoicePdf::class);

        $this->assertSame('%PDF-1.4', $pdf->of($invoice));
        Http::assertSent(static fn(Request $request): bool => $request['api_token'] === 'secret-api-token');

        InvoicingAccount::query()->update(['is_active' => false]);
        $this->assertNull($pdf->of($invoice->refresh()));
    }

    private function issue(): Invoice
    {
        $today = CarbonImmutable::today();

        return $this->app->make(IssueInvoice::class)->issue($this->clinic, $this->contractor, new InvoiceData($today, $today->subDay(), [
            new InvoiceItemData('Badanie okresowe', '2', '100', InvoiceTax::Exempt, unit: 'usł.'),
            new InvoiceItemData('Druk', '1', '10,00', InvoiceTax::Rate, '23'),
        ], $today->addDays(14), PaymentMethod::Transfer, notes: 'Badania pracowników'));
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

    private function runJob(Invoice $invoice): void
    {
        $this->app->call([new SendInvoiceJob($invoice->id), 'handle']);
    }

    private function rejectedField(callable $action): string
    {
        try {
            $action();
        } catch (InvoicingRejected $rejected) {
            return $rejected->field;
        }

        $this->fail('Oczekiwano odmowy wystawienia faktury.');
    }
}
