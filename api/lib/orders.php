<?php

declare(strict_types=1);

function orders_path(): string
{
    return dirname(__DIR__, 2) . '/data/orders.json';
}

function load_orders(): array
{
    $path = orders_path();
    if (!file_exists($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function save_orders(array $orders): void
{
    $path = orders_path();
    $json = json_encode(array_values($orders), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode orders JSON.');
    }

    $handle = fopen($path, 'c+');
    if (!$handle) {
        throw new RuntimeException('Unable to open order storage file.');
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock order storage file.');
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, $json . PHP_EOL);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function find_order_index(array $orders, string $orderId): ?int
{
    foreach ($orders as $index => $order) {
        if (($order['order_id'] ?? null) === $orderId) {
            return $index;
        }
    }

    return null;
}
