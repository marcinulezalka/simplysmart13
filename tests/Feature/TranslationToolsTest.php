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

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Szablon i import tłumaczeń paczek (polygon:translations:export / :import): szablon ma teksty wszystkich przestrzeni
 * w języku źródłowym z istniejącym tłumaczeniem, import zapisuje tylko klucze szablonu do lang/vendor projektu.
 */
class TranslationToolsTest extends TestCase
{
    private Filesystem $files;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem();
        $this->root = sys_get_temp_dir() . '/translation-tools-' . uniqid();
        $this->files->ensureDirectoryExists($this->root . '/lang');
        $this->app->useLangPath($this->root . '/lang');
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_export_lists_package_texts_with_existing_translations(): void
    {
        $output = $this->root . '/en.json';

        $this->command('polygon:translations:export', ['language' => 'en', '--source' => 'pl', '--output' => $output])->assertSuccessful();

        $template = json_decode((string) file_get_contents($output), true);
        $this->assertIsArray($template);
        $this->assertSame('en', $template['language']);
        $texts = $template['texts'];
        $this->assertIsArray($texts);
        $this->assertSame(['source' => 'Aktywny', 'translation' => 'Active'], $texts['polygon::enums.record_status.active']);
        $this->assertSame(['source' => 'Zapisz', 'translation' => 'Save'], $texts['polygon::actions.save']);
    }

    public function test_import_writes_known_keys_to_the_project_overrides(): void
    {
        $file = $this->root . '/de.json';
        file_put_contents($file, json_encode(['language' => 'de', 'source' => 'pl', 'texts' => [
            'polygon::actions.save' => ['source' => 'Zapisz', 'translation' => 'Speichern'],
            'polygon::actions.unknown' => ['source' => '', 'translation' => 'Ignoriert'],
            'polygon::actions.cancel' => ['source' => 'Anuluj', 'translation' => ' '],
        ]], JSON_THROW_ON_ERROR));

        $this->command('polygon:translations:import', ['file' => $file])->expectsOutput('Zapisano tłumaczeń: 1 (język de).')->assertSuccessful();

        $this->assertSame(['save' => 'Speichern'], require $this->root . '/lang/vendor/polygon/de/actions.php');
        App::setLocale('de');
        $this->assertSame('Speichern', text_action('polygon::save'));
    }

    public function test_import_rejects_a_file_without_the_template_fields(): void
    {
        $file = $this->root . '/bad.json';
        file_put_contents($file, '{"texts": {}}');

        $this->command('polygon:translations:import', ['file' => $file])->assertFailed();
    }

}
