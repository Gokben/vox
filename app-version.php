<?php
declare(strict_types=1);

function vox_app_release(): array
{
    static $release;
    if ($release !== null) return $release;
    $data = is_file(__DIR__ . '/release.json') ? json_decode((string)file_get_contents(__DIR__ . '/release.json'), true) : null;
    return $release = is_array($data) ? $data : ['version' => '', 'build' => ''];
}

function vox_app_version(): string { return (string)(vox_app_release()['version'] ?? ''); }

function vox_next_release_version(?array $previous, string $build, DateTimeImmutable $now): string
{
    $version = (string)($previous['version'] ?? '');
    if (!preg_match('/^\d{5}\.\d{2,}$/', $version)) $version = '';
    if ($version !== '' && ($previous['build'] ?? '') === $build) return $version;
    $date = $now->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('dm');
    $date .= substr($now->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y'), -1);
    $sequence = str_starts_with($version, $date . '.') ? (int)explode('.', $version)[1] + 1 : 1;
    return $date . '.' . str_pad((string)$sequence, 2, '0', STR_PAD_LEFT);
}

function vox_versioned_path(string $path): string
{
    $relative = parse_url($path, PHP_URL_PATH);
    if (!is_string($relative) || !str_starts_with($relative, 'assets/') || str_contains($relative, '..')) return $path;
    if (!preg_match('/\.(?:css|js|png|jpe?g|svg|webp|ico)$/i', $relative)) return $path;
    $file = __DIR__ . '/' . $relative;
    if (!is_file($file)) return $path;
    static $hashes = [];
    $hash = $hashes[$relative] ??= substr(hash_file('sha256', $file), 0, 16);
    $token = $hash . (isset($_SESSION['vox_asset_refresh']) ? '-' . $_SESSION['vox_asset_refresh'] : '');
    $parts = explode('#', $path, 2);
    return $parts[0] . (str_contains($parts[0], '?') ? '&' : '?') . '_vox_v=' . rawurlencode($token) . (isset($parts[1]) ? '#' . $parts[1] : '');
}
