<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>

## Development Setup
- simplysmart13 is a Laravel 13 application (PHP ^8.4, MySQL 8 — local database configured in `.env`; tests use in-memory SQLite from `phpunit.xml`), served locally at `https://simplysmart13.local` (`public/` is the document root; vhost and PHP-FPM pool in `infrastructure/config/`, mkcert certificate in `infrastructure/ssl/`). The whole `infrastructure/` directory is local and git-ignored — never commit it.
- Local packages live outside the project, in `/var/www/packages/simplysmart/<package-name>` (`../packages/` from the project root), and are installed through the generic Composer path repository `../packages/*/*` (`simplysmart-local`, symlinks). Each package is a separate Git repository, outside the simplysmart13 repository.
- Always edit package code inside `../packages/simplysmart/<package-name>/`, never inside `vendor/`. After changing a package's `composer.json`, run `composer update simplysmart/<package-name>` here so `composer.lock` picks it up.
- Coding standards (project and packages): PHP ^8.4, `declare(strict_types=1);` in every PHP file, PSR-4 autoloading (one class per file, namespace mirrors the directory), PER Coding Style via the Laravel Pint `per` preset (`pint.json`). Own PHP files carry the PHPDoc copyright header from `pint.header.json`, applied to `tests/` only, because the stock framework files stay untouched — `composer format` adds it, `composer lint` fails without it.

## Secrets
- **Never print secret values of `.env.production` or `.env.staging`** — not `APP_KEY`, passwords (`DB_PASSWORD`, `MAIL_PASSWORD`, `REDIS_PASSWORD`), tokens (`GITHUB_TOKEN`, API keys) or any other value — in tool output, replies, logs, commit messages or files. The same applies to `infrastructure/deploy/vendors/*.env` and to the local `.env` / `.env.local`.
- Inspect these files only by key names or masked values: `grep -oE '^[A-Z_]+=' .env.production`, `sed -E 's/=(.+)$/=<ustawione>/'`, or compare hashes (`grep '^APP_KEY=' f | sha256sum`). Never `cat`, `head`, `tail`, `less` or an unmasked `grep` on them, and never `config:show` / `tinker` output of secret config keys.
- Generate keys straight into the file (`php artisan key:generate --env=<name> --force`, or a script that writes without echoing); never `key:generate --show` or another command that prints the new key.
- Never read or print a shell's or process's environment (`env`, `printenv`, `/proc/*/environ`) unfiltered — VS Code terminals may carry `.env` values; filter to key names only.
- If a secret value appears in output anyway, say so to the user and recommend rotating it; do not repeat it.

## Packages
- **Private packages**: this repository is public, so nothing about a private package (name, purpose, classes, routes, tables, env variables) may appear in any tracked file — `composer.json`, `composer.lock`, docs, `CHANGELOG.md`, tests or commit messages. List them in the git-ignored `private-packages.json` (`{"require": {"<vendor>/<package>": "@dev"}}`) and install with `composer private:install` (polygon's `vendor/bin/composer-private`, writing the git-ignored `composer.private.json`/`.lock`); a plain `composer install/update/require/remove` removes them from `vendor/`, so run `composer private:install` again afterwards. Before committing, grep the diff and the commit message for the names of the private packages in `../packages/`; the result must be empty.
- **Application packages**: auto-discovered through `extra.laravel.providers`, never registered in `bootstrap/providers.php`; standalone libraries — package code never references `App\...` classes, and the project uses them only through their public API.
- **`simplysmart/polygon`** (`require`): the database core — migrations of the base tables (`database/migrations/`: standard `users` columns, the `polygon_*` zone tables, `locale_*` dictionaries, `organizations` with `organization_unit_types` and `organization_members`), `Database\Columns`/`History`/`IdSlots`/`ZoneTables`, `Models\DataModel`, the dictionary models and `Models\Organization`/`OrganizationUnitType`/`OrganizationMember`, seeders run by `php artisan polygon:localization:seed` and `php artisan polygon:organizations:seed` — plus the developer tools for releases (`vendor/bin/version-release`) and deployment (`vendor/bin/deploy`). Schema and model rules: `vendor/simplysmart/polygon/docs/baza-danych.md`. The project has no `app/Models/`, `database/migrations/`, `database/seeders/` or `database/factories/`: accounts of the `polygon` zone live in `polygon_accounts` (`Models\PolygonAccount`, guard `polygon`, created with `php artisan polygon:account:create {email} {name} [--role=*]`, like `admiro:account:create`), while `users` is only for frontend accounts (polygon's `Models\User` with `UserFactory`, `HasRoles`, guard `web`), `db:seed` runs polygon's `DatabaseSeeder` (dictionaries, the hero organization, then the JSON install files: zones, permissions, roles and start accounts — the package's `database/install/polygon.json` plus the project's `POLYGON_INSTALL_FILES`; `php artisan polygon:install`, format in `vendor/simplysmart/polygon/docs/instalacja.md`), and every migration (including Laravel's stock `users`, `cache`, `jobs`) lives in a package; new tables follow the checklist (§13) in the package that owns them. Migrations create schema only — never insert data or call a seeder; reference data comes from seeders in each package's `database/seeders/` (polygon's base seeders in `DatabaseSeeder::BASE`, other packages register theirs in polygon's `Database\PackageSeeders`, run by `db:seed` after the base ones). Roles and permissions use `spatie/laravel-permission` without teams, configured only by polygon's `Permissions\PermissionDefaults` (never publish `config/permission.php`); models with roles add `HasRoles`. The organization context of a signed-in zone account is polygon's `Contracts\CurrentOrganization` (zone session; switch route `<zone>.account.organization.switch`); the hero organization „Simplysmart” (`Organization::HERO_ID`, created by polygon's `HeroOrganizationSeeder` in `db:seed`) holds the `hero` accounts, gives them every active organization as a context and is never shown on zone frontends (`contexts()`, `visible()`).
- **Zones** (polygon `docs/strefy.md`): every zone is registered by its package's provider extending polygon's `Zones\ZoneServiceProvider` (zone `polygon` included), with its own URL slug, route name prefix, guard, `<zone>_accounts` model (`Models\ZoneAccount`) and session; always name the guard explicitly (`Auth::guard('<zone>')`, `actingAs($account, '<zone>')`) — the default `web` guard is for frontend `users` only. polygon has no views: sign-in, account and mail screens come from the zone package's `views` / `mail.views` config. Roles come from the install files' role catalog, per zone guard. Zone mechanism tests use the fixture zones `alpha`, `beta` and `open` (`tests/Fixtures/Zones`, `tests/Feature/Zones/ZoneTestCase`); a zone package tests its integration in its own `tests/Integration`, run here by the `Packages` suite (`php artisan test --testsuite=Packages`).
- Servers never get `../packages/`: `composer deploy` builds a fresh deploy manifest (`composer.deploy.json` / `.lock`, git-ignored; `vendor/bin/composer-private --deploy`) that installs path packages, private ones included, from their Git repositories, so push every changed package before deploying the project.

## Framework Footprint
Goal: upgrading Laravel stays a `composer.json` bump plus `composer update`.
- Framework files (`config/*.php`, `bootstrap/`, `routes/`, `app/Providers/`) stay as Laravel ships them; the only allowed changes are `declare(strict_types=1);` (Pint) and the user model import in `config/auth.php` (`Simplysmart\Polygon\Models\User`, because `app/Models/` is gone). Change settings through `.env` variables, not by editing these files.
- Ask the user before adding new files to `app/` or `config/` (there are no models in `app/`); shared or domain code belongs in a package.
- Package config, views, translations, migrations and routes are loaded by the package's own Service Provider; never copy or bulk-publish (`vendor:publish`) them into the project.

## Translations
- UI and application texts go through polygon's helpers `text_*` (`text`, `text_action`, `text_data`, `text_file`, `text_email`) and `local_*` (`local_money`, `local_number`, `local_date`, `local_day`, `local_month`), not `__()`. The cascade is package → polygon → the project's `lang/` → the key itself, in the current locale only (no fallback language), so a missing translation shows its key.
- Polish framework texts (`validation`, `auth`, `passwords`, `pagination`) come from polygon's `lang/pl/`; override a single file in the project's `lang/pl/<file>.php` only when the user asks. Override a package text in `lang/vendor/<package>/<locale>/<file>.php` (or import a filled template with `php artisan polygon:translations:import`); never bulk-publish package translations.
- Details: `vendor/simplysmart/polygon/docs/tlumaczenia.md`.

## Release & Deployment
- One release implementation: `simplysmart/polygon` (`src/Deploy/Release/` in `../packages/simplysmart/polygon`). Never add a second one to the project. Preview with `composer release -- --dry-run`; without `--dry-run` it really writes `VERSION` and `CHANGELOG.md` (MAJOR requires `--major-confirm`), so release only when the user asks.
- Deploy only with `composer deploy -- <project>` (`vendor/bin/deploy` from `simplysmart/polygon`), run on the development machine: it updates pre-production first, then asks about production (`--production` / `--no-production`, `--with-push` pushes first, `--dry-run` shows the plan). Project config lives in `infrastructure/deploy/vendors/<project>.env` (git-ignored; template `vendor/simplysmart/polygon/resources/deploy/deploy-vendor.env.example`). Steps and options: `vendor/simplysmart/polygon/docs/deploy.md`.
- Never push or deploy unless the user asks.

## Skills Ownership
- `.claude/` (and `.ai/`, if Boost ever creates it) is local and git-ignored (like `infrastructure/`) — never commit it.
- **Project skills** (`changelog-before-commit`, `docs-after-tests`, `solid-dry`) live directly in `.claude/skills/` as plain directories and are not listed in `boost.json`, so `boost:update` neither overwrites nor removes them. Never add them back to `boost.json` without restoring a `.ai/skills/` source — Boost deletes tracked skills it cannot find.
- **Boost skills** (`laravel-best-practices`, `testing-best-practices`, `tailwindcss-development`, `infer-conventions`) in `.claude/skills/` are generated by Laravel Boost (`boost.json`) — do not edit them by hand; refresh with `php artisan boost:update`.
- `AGENTS.md` only points to this file; project rules belong here, outside the `<laravel-boost-guidelines>` block, which Boost regenerates.

## Quality Gates
Run from the root of the repository you changed:
- `composer lint` — PER style plus `declare(strict_types=1);` in every PHP file (`composer format` fixes both).
- `composer analyse` — PHPStan + Larastan at level `max` (`phpstan.neon`). Fix the cause; do not add ignores or a baseline.
- Intelephense — no diagnostics (errors, warnings, hints) in changed PHP files. Fix the cause; never `@intelephense-ignore` or disabled diagnostics. VS Code's Problems panel (`getDiagnostics`) only shows files Intelephense has analyzed, so an empty result for an unopened file is not proof.
- `composer test` — PHPUnit (`tests/Unit`, `tests/Feature`). Project tests cover integration with packages; a package tests its own logic in its own `tests/`.
