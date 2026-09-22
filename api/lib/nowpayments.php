<?php

declare(strict_types=1);

final class NowPaymentsClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.nowpayments.io/v1';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (string) getenv('NOWPAYMENTS_API_KEY');
        if ($this->apiKey === '') {
            throw new RuntimeException('NOWPAYMENTS_API_KEY is not configured.');
        }
    }

    /**
     * The scaffold uses NOWPayments invoices because they return a hosted payment URL,
     * which keeps the storefront static and minimizes checkout UI we need to maintain.
     */
    public function createInvoice(array $payload): array
    {
        return $this->request('/invoice', $payload);
    }

    public function getPaymentStatus(string $paymentId): array
    {
        return $this->request('/payment/' . rawurlencode($paymentId), null, 'GET');
    }

    private function request(string $path, ?array $payload = null, string $method = 'POST'): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = [
            'x-api-key: ' . $this->apiKey,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('NOWPayments request failed: ' . $message);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('NOWPayments returned malformed JSON.');
        }

        if ($statusCode >= 400) {
            $message = $decoded['message'] ?? $decoded['error'] ?? 'NOWPayments API error';
            throw new RuntimeException('NOWPayments error: ' . $message);
        }

        return $decoded;
    }
}
