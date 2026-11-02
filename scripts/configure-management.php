<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
if (! in_array($mode, ['--enable', '--disable'], true)) {
    fwrite(STDERR, "Usage: php scripts/configure-management.php --enable|--disable\n");
    exit(1);
}

$path = dirname(__DIR__).'/.env';
if (! is_file($path) || is_link($path)) {
    throw new RuntimeException('Initialize the local .env first. Symlinks are not supported.');
}

$file = fopen($path, 'r+');
if ($file === false || ! flock($file, LOCK_EX)) {
    throw new RuntimeException('Cannot lock the local .env.');
}
try {
    $contents = stream_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Cannot read the local .env.');
    }

    $values = ['DEV_MANAGEMENT_ENABLED' => $mode === '--enable' ? 'true' : 'false'];
    preg_match('/^DEV_MANAGEMENT_TOKEN=(.*)$/m', $contents, $match);
    $existingToken = trim($match[1] ?? '', "\"' \t\r");
    if ($mode === '--enable' && $existingToken !== '' && strlen($existingToken) < 32) {
        throw new RuntimeException('The existing development token is too short. Configure at least 32 characters.');
    }
    if ($existingToken === '') {
        $values['DEV_MANAGEMENT_TOKEN'] = bin2hex(random_bytes(32));
    }
    foreach ($values as $key => $value) {
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $contents = preg_match($pattern, $contents)
            ? preg_replace($pattern, $key.'='.$value, $contents)
            : rtrim($contents)."\n{$key}={$value}\n";
    }
    if (! chmod($path, 0600) || ! rewind($file)
        || fwrite($file, $contents) !== strlen($contents) || ! ftruncate($file, strlen($contents))) {
        throw new RuntimeException('Cannot update the local .env.');
    }
} finally {
    flock($file, LOCK_UN);
    fclose($file);
}
fwrite(STDOUT, "Development management setting updated. No secrets printed. Recreate containers with docker compose up -d.\n");
