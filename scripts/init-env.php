<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root.'/.env';

if (file_exists($path)) {
    fwrite(STDERR, ".env already exists; refusing to overwrite it.\n");
    exit(1);
}

$template = file_get_contents($root.'/.env.example');
if ($template === false) {
    throw new RuntimeException('Cannot read .env.example.');
}

$contents = str_replace(
    [
        'POSTGRES_PASSWORD=',
        'APP_DB_PASSWORD=',
        'APP_DB_MIGRATION_PASSWORD=',
        'APP_DB_MUTATION_SIGNING_KEY=',
        'APP_KEY=',
        'DEV_MANAGEMENT_TOKEN=',
    ],
    [
        'POSTGRES_PASSWORD='.bin2hex(random_bytes(32)),
        'APP_DB_PASSWORD='.bin2hex(random_bytes(32)),
        'APP_DB_MIGRATION_PASSWORD='.bin2hex(random_bytes(32)),
        'APP_DB_MUTATION_SIGNING_KEY='.bin2hex(random_bytes(32)),
        'APP_KEY=base64:'.base64_encode(random_bytes(32)),
        'DEV_MANAGEMENT_TOKEN='.bin2hex(random_bytes(32)),
    ],
    $template,
);

umask(0077);
$file = fopen($path, 'x');
if ($file === false || fwrite($file, $contents) !== strlen($contents)) {
    throw new RuntimeException('Cannot write .env.');
}
fclose($file);
fwrite(STDOUT, "Created local .env with generated credentials (not tracked by Git).\n");
