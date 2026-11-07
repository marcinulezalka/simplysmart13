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

namespace Tests\Feature\Zones;

use Simplysmart\Polygon\Http\StatusMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Simplysmart\Polygon\Auth\SignIn\IssueSignInCode;
use Simplysmart\Polygon\Mail\LoginCodeMail;
use Simplysmart\Polygon\Models\Permissions\Permission;
use Simplysmart\Polygon\Organizations\OrganizationMembership;
use Simplysmart\Polygon\Permissions\OrganizationRoles;
use Tests\Fixtures\Zones\AlphaAccount;
use Tests\Fixtures\Zones\AlphaZoneHome;

/**
 * Przepływ logowania: e-mail → kod → PIN (albo ustawienie PIN-u), decoy dla nieznanych
 * i nieaktywnych kont, tylko najnowszy kod, brak przeskakiwania kroków, czas kroków i strona startowa konta wybrana przez
 * strefę (Contracts\ZoneHome, uprawnienia w bieżącej organizacji).
 */
class SignInFlowTest extends SignInTestCase
{
    public function test_account_with_a_pin_signs_in_with_the_code_and_the_pin(): void
    {
        $account = $this->account(AlphaAccount::class, pin: self::PIN);

        $this->requestCode()->assertRedirect('/alpha/signin/code');
        $this->submitCode($this->sentCode())->assertRedirect('/alpha/signin/pin');
        $this->submitPin(self::PIN)->assertRedirect('/alpha/dashboard');

        $this->assertAuthenticatedAs($account, 'alpha');
        $this->assertNotNull($account->fresh()?->getAttribute('last_login_at'));
    }

    public function test_account_without_a_pin_sets_it_after_the_code(): void
    {
        $account = $this->account(AlphaAccount::class);

        $this->requestCode();
        $this->submitCode($this->sentCode())->assertRedirect('/alpha/signin/pin-setup');
        $this->post('/alpha/signin/pin-setup', ['pin' => self::PIN, 'pin_confirmation' => self::PIN])->assertRedirect('/alpha/dashboard');

        $this->assertAuthenticatedAs($account, 'alpha');
        $this->assertTrue($account->fresh()?->hasPin());
    }

    public function test_sign_in_returns_to_the_page_opened_before_it(): void
    {
        $this->account(AlphaAccount::class, pin: self::PIN);

        $this->get('/alpha/page')->assertRedirect('/alpha/signin');
        $this->requestCode();
        $this->submitCode($this->sentCode());
        $this->submitPin(self::PIN)->assertRedirect('/alpha/page');
    }

    public function test_first_sign_in_goes_to_the_dashboard_instead_of_the_page_opened_before_it(): void
    {
        $this->account(AlphaAccount::class);

        $this->get('/alpha/page')->assertRedirect('/alpha/signin');
        $this->requestCode();
        $this->submitCode($this->sentCode());
        $this->post('/alpha/signin/pin-setup', ['pin' => self::PIN, 'pin_confirmation' => self::PIN])->assertRedirect('/alpha/dashboard');

        $this->assertNull(session('url.intended'));
    }

    public function test_sign_in_goes_to_the_home_the_zone_chooses_for_the_account(): void
    {
        $account = $this->account(AlphaAccount::class, pin: self::PIN);
        $organization = $this->organization('Północ');
        $this->role('manager', 'alpha')->givePermissionTo($this->permission(AlphaZoneHome::PERMISSION));
        $this->app->make(OrganizationMembership::class)->add($account, $organization);
        $this->app->make(OrganizationRoles::class)->assign($account, ['manager'], $organization->id);

        $this->requestCode();
        $this->submitCode($this->sentCode());
        $this->submitPin(self::PIN)->assertRedirect('/alpha/' . AlphaZoneHome::ROUTE);
    }

    public function test_first_sign_in_goes_to_the_home_the_zone_chooses_for_the_account(): void
    {
        $account = $this->account(AlphaAccount::class);
        $this->role('manager', 'alpha')->givePermissionTo($this->permission(AlphaZoneHome::PERMISSION));
        $account->assignRole('manager');

        $this->get('/alpha/page')->assertRedirect('/alpha/signin');
        $this->requestCode();
        $this->submitCode($this->sentCode());
        $this->post('/alpha/signin/pin-setup', ['pin' => self::PIN, 'pin_confirmation' => self::PIN])->assertRedirect('/alpha/' . AlphaZoneHome::ROUTE);
    }

    public function test_a_home_route_that_does_not_exist_falls_back_to_the_dashboard(): void
    {
        $this->account(AlphaAccount::class, ['email' => AlphaZoneHome::MISSING_ROUTE_EMAIL], self::PIN);

        $this->requestCode(AlphaZoneHome::MISSING_ROUTE_EMAIL);
        $this->submitCode($this->sentCode(AlphaZoneHome::MISSING_ROUTE_EMAIL));
        $this->submitPin(self::PIN)->assertRedirect('/alpha/dashboard');
    }

    public function test_weak_pin_is_rejected(): void
    {
        $this->account(AlphaAccount::class);

        $this->requestCode();
        $this->submitCode($this->sentCode());
        $this->post('/alpha/signin/pin-setup', ['pin' => '123456', 'pin_confirmation' => '123456'])->assertSessionHasErrors('pin');

        $this->assertGuest('alpha');
    }

    public function test_unknown_address_gets_the_same_answer_and_no_mail(): void
    {
        $this->requestCode('nobody@example.com')->assertRedirect('/alpha/signin/code');
        $this->submitCode('123456')->assertSessionHasErrors(['code' => text('sign_in.failure')]);

        Mail::assertNothingSent();
    }

    public function test_inactive_account_gets_the_decoy_flow(): void
    {
        $this->account(AlphaAccount::class, ['is_active' => false], self::PIN);

        $this->requestCode()->assertRedirect('/alpha/signin/code');

        Mail::assertNothingSent();
    }

    public function test_known_and_unknown_addresses_take_the_same_path(): void
    {
        Queue::fake();
        $this->account(AlphaAccount::class, pin: self::PIN);

        $known = $this->requestCode();
        $unknown = $this->requestCode('nobody@example.com');

        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        Queue::assertPushed(IssueSignInCode::class, 2);
    }

    public function test_only_the_newest_code_is_valid(): void
    {
        $this->account(AlphaAccount::class, pin: self::PIN);

        $this->requestCode();
        $first = $this->sentCode();

        $this->travel(61)->seconds();
        $this->post('/alpha/signin/code/resend')->assertRedirect('/alpha/signin/code');

        $second = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$second): bool {
            $second = $mail->code;

            return true;
        });

        if ($first !== $second) {
            $this->submitCode($first)->assertSessionHasErrors('code');
        }

        $this->submitCode((string) $second)->assertRedirect('/alpha/signin/pin');
    }

    public function test_double_submit_of_the_address_sends_one_code(): void
    {
        $this->account(AlphaAccount::class, pin: self::PIN);

        $this->requestCode();
        $this->requestCode();

        Mail::assertSentCount(1);
    }

    public function test_code_is_stored_only_as_a_hash(): void
    {
        $this->account(AlphaAccount::class, pin: self::PIN);

        $this->requestCode();
        $code = $this->sentCode();

        $this->assertDatabaseMissing('alpha_login_challenges', ['code_hash' => $code]);
        $hash = DB::table('alpha_login_challenges')->value('code_hash');
        $this->assertIsString($hash);
        $this->assertSame(64, strlen($hash));
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $this->account(AlphaAccount::class, pin: self::PIN);

        $this->get('/alpha/signin/pin')->assertRedirect('/alpha/signin');

        $this->requestCode();
        $this->get('/alpha/signin/pin')->assertRedirect('/alpha/signin')->assertSessionHasErrors(['email' => text('sign_in.expired')]);
    }

    public function test_the_code_step_expires(): void
    {
        $this->account(AlphaAccount::class, pin: self::PIN);

        $this->requestCode();
        $code = $this->sentCode();
        $this->travel(11)->minutes();

        $this->submitCode($code)->assertRedirect('/alpha/signin');
        $this->assertGuest('alpha');
    }

    public function test_signed_in_account_skips_the_sign_in_pages(): void
    {
        $this->signedIn($this->account(AlphaAccount::class, pin: self::PIN), 'alpha')
            ->get('/alpha/signin')
            ->assertRedirect('/alpha/dashboard');
    }

    public function test_sign_out_ends_the_zone_session(): void
    {
        $this->signedIn($this->account(AlphaAccount::class, pin: self::PIN), 'alpha')
            ->post('/alpha/signin/out')
            ->assertRedirect('/alpha/signin');

        $this->assertGuest('alpha');
    }

    public function test_sign_out_says_goodbye_on_the_sign_in_page(): void
    {
        $this->signedIn($this->account(AlphaAccount::class, pin: self::PIN), 'alpha')
            ->post('/alpha/signin/out')
            ->assertRedirect('/alpha/signin')
            ->assertSessionHas(StatusMessage::KEY, text('sign_in.signed_out'))
            ->assertCookie('alpha_session');
    }


    public function test_forgot_pin_page_explains_the_reset_and_leads_back_to_sign_in(): void
    {
        $this->get('/alpha/signin/forgot-pin')
            ->assertOk()
            ->assertSee('data-screen="forgot_pin"', false)
            ->assertViewHas('codePending', false);
    }


    public function test_expired_form_sends_back_to_the_zone_sign_in(): void
    {
        $this->post('/alpha/expired-form')
            ->assertRedirect('/alpha/signin')
            ->assertSessionHasErrors(['email' => text('sign_in.page_expired')]);
    }

    public function test_e_mail_is_encrypted_and_found_by_its_index(): void
    {
        $this->account(AlphaAccount::class, ['email' => ' Anna@Example.COM ']);

        $row = DB::table('alpha_accounts')->first();

        $this->assertNotNull($row);
        $this->assertNotSame(self::EMAIL, $row->email);
        $this->assertIsString($row->email_hash);
        $this->assertSame(64, strlen($row->email_hash));
        $this->requestCode();
        $this->assertNotSame('', $this->sentCode());
    }

    private function permission(string $name): Permission
    {
        $permission = new Permission();
        $permission->forceFill(['name' => $name, 'guard_name' => 'alpha'])->save();

        return $permission;
    }
}
