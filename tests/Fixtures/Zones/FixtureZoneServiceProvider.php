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

namespace Tests\Fixtures\Zones;

use Simplysmart\Polygon\Zones\ZoneServiceProvider;
use Simplysmart\Polygon\Zones\ZoneView;

/**
 * Strefa testowa: nazwa strefy jest też prefiksem nazw tras, trasy leżą w routes/<strefa>/, a strefa z logowaniem
 * dostaje proste widoki ekranów i maili z views/ (polygon nie ma widoków, docs/strefy.md paczki).
 */
abstract class FixtureZoneServiceProvider extends ZoneServiceProvider
{
    public const string VIEWS = 'zone-fixtures';

    abstract protected function zoneName(): string;

    public function register(): void
    {
        $this->loadViewsFrom(__DIR__ . '/views', self::VIEWS);
    }

    protected function routeNamePrefix(): string
    {
        return $this->zoneName();
    }

    protected function routesPath(): string
    {
        return __DIR__ . '/routes/' . $this->zoneName();
    }

    /**
     * Config strefy z logowaniem: model, widoki ekranów i maili fikstur oraz dodatkowe klucze.
     *
     * @param class-string<\Simplysmart\Polygon\Models\ZoneAccount> $model
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function signInZone(string $model, array $extra = []): array
    {
        $views = [];

        foreach (ZoneView::cases() as $view) {
            $views[$view->value] = self::VIEWS . '::' . (in_array($view, [ZoneView::Unlock, ZoneView::Security], true) ? 'account.' : 'auth.') . $view->value;
        }

        return ['name' => $this->zoneName(), 'model' => $model, 'views' => $views, 'mail' => ['views' => self::VIEWS . '::mail'], ...$extra];
    }
}
