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
use Tests\Fixtures\Zones\AlphaAccount;

/**
 * Strona bezpieczeństwa konta (widok strefy z danymi polygon), wylogowanie ze wszystkich urządzeń, dawne adresy sekcji
 * przekierowujące do kotwic oraz blokada ekranu.
 */
class AccountSecurityTest extends SignInTestCase
{
    public function test_security_page_renders_the_zone_view_with_the_account_data(): void
    {
        $this->account(AlphaAccount::class, pin: self::PIN);
        $this->signIn();

        $view = $this->get('/alpha/account/security')->assertOk()->assertSee('data-screen="security"', false);

        $view->assertViewHas('zone');
        $view->assertViewHas('account');
        $view->assertViewHas('devices', []);
        $view->assertViewHas('recoveryEmail', null);
    }

    public function test_sign_out_everywhere_ends_every_session_and_returns_to_sign_in(): void
    {
        $account = $this->account(AlphaAccount::class, pin: self::PIN);
        $this->signIn();
        $epoch = $account->fresh()?->authEpoch();

        $this->delete('/alpha/account/security/sessions')
            ->assertRedirect(route('alpha.auth.login'))
            ->assertSessionHas(StatusMessage::KEY, text('account.signed_out_everywhere'));

        $this->assertGuest('alpha');
        $this->assertNotSame($epoch, $account->fresh()?->authEpoch());
        $this->get('/alpha/account/security')->assertRedirect(route('alpha.auth.login'));
    }

    public function test_old_section_addresses_lead_to_the_security_page(): void
    {
        $this->signedIn($this->account(AlphaAccount::class), 'alpha');

        $this->get('/alpha/account/pin')->assertRedirect(url('/alpha/account/security') . '#pin');
        $this->get('/alpha/account/devices')->assertRedirect(url('/alpha/account/security') . '#devices');
    }

    public function test_screen_lock_keeps_the_session_and_unlocks_with_the_pin_back_on_the_same_page(): void
    {
        $this->signedIn($this->account(AlphaAccount::class, pin: self::PIN), 'alpha');

        $this->from(url('/alpha/page'))->post('/alpha/account/lock')->assertRedirect(url('/alpha/account/unlock'));
        $this->get('/alpha/page')->assertRedirect(url('/alpha/account/unlock'));
        $this->get('/alpha/account/unlock')->assertOk()->assertSee('data-screen="unlock"', false)->assertSee('Anna Nowak');

        $this->post('/alpha/account/unlock', ['pin' => '951357'])->assertRedirect();
        $this->assertSame(text('account.screen_lock.invalid'), $this->sessionError('pin'));
        $this->get('/alpha/page')->assertRedirect(url('/alpha/account/unlock'));

        $this->post('/alpha/account/unlock', ['pin' => self::PIN])->assertRedirect(url('/alpha/page'));
        $this->get('/alpha/page')->assertOk()->assertSee('alpha page');
    }

    public function test_locked_screen_still_allows_signing_out(): void
    {
        $this->signedIn($this->account(AlphaAccount::class, pin: self::PIN), 'alpha');

        $this->post('/alpha/account/lock');
        $this->get('/alpha/account/unlock')->assertOk();
        $this->post(route('alpha.auth.logout'))->assertRedirect();
        $this->get('/alpha/page')->assertRedirect(route('alpha.auth.login'));
    }

    private function signIn(): void
    {
        $this->requestCode();
        $this->submitCode($this->sentCode());
        $this->submitPin(self::PIN)->assertRedirect('/alpha/dashboard');
    }
}
