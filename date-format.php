<?php
declare(strict_types=1);

// Browser controls display Turkish dates; application/database values remain ISO.
function vox_date_to_iso(string $value, bool $withTime = false): string
{
    if ($value === '') return '';
    $value = preg_replace_callback('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?=$| )/', static fn($m) => sprintf('%02d.%02d.%s', (int)$m[1], (int)$m[2], $m[3]), $value);
    $formats = $withTime ? ['d.m.Y H:i', 'Y-m-d\TH:i', 'Y-m-d H:i:s'] : ['d.m.Y', 'Y-m-d'];
    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $parsed->format($format) === $value && (int)$parsed->format('Y') >= 1000) {
            return $parsed->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        }
    }
    throw new InvalidArgumentException('Tarih gg.aa.yyyy biçiminde ve geçerli olmalıdır.' . ($withTime ? ' Saat biçimi: ss:dd.' : ''));
}

function vox_is_date_field(string $key): bool
{
    return (bool)preg_match('/(?:^date$|_date$|^date_(?:from|to|start|end)$|^(?:sales_)?warranty_(?:start|end)$|^valid_(?:from|until)$|^special_day$|^(?:found|delivered)_at$)/D', $key);
}

function vox_normalize_dates(array $values): array
{
    foreach ($values as $key => $value) {
        if (vox_is_date_field((string)$key)) {
            $convert = static function ($item) use ($key): string {
                if (!is_string($item)) throw new InvalidArgumentException('Geçersiz tarih alanı.');
                // Unknown birth dates are stored as empty, never as an invalid SQL date.
                if ($key === 'birth_date' && $item === '00.00.0000') return '';
                return vox_date_to_iso($item, $key === 'delivered_at');
            };
            $values[$key] = is_array($value) ? array_map($convert, $value) : $convert($value);
        } elseif (is_array($value)) {
            $values[$key] = vox_normalize_dates($value);
        } elseif (is_string($value) && $value !== '' && preg_match('/(?:^(?:sales_details|repair_details)$|(?:^|_)term_schedule(?:_json)?$)/D', (string)$key)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) throw new InvalidArgumentException('Tarih planı okunamadı.');
            $values[$key] = json_encode(vox_normalize_dates($decoded), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    }
    return $values;
}

function vox_validate_request_dates(): void
{
    if (PHP_SAPI === 'cli') return;
    try {
        $_POST = vox_normalize_dates($_POST);
        $_GET = vox_normalize_dates($_GET);
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || basename($_SERVER['SCRIPT_NAME'] ?? '') === 'calendar-move.php') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="tr"><meta charset="utf-8"><title>Geçersiz tarih</title><h1>Geçersiz tarih</h1><p>' . htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8') . '</p><p>Geri dönüp tarih alanını düzeltin. Örnek: 13.09.2026.</p></html>';
        }
        exit;
    }
}