<?php

namespace Database\Seeders;

use App\Models\BibleTranslation;
use App\Services\Bible\BibleJsonImporter;
use Illuminate\Database\Seeder;

class BibleContentSeeder extends Seeder
{
    public function run(): void
    {
        $importer = app(BibleJsonImporter::class);
        $importer->seedBooks();

        $kjvPath = database_path('seeders/kjv-bible.json');
        if (is_file($kjvPath)) {
            $importer->import($kjvPath, [
                'code' => 'KJV',
                'name' => 'King James Version',
                'language' => 'English',
                'license' => 'Public domain in the United States',
                'copyright' => null,
                'source_url' => 'https://github.com/churchstudio-org/openbible',
                'attribution' => 'KJV text imported from the openbible project.',
                'is_public_domain' => true,
                'is_enabled' => true,
            ]);
        }

        BibleTranslation::query()->updateOrCreate(
            ['code' => 'NIV'],
            [
                'name' => 'New International Version',
                'language' => 'English',
                'license' => 'Requires Biblica/Zondervan licensing for full-text storage or distribution',
                'copyright' => 'NIV full text is not bundled by this app. Import only from a licensed source.',
                'source_url' => 'https://www.biblica.com/resources/licensing/',
                'attribution' => 'Licensed NIV content can be imported with php artisan bible:import-json.',
                'is_public_domain' => false,
                'is_enabled' => false,
            ],
        );

        BibleTranslation::query()->updateOrCreate(
            ['code' => 'TEL'],
            [
                'name' => 'Telugu Bible',
                'language' => 'Telugu',
                'license' => 'Import from a permitted Telugu Bible source with required attribution',
                'copyright' => 'Telugu full text is not bundled until a permitted source file is provided.',
                'source_url' => null,
                'attribution' => 'Import Telugu content with php artisan bible:import-json.',
                'is_public_domain' => false,
                'is_enabled' => false,
            ],
        );
    }
}
