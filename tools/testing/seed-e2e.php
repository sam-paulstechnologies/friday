<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

require __DIR__.'/e2e-environment.php';
isolatedE2eEnvironment();
// Never overwrite an existing test DB implicitly.
$database = getenv('DB_DATABASE');
if (file_exists($database)) {
    fwrite(STDERR, "Disposable E2E database already exists; use a fresh isolated worktree.\n");
    exit(2);
}
touch($database);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
if (! $app->environment('testing') || config('database.default') !== 'sqlite' ||
    config('database.connections.sqlite.database') !== $database) {
    throw new RuntimeException('Unsafe E2E configuration.');
}
Artisan::call('migrate', ['--force' => true]);
$user = User::factory()->create(['name' => 'Synthetic CI Owner', 'email' => 'jarvis-ci@example.test',
    'password' => Hash::make('synthetic-e2e-password'), 'email_verified_at' => now()]);
$workspace = Workspace::create(['name' => 'Synthetic CI Workspace', 'slug' => 'jarvis-ci', 'created_by' => $user->id]);
$workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
echo "Created isolated synthetic E2E fixtures.\n";
