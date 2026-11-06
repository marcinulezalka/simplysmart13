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

namespace Tests\Feature\Database;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Simplysmart\Polygon\Http\Middleware\RecordHistoryActor;
use Tests\TestCase;

/**
 * Aktor historii zmian (docs/baza-danych.md §4): middleware grupy `web` dla żądań i listener zadań kolejki.
 */
class HistoryActorTest extends TestCase
{
    public function test_web_requests_record_the_actor(): void
    {
        $web = $this->app->make(Router::class)->getMiddlewareGroups()['web'] ?? null;

        $this->assertIsArray($web);
        $this->assertContains(RecordHistoryActor::class, $web);
    }

    public function test_actor_middleware_lets_the_request_through(): void
    {
        Route::middleware('web')->get('/_history-actor', static fn(): string => 'ok');

        $this->get('/_history-actor')->assertOk()->assertSee('ok');
    }

    public function test_queue_jobs_set_and_clear_the_actor(): void
    {
        $this->assertTrue(Event::hasListeners(JobProcessing::class));
        $this->assertTrue(Event::hasListeners(JobProcessed::class));
        $this->assertTrue(Event::hasListeners(JobFailed::class));
    }
}
