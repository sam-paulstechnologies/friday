<?php

use App\Models\CalendarConnection;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Calendar\CalendarSyncService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Synthetic concurrency harness only. Never accepts a host, credential or arbitrary database.
$root = realpath($argv[1] ?? '');
$port = $argv[2] ?? '';
$mode = $argv[3] ?? '';
if (PHP_SAPI !== 'cli' || ! $root || ! str_starts_with(basename($root), 'jarvis-restore-') ||
    ! is_file($root.'/.jarvis-isolated-restore') ||
    trim(file_get_contents($root.'/.jarvis-isolated-restore')) !== 'jarvis-disposable-restore-v1' ||
    ! ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535 ||
    ! in_array($mode, ['setup', 'hold', 'contend'], true)) {
    throw new RuntimeException('Disposable concurrency target required.');
}
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $root.'/no-config-cache.php',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'DB_URL' => '',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port,
    'DB_DATABASE' => 'jarvis_fixture_calendar', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_SOCKET' => '',
    'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
    'CACHE_STORE' => 'database', 'CACHE_PREFIX' => 'jarvis_synthetic_', 'OPENAI_API_KEY' => '',
    'SLACK_BOT_TOKEN' => '', 'SLACK_WEBHOOK_URL' => '', 'GOOGLE_CLIENT_ID' => '',
    'GOOGLE_CLIENT_SECRET' => '', 'GOOGLE_CALENDAR_ENABLED' => 'false', 'AI_ASSISTANT_ENABLED' => 'false',
    'MIRIAM_AI_ENABLED' => 'false'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
if (file_exists($root.'/no-config-cache.php')) {
    throw new RuntimeException('Unexpected test cache.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
file_put_contents($root.'/test-stage', 'booted');
Http::preventStrayRequests();
$facts = DB::selectOne('SELECT @@datadir AS datadir');
$data = realpath($facts->datadir);
if (! $data || ! str_starts_with($data.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR) ||
    config('database.connections.mysql.host') !== '127.0.0.1') {
    throw new RuntimeException('Server datadir did not prove isolation.');
}
if ($mode === 'setup') {
    file_put_contents($root.'/test-stage', 'migrating-fixtures');
    Artisan::call('migrate', ['--force' => true]);
    file_put_contents($root.'/test-stage', 'creating-fixtures');
    $user = User::factory()->create();
    $workspace = Workspace::create(['name' => 'Synthetic Calendar', 'slug' => 'calendar', 'created_by' => $user->id]);
    $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
    $connection = CalendarConnection::create(['user_id' => $user->id, 'workspace_id' => $workspace->id,
        'provider' => 'google', 'access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh',
        'token_expires_at' => now()->addHour(), 'is_active' => true]);
    Task::create(['workspace_id' => $workspace->id, 'title' => 'Synthetic task',
        'reporter_id' => $user->id, 'assignee_id' => $user->id, 'status' => 'todo', 'due_date' => now()->toDateString()]);
    echo json_encode(['connection_id' => $connection->id]);
    exit;
}
config(['services.google_calendar.enabled' => true, 'services.google_calendar.client_id' => 'fixture-client',
    'services.google_calendar.client_secret' => 'fixture-secret', 'services.google_calendar.redirect_uri' => 'https://example.test/callback']);
Http::fake(function ($request) use ($root, $mode) {
    if ($mode === 'contend') {
        throw new RuntimeException('Contending client must not reach the provider.');
    }
    if ($request->method() === 'POST') {
        file_put_contents($root.'/holder-entered', 'fixture');
        $deadline = microtime(true) + 15;
        while (! is_file($root.'/release-holder') && microtime(true) < $deadline) {
            usleep(20000);
        }
        if (! is_file($root.'/release-holder')) {
            throw new RuntimeException('Synthetic concurrency timeout.');
        }

        return Http::response(['id' => $request['id']]);
    }

    return Http::response(['items' => []]);
});
$result = app(CalendarSyncService::class)->syncConnection(
    CalendarConnection::findOrFail((int) $argv[4]));
echo json_encode($result);
