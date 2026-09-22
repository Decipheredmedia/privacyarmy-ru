<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/orders.php';

header('Content-Type: application/json');

function sort_payload_recursively(array &$payload): void
{
    ksort($payload);
    foreach ($payload as &$value) {
        if (is_array($value)) {
            sort_payload_recursively($value);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $rawBody = file_get_contents('php://input') ?: '';
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid JSON payload.');
    }

    $signature = (string) ($_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? '');
    $secret = (string) getenv('NOWPAYMENTS_IPN_SECRET');
    if ($signature === '' || $secret === '') {
        throw new RuntimeException('NOWPayments IPN secret is not configured.');
    }

    $signedPayload = $payload;
    sort_payload_recursively($signedPayload);
    $expectedSignature = hash_hmac('sha512', json_encode($signedPayload, JSON_UNESCAPED_SLASHES), trim($secret));
    if (!hash_equals($expectedSignature, $signature)) {
        throw new RuntimeException('Invalid NOWPayments signature.');
    }

    $orderId = (string) ($payload['order_id'] ?? '');
    if ($orderId === '') {
        throw new InvalidArgumentException('Missing order_id in callback.');
    }

    $orders = load_orders();
    $index = find_order_index($orders, $orderId);
    if ($index === null) {
        throw new RuntimeException('Order not found.');
    }

    $orders[$index]['status'] = (string) ($payload['payment_status'] ?? $orders[$index]['status']);
    $orders[$index]['updated_at'] = gmdate(DATE_ATOM);
    $orders[$index]['payment_id'] = $payload['payment_id'] ?? ($orders[$index]['payment_id'] ?? null);
    $orders[$index]['invoice_id'] = $payload['invoice_id'] ?? ($orders[$index]['invoice_id'] ?? null);

    save_orders($orders);

    $paidStatuses = ['confirmed', 'finished'];
    if (in_array($orders[$index]['status'], $paidStatuses, true)) {
        $products = json_decode((string) file_get_contents(dirname(__DIR__) . '/data/products.json'), true);
        $product = null;
        foreach ($products as $catalogItem) {
            if (($catalogItem['id'] ?? null) === $orders[$index]['product_id']) {
                $product = $catalogItem;
                break;
            }
        }
        if (!is_array($product)) {
            throw new RuntimeException('Associated product not found.');
        }

        $mailer = new SimpleMailer();
        if (!$orders[$index]['notifications']['confirmed_email_sent']) {
            $mailer->sendPaymentConfirmedEmail($orders[$index], $product);
            $orders[$index]['notifications']['confirmed_email_sent'] = true;
        }
        if (!$orders[$index]['notifications']['admin_email_sent']) {
            $mailer->sendAdminNotification(
                $orders[$index],
                'Payment was marked ' . $orders[$index]['status'] . '. Begin fulfillment for ' . $orders[$index]['product_title'] . '.'
            );
            $orders[$index]['notifications']['admin_email_sent'] = true;
        }
        $orders[$index]['updated_at'] = gmdate(DATE_ATOM);
        save_orders($orders);
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()]);
}
