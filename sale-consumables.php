<?php
declare(strict_types=1);
function sale_consumable_lines(array $details): array
{
    $stock = (int)($details['sales_consumable_stock_id'] ?? 0);
    $quantity = (int)($details['sales_consumable_quantity'] ?? 0);
    if (!$stock || $quantity < 1) return [];
    $promotion = ($details['sales_consumable_promotion'] ?? '') === 'Evet';
    $json = trim((string)($details['sales_consumable_items'] ?? ''));
    if ($json === '') return [['stock_id'=>$stock,'quantity'=>$quantity,'promotion'=>$promotion]];
    $items = json_decode($json, true);
    if (!is_array($items) || !array_is_list($items) || count($items) !== $quantity) throw new RuntimeException('Her adet için bir sarf malzeme seçin.');
    $lines = [];
    foreach ($items as $item) {
        $id = filter_var($item['stock_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new RuntimeException('Her adet için geçerli bir sarf malzeme seçin.');
        $lines[] = ['stock_id'=>$id,'quantity'=>1,'promotion'=>$promotion];
    }
    if ($lines[0]['stock_id'] !== $stock) throw new RuntimeException('İlk sarf malzeme seçimi eşleşmiyor.');
    return $lines;
}
