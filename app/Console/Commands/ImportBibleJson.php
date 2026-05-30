<?php

namespace App\Console\Commands;

use App\Services\Bible\BibleJsonImporter;
use Illuminate\Console\Command;

class ImportBibleJson extends Command
{
    protected $signature = 'bible:import-json
        {code : Translation code, for example KJV, NIV, TEL}
        {name : Translation display name}
        {language : Translation language}
        {path : Absolute path or project-relative path to JSON file}
        {--license= : License label}
        {--copyright= : Copyright statement}
        {--source-url= : Source URL}
        {--attribution= : Attribution text}
        {--public-domain : Mark translation as public domain}
        {--disabled : Import but hide from reader selector}';

    protected $description = 'Import a Bible translation from a canonical 66-book JSON array.';

    public function handle(BibleJsonImporter $importer): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $path = base_path($path);
        }

        $count = $importer->import($path, [
            'code' => strtoupper((string) $this->argument('code')),
            'name' => (string) $this->argument('name'),
            'language' => (string) $this->argument('language'),
            'license' => $this->option('license'),
            'copyright' => $this->option('copyright'),
            'source_url' => $this->option('source-url'),
            'attribution' => $this->option('attribution'),
            'is_public_domain' => (bool) $this->option('public-domain'),
            'is_enabled' => ! (bool) $this->option('disabled'),
        ]);

        $this->info("Imported {$count} verses.");

        return self::SUCCESS;
    }
}
