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

use Simplysmart\Polygon\Database\ZoneTables;
use Simplysmart\Polygon\Zones\ZoneUrlKey;
use Tests\Fixtures\Zones\GammaAccount;
use Tests\Fixtures\Zones\GammaZoneServiceProvider;

/**
 * Hash sesji w adresie (`url_key` strefy, Zones\ZoneUrlKey): trasy po zalogowaniu są pod `/<slug>/<hash>/…`, route()
 * dokłada hash bieżącej sesji, a kontroler nie dostaje go w parametrach; brak, zły albo cudzy hash to 404 także dla
 * zalogowanego; logowanie i trasy publiczne zostają pod `/<slug>/…`.
 */
class ZoneUrlKeyTest extends ZoneTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(ZoneTables::class)->create('gamma');
        $this->registerZone(GammaZoneServiceProvider::class);
    }

    public function test_screens_after_sign_in_live_under_the_session_hash(): void
    {
        $account = $this->account(GammaAccount::class);
        $this->get('/gamma/signin')->assertOk();
        $open = $this->get('/gamma/open')->assertOk()->getContent();
        $this->assertIsString($open);
        $this->assertMatchesRegularExpression('#/gamma/([a-z0-9]{' . ZoneUrlKey::LENGTH . '})/items/x$#', $open);
        $key = (string) preg_replace('#^.*/gamma/([a-z0-9]{' . ZoneUrlKey::LENGTH . '})/items/x$#', '$1', $open);

        $this->signedIn($account, 'gamma')->get('/gamma/' . $key . '/items/abc')->assertOk()->assertSee('gamma item:abc:' . $account->id);
        $this->get('/gamma/items/abc')->assertNotFound();
        $this->get('/gamma/' . str_repeat('a', ZoneUrlKey::LENGTH) . '/items/abc')->assertNotFound();
    }

    public function test_another_session_has_another_hash(): void
    {
        $first = (string) $this->get('/gamma/open')->getContent();
        $this->flushSession();
        $this->app->make('session')->driver()->flush();
        $second = (string) $this->withCookies([])->get('/gamma/open')->getContent();

        $this->assertNotSame($first, '');
        $this->assertMatchesRegularExpression('#/gamma/[a-z0-9]{' . ZoneUrlKey::LENGTH . '}/items/x$#', $second);
    }
}
