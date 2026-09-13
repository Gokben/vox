<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/app-version.php';
// Publish only after application files have been copied successfully.
$files = glob(__DIR__ . '/*.php') ?: [];
$assets = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/assets', FilesystemIterator::SKIP_DOTS));
foreach ($assets as $asset) {
    if (!$asset->isFile() || $asset->isLink()) continue;
    $relative = str_replace('\\', '/', substr($asset->getPathname(), strlen(__DIR__) + 1));
    if (str_starts_with($relative, 'assets/uploads/')) continue;
    $files[] = $asset->getPathname();
}
sort($files, SORT_STRING);
$hash = hash_init('sha256');
foreach ($files as $file) {
    if (basename($file) === 'config.local.php') continue;
    hash_update($hash, str_replace(__DIR__, '', $file));
    hash_update_file($hash, $file);
}
$build = hash_final($hash);
$lock = fopen(__DIR__ . '/release.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Release lock failed.');
try {
    $path = __DIR__ . '/release.json';
    $previous = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
    $version = vox_next_release_version($previous, $build, $now);
    $release = ['version' => $version, 'build' => $build, 'builtAt' => $now->format(DATE_ATOM)];
    if (($previous['build'] ?? '') !== $build || ($previous['version'] ?? '') !== $version) {
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, json_encode($release, JSON_THROW_ON_ERROR) . PHP_EOL) === false || !rename($temporary, $path)) throw new RuntimeException('Release publish failed.');
    }
    echo $version . PHP_EOL;
} finally { flock($lock, LOCK_UN); fclose($lock); }
