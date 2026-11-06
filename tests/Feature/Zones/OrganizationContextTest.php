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
use Simplysmart\Polygon\Auth\CreateZoneAccount;
use Simplysmart\Polygon\Contracts\ZoneRegistry;
use Simplysmart\Polygon\Database\Seeders\HeroOrganizationSeeder;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\OrganizationMember;
use Simplysmart\Polygon\Models\ZoneAccount;
use Simplysmart\Polygon\Organizations\OrganizationMembership;
use Simplysmart\Polygon\Permissions\RequiredRole;
use Tests\Fixtures\Zones\AlphaAccount;
use Tests\Fixtures\Zones\BetaAccount;

/**
 * Kontekst organizacji konta zalogowanego w strefie (Contracts\CurrentOrganization, trasa
 * `account.organization.switch`): domyślnie najstarsze członkostwo, przełączenie tylko na własną organizację,
 * osobny kontekst w każdej strefie; organizacja hero (Simplysmart) — członkostwo kont z rolą hero, dostęp hero do
 * wszystkich aktywnych organizacji i brak organizacji hero na liście frontendu.
 */
class OrganizationContextTest extends ZoneTestCase
{
    public function test_account_works_in_its_oldest_membership_and_switches_between_its_organizations(): void
    {
        $account = $this->account(AlphaAccount::class);
        $north = $this->organization('Północ');
        $south = $this->organization('Południe');
        $this->member($account, $north);
        $this->member($account, $south);

        $this->signedIn($account, 'alpha')->get('/alpha/organization')->assertOk()->assertExactJson([
            'id' => $north->id,
            'visible' => 'Północ',
            'hero' => false,
            'contexts' => [['Północ', true], ['Południe', false]],
        ]);

        $this->post('/alpha/account/organization', ['organization' => $south->token])
            ->assertRedirect(route('alpha.dashboard'))
            ->assertSessionHas(StatusMessage::KEY, text('account.organization.switched', ['name' => 'Południe']));
        $this->get('/alpha/organization')->assertJsonPath('id', $south->id)->assertJsonPath('contexts', [['Północ', false], ['Południe', true]]);
    }

    public function test_account_cannot_switch_to_an_organization_it_does_not_belong_to(): void
    {
        $account = $this->account(AlphaAccount::class);
        $own = $this->organization('Własna');
        $foreign = $this->organization('Obca');
        $this->member($account, $own);

        $this->signedIn($account, 'alpha')->post('/alpha/account/organization', ['organization' => $foreign->token])->assertRedirect();
        $this->assertSessionError('organization', text('account.organization.unavailable'));
        $this->get('/alpha/organization')->assertJsonPath('id', $own->id);
    }

    public function test_inactive_organization_is_no_context_and_the_choice_falls_back(): void
    {
        $account = $this->account(AlphaAccount::class);
        $first = $this->organization('Pierwsza');
        $second = $this->organization('Druga');
        $this->member($account, $first);
        $this->member($account, $second);
        $this->signedIn($account, 'alpha')->post('/alpha/account/organization', ['organization' => $second->token]);

        $second->forceFill(['is_active' => false])->save();

        $this->get('/alpha/organization')->assertJsonPath('id', $first->id)->assertJsonPath('contexts', [['Pierwsza', true]]);
    }

    public function test_account_without_organizations_has_no_context(): void
    {
        $this->signedIn($this->account(AlphaAccount::class), 'alpha')->get('/alpha/organization')->assertExactJson([
            'id' => null,
            'visible' => null,
            'hero' => false,
            'contexts' => [],
        ]);
    }

    public function test_each_zone_keeps_its_own_context(): void
    {
        $organization = $this->organization('Wspólna');
        $alpha = $this->account(AlphaAccount::class);
        $beta = $this->account(BetaAccount::class);
        $this->member($alpha, $organization);

        $this->signedIn($alpha, 'alpha')->get('/alpha/organization')->assertJsonPath('id', $organization->id);
        $this->signedIn($beta, 'beta');
        $this->assertSame([], $beta->organizations()->pluck('organizations.id')->all());
    }

    public function test_account_created_with_the_hero_role_joins_the_hero_organization(): void
    {
        $this->seed(HeroOrganizationSeeder::class);
        $this->role(RequiredRole::ROOT->value, 'alpha');
        $this->role('manager', 'alpha');
        $create = $this->app->make(CreateZoneAccount::class);
        $zone = $this->app->make(ZoneRegistry::class)->get('alpha');

        $hero = $create->handle($zone, 'Hero', 'hero@example.com', [RequiredRole::ROOT->value]);
        $manager = $create->handle($zone, 'Manager', 'manager@example.com', ['manager']);

        $this->assertSame([Organization::HERO_ID], $hero->organizations()->pluck('organizations.id')->all());
        $this->assertSame([], $manager->organizations()->pluck('organizations.id')->all());
    }

    public function test_hero_works_in_the_hidden_hero_organization_and_may_enter_every_active_organization(): void
    {
        $this->seed(HeroOrganizationSeeder::class);
        $account = $this->account(AlphaAccount::class);
        $this->member($account, Organization::query()->findOrFail(Organization::HERO_ID));
        $clinic = $this->organization('Przychodnia');
        $this->organization('Apteka');
        $this->organization('Zamknięta')->forceFill(['is_active' => false])->save();

        $this->signedIn($account, 'alpha')->get('/alpha/organization')->assertExactJson([
            'id' => Organization::HERO_ID,
            'visible' => null,
            'hero' => true,
            'contexts' => [['Apteka', false], ['Przychodnia', false]],
        ]);

        $this->post('/alpha/account/organization', ['organization' => $clinic->token])->assertRedirect(route('alpha.dashboard'));
        $this->get('/alpha/organization')->assertJsonPath('id', $clinic->id)->assertJsonPath('visible', 'Przychodnia')->assertJsonPath('hero', true);
        $this->assertSame(1, OrganizationMember::query()->count(), 'Hero nie dostaje członkostw w organizacjach klientów.');
    }

    public function test_guest_cannot_switch_the_organization(): void
    {
        $organization = $this->organization('Firma');

        $this->post('/alpha/account/organization', ['organization' => $organization->token])->assertRedirect(route('alpha.auth.login'));
    }


    private function member(ZoneAccount $account, Organization $organization): void
    {
        $this->app->make(OrganizationMembership::class)->add($account, $organization);
    }
}
