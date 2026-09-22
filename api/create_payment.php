<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/nowpayments.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/orders.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $payload = $_POST;
    if ($payload === []) {
        $rawInput = file_get_contents('php://input');
        $decoded = json_decode($rawInput ?: '[]', true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $productId = trim((string) ($payload['product_id'] ?? ''));
    $customerEmail = trim((string) ($payload['customer_email'] ?? ''));
    $condition = trim((string) ($payload['condition'] ?? 'New'));
    $quantity = max(1, min(5, (int) ($payload['quantity'] ?? 1)));

    if ($productId === '' || $customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Valid product_id and customer_email are required.');
    }

    $productsPath = dirname(__DIR__) . '/data/products.json';
    $products = json_decode((string) file_get_contents($productsPath), true);
    if (!is_array($products)) {
        throw new RuntimeException('Product catalog is unavailable.');
    }

    $product = null;
    foreach ($products as $catalogItem) {
        if (($catalogItem['id'] ?? null) === $productId) {
            $product = $catalogItem;
            break;
        }
    }
    if (!is_array($product)) {
        throw new InvalidArgumentException('Unknown product_id.');
    }

    if (!in_array($condition, $product['condition_options'], true)) {
        throw new InvalidArgumentException('Invalid condition selected.');
    }

    $siteBaseUrl = rtrim((string) getenv('SITE_BASE_URL'), '/');
    if ($siteBaseUrl === '') {
        throw new RuntimeException('SITE_BASE_URL is not configured.');
    }

    $orderId = 'PA-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $amountUsd = round((float) $product['price_usd'] * $quantity, 2);

    $client = new NowPaymentsClient();
    $invoice = $client->createInvoice([
        'price_amount' => $amountUsd,
        'price_currency' => 'usd',
        'order_id' => $orderId,
        'order_description' => $product['title'] . ' x' . $quantity,
        'success_url' => $siteBaseUrl . '/support/faq/index.html',
        'cancel_url' => $siteBaseUrl . '/products/' . $product['slug'] . '/index.html',
        'ipn_callback_url' => $siteBaseUrl . '/api/ipn_callback.php',
    ]);

    $order = [
        'order_id' => $orderId,
        'product_id' => $product['id'],
        'product_title' => $product['title'],
        'customer_email' => $customerEmail,
        'quantity' => $quantity,
        'condition' => $condition,
        'amount_usd' => $amountUsd,
        'status' => 'waiting',
        'created_at' => gmdate(DATE_ATOM),
        'updated_at' => gmdate(DATE_ATOM),
        'payment_url' => $invoice['invoice_url'] ?? null,
        'invoice_id' => $invoice['id'] ?? null,
        'payment_id' => $invoice['payment_id'] ?? null,
        'admin_notes' => '',
        // JSON storage keeps the scaffold simple. A real deployment can replace this
        // with SQLite here, and later move to MySQL/Postgres without changing the API surface.
        'notifications' => [
            'pending_email_sent' => false,
            'confirmed_email_sent' => false,
            'admin_email_sent' => false,
        ],
    ];

    $orders = load_orders();
    $orders[] = $order;
    save_orders($orders);

    $mailer = new SimpleMailer();
    $mailer->sendPendingOrderEmail($order, $product);

    $orders = load_orders();
    $index = find_order_index($orders, $orderId);
    if ($index !== null) {
        $orders[$index]['notifications']['pending_email_sent'] = true;
        $orders[$index]['updated_at'] = gmdate(DATE_ATOM);
        save_orders($orders);
    }

    $response = [
        'order_id' => $orderId,
        'payment_url' => $invoice['invoice_url'] ?? null,
        'invoice' => $invoice,
    ];

    $acceptHeader = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $expectsJson = str_contains($acceptHeader, 'application/json');
    if (!$expectsJson && !empty($response['payment_url'])) {
        header('Location: ' . $response['payment_url'], true, 303);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Throwable $exception) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()]);
}
