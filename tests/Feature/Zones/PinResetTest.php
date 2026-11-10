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

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Simplysmart\Polygon\Auth\AccessRevocationReason;
use Simplysmart\Polygon\Auth\Events\AccountAccessRevoked;
use Simplysmart\Polygon\Auth\ResetOwnPin;
use Simplysmart\Polygon\Http\StatusMessage;
use Simplysmart\Polygon\Mail\PinResetMail;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Zones\AlphaAccount;

/**
 * Reset PIN-u przez właściciela zalogowanego konta (trasa `account.pin.reset`, Auth\ResetOwnPin): obecny PIN, potem
 * PIN usunięty, nowa epoka (wszystkie sesje wylogowane), bieżąca sesja wylogowana z komunikatem, a następne logowanie
 * kodem prowadzi do ustawienia nowego PIN-u; właściciel dostaje potwierdzenie e-mailem (Mail\PinResetMail).
 */
class PinResetTest extends SignInTestCase
{
    private const string NEW_PIN = '284619';

    public function test_account_resets_its_pin_with_the_current_pin_is_signed_out_and_sets_a_new_pin_at_the_next_sign_in(): void
    {
        Event::fake([AccountAccessRevoked::class]);
        $account = $this->account(AlphaAccount::class, pin: self::PIN);
        $this->signedIn($account, 'alpha');

        $this->resetPin(self::PIN)
            ->assertRedirect(route('alpha.auth.login'))
            ->assertSessionHas(StatusMessage::KEY, text('account.pin_reset_done'));

        $account->refresh();
        $this->assertFalse($account->hasPin());
        $this->assertSame(1, $account->authEpoch());
        $this->assertGuest('alpha');
        $this->get('/alpha/dashboard')->assertRedirect(route('alpha.auth.login'));
        Event::assertDispatched(AccountAccessRevoked::class, static fn(AccountAccessRevoked $event): bool => $event->reason === AccessRevocationReason::PinResetByOwner);
        Mail::assertQueued(PinResetMail::class, static fn(PinResetMail $mail): bool => $mail->hasTo(self::EMAIL) && $mail->accountName === 'Anna Nowak');
        $this->assertStringContainsString('data-template="pin-reset"', (new PinResetMail('alpha', 'Anna Nowak', now()))->render());

        $this->requestCode();
        $this->submitCode($this->sentCode())->assertRedirect(route('alpha.auth.pin-setup'));
        $this->post(route('alpha.auth.pin-setup.store'), ['pin' => self::NEW_PIN, 'pin_confirmation' => self::NEW_PIN])->assertRedirect('/alpha/dashboard');
        $this->assertTrue(Hash::check(self::NEW_PIN, (string) $account->fresh()?->pinHash()));
    }

    public function test_wrong_current_pin_keeps_the_pin_and_the_session_and_is_counted(): void
    {
        $account = $this->account(AlphaAccount::class, pin: self::PIN);
        $this->signedIn($account, 'alpha');

        $this->resetPin('951357')->assertRedirect();
        $account->refresh();

        $this->assertSessionError(ResetOwnPin::FIELD, text('account.pin_invalid'));
        $this->assertSame(1, $account->failedPinAttempts());
        $this->assertTrue(Hash::check(self::PIN, (string) $account->pinHash()));
        $this->assertSame(0, $account->authEpoch());
        $this->get('/alpha/dashboard')->assertOk();
        Mail::assertNotQueued(PinResetMail::class);
    }

    public function test_guest_cannot_reset_a_pin(): void
    {
        $this->resetPin(self::PIN)->assertRedirect(route('alpha.auth.login'));
    }

    /**
     * @return TestResponse<Response>
     */
    private function resetPin(string $currentPin): TestResponse
    {
        return $this->delete('/alpha/account/pin', [ResetOwnPin::FIELD => $currentPin]);
    }
}
