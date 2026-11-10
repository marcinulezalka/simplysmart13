# Dla programistów

Opis techniczny simplysmart13: aplikacja Laravel 13 na paczce `simplysmart/polygon`. Opis biznesowy: [README.md](README.md). Zasady pracy agentów i pełne reguły projektu: [CLAUDE.md](CLAUDE.md).

## Stos

- Laravel 13 (PHP ^8.4), baza MySQL 8 (lokalnie wg `.env`; testy na SQLite w pamięci z `phpunit.xml`)
- `spatie/laravel-permission` — role i uprawnienia z zespołami, w których zespół to organizacja (`organization_id`): konto strefy może mieć w każdej organizacji inne role; tabele i konfiguracja z `simplysmart/polygon` (`Permissions\PermissionDefaults`, bez publikowania `config/permission.php`)
- `simplysmart/polygon` (`require`) — rdzeń bazy danych: wszystkie migracje i seedery (projekt nie ma własnych `database/migrations/` ani `database/seeders/`), kolumny standardowe, historia zmian, pule id, tabele strefy `polygon_*`, słowniki lokalizacji, organizacje (`vendor/simplysmart/polygon/docs/baza-danych.md`), strefy i logowanie (`docs/strefy.md`), pliki instalacji (`docs/instalacja.md`) oraz narzędzia wydań (`vendor/bin/version-release`) i wdrożeń (`vendor/bin/deploy`); lokalnie z `../packages/simplysmart/polygon` (repozytorium path `../packages/*/*`, symlink), na serwer trafia z repozytorium git
- Paczki prywatne — wpisane w git-ignorowany `private-packages.json` i instalowane przez `composer private:install` (`composer.private.json` / `.lock`), więc ich nazwy nie trafiają do repozytorium; zwykłe `composer install/update` usuwa je z `vendor/`, więc potem uruchom `composer private:install` ponownie
- Laravel Boost — wytyczne i skille agentów w lokalnym (poza repozytorium, `.gitignore`) `.claude/skills/` (konfiguracja: `boost.json`, aktualizacja skilli Boost: `php artisan boost:update`); własne skille projektu (`changelog-before-commit`, `docs-after-tests`, `solid-dry`) leżą tam bezpośrednio, poza `boost.json`, więc Boost ich nie nadpisuje ani nie usuwa

## Baza danych

```bash
php artisan migrate                      # migracje paczek (projekt nie ma własnych)
php artisan db:seed                      # DatabaseSeeder z polygon: słowniki, typy jednostek i organizacja hero, pliki instalacji, seedery paczek
php artisan polygon:organizations:seed   # same typy jednostek i organizacja hero (idempotentnie)
php artisan polygon:install              # same pliki instalacji (JSON paczek + POLYGON_INSTALL_FILES projektu)
php artisan polygon:account:create jan@example.com "Jan Kowalski" --role=owner   # konto strefy polygon (polygon_accounts; PIN przy pierwszym logowaniu)
```

Migracje tworzą tylko schemat; dane referencyjne dają wyłącznie seedery. Język, kraj i waluta przez `.env`: `APP_LOCALE=pl`, `APP_LOCALES=pl`, `APP_COUNTRY=PL`, `APP_CURRENCY=PLN` (słowniki po polsku, stawki VAT Polski; `config/polygon.php` w paczce). Każda zmiana danych trafia do `<tabela>_history` z aktorem (użytkownik żądania `web` albo zadanie kolejki).

## Tłumaczenia

Polskie komunikaty Laravela (walidacja, logowanie, hasła, paginacja) i helpery `text_*` / `local_*` dostarcza `simplysmart/polygon` (`vendor/simplysmart/polygon/docs/tlumaczenia.md`). Szablon tłumaczeń: `php artisan polygon:translations:export en --output=en.json`, import: `php artisan polygon:translations:import en.json` (do `lang/vendor/`).

## Jakość kodu

- `composer lint` / `composer format` — Laravel Pint, preset PER + `declare(strict_types=1)` (`pint.json`, nagłówek licencji w testach: `pint.header.json`)
- `composer analyse` — PHPStan + Larastan, poziom `max` (`phpstan.neon`), bez ignorów i baseline
- `composer check` — wszystkie trzy naraz (`lint`, `analyse`, `test`) plus `check:packages`: `composer check` każdej zainstalowanej paczki z `vendor/simplysmart/*`, która go definiuje; `composer deploy` uruchamia go przed wdrożeniem (także z `--dry-run`) i przerywa przy błędzie. Przed commitem wystarczą testy obejmujące zmianę.
- `composer test` — PHPUnit: `tests/Unit`, `tests/Feature` (integracja z paczkami, strefy testowe `alpha`, `beta`, `open` w `tests/Fixtures/Zones`) i zestaw `Packages` — testy integracyjne paczek zainstalowanych w projekcie (`vendor/simplysmart/*/tests/Integration`; `php artisan test --testsuite=Packages`)
- `composer release -- --dry-run` (`vendor/bin/version-release` z `simplysmart/polygon`) — podgląd wydania SemVer na podstawie `CHANGELOG.md` (bez `--dry-run` zapisuje `VERSION` i `CHANGELOG.md`; MAJOR wymaga `--major-confirm`)

## Struktura

- `app/`, `config/`, `routes/`, `resources/` — aplikacja Laravel w postaci, w jakiej ją wydano (zmiany ustawień przez `.env`), bez modeli w `app/` (konta strefy: `Simplysmart\Polygon\Models\PolygonAccount` w `polygon_accounts`; konta frontendu: `Simplysmart\Polygon\Models\User` w `users`); `database/` jest pusty (migracje, seedery i fabryki są w paczkach)
- `public/` — document root Laravela (punkt wejścia: `public/index.php`)
- `infrastructure/` — lokalny, poza repozytorium (`.gitignore`), tworzony na każdym komputerze osobno:
  - `infrastructure/ssl/` — certyfikat SSL (mkcert),
  - `infrastructure/config/` — vhost Apache i pula PHP-FPM,
  - `infrastructure/deploy/vendors/` — konfiguracje wdrażanych projektów: katalog `<projekt>/<projekt>.env` albo układ płaski `<projekt>.env` (wzór w paczce `simplysmart/polygon`: `resources/deploy/deploy-vendor.env.example`), obok opcjonalny plik instalacji projektu `<projekt>.json` (organizacje, konta, role i uprawnienia stref; format: `vendor/simplysmart/polygon/docs/instalacja.md`)

## Wdrożenie

Uruchamiane na komputerze deweloperskim przez `vendor/bin/deploy` z paczki `simplysmart/polygon` (`remote.sh` trafia na serwer przez `ssh … bash -s`). Konfiguracja projektu: lokalny `infrastructure/deploy/vendors/<projekt>/<projekt>.env` albo `infrastructure/deploy/vendors/<projekt>.env` (poza repozytorium).

Każde wdrożenie buduje świeży manifest wdrożeniowy (`composer.deploy.json` i `composer.deploy.lock`, git-ignorowane): paczki z lokalnych repozytoriów path (`../packages/*/*`), także prywatne, dostają repozytoria git (`origin`) zamiast katalogów. Manifest jest kopiowany do przed-produkcji i wysyłany na serwer (`scp`), a `composer install --no-dev` instaluje z niego paczki. Dlatego przed wdrożeniem wypchnij zmienione paczki (albo użyj `--with-push`, które wypycha projekt i paczki), a lokalny Composer musi mieć dostęp do ich repozytoriów (token GitHub: `composer config -g github-oauth.github.com <token>`). Na serwerze musi być klon repozytorium z `.env.production` (kopiowanym przy każdym wdrożeniu jako `.env`).

Po migracjach każde wdrożenie uruchamia `php artisan polygon:organizations:seed` (typy jednostek i organizacja hero) i `php artisan polygon:install`: organizacje, uprawnienia i role z plików paczek, `POLYGON_INSTALL_FILES` z `.env` oraz plik instalacji projektu `<projekt>.json`, jeśli jest (deploy przesyła go do obu środowisk jako `storage/app/<projekt>.install.json`). Instalacja jest idempotentna. Słowników deploy nie seeduje — przy pierwszej instalacji uruchom w każdym środowisku `php artisan db:seed --force`.

```bash
cp vendor/simplysmart/polygon/resources/deploy/deploy-vendor.env.example infrastructure/deploy/vendors/simplysmart13.env   # raz, uzupełnij
composer deploy -- simplysmart13 --dry-run          # podgląd planu (z listą paczek manifestu)
vendor/bin/composer-private --deploy --dry-run      # sam manifest: paczki z repozytoriów git
composer deploy -- simplysmart13 --with-push        # git push projektu i paczek, przed-produkcja, potem pytanie o produkcję
composer deploy -- simplysmart13 --no-production    # tylko przed-produkcja
composer deploy -- simplysmart13 --production       # produkcja bez pytania
```

Kroki i opcje: `vendor/simplysmart/polygon/docs/deploy.md`.
