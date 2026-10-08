<?php

$base = getenv('LINT_BASE_SHA') ?: 'HEAD^';
if ($base !== 'HEAD^' && ! preg_match('/^[0-9a-f]{40}$/', $base)) {
    exit(2);
}
exec('git diff --name-only --diff-filter=ACMR '.escapeshellarg($base).' HEAD -- "*.php"', $files, $status);
if ($status !== 0) {
    exit($status);
}
$files = array_filter($files, fn ($file) => is_file($file));
if ($files === []) {
    echo "No changed PHP files.\n";
    exit(0);
}
passthru(escapeshellarg(PHP_BINARY).' vendor/bin/pint --test '.implode(' ', array_map('escapeshellarg', $files)), $status);
exit($status);
