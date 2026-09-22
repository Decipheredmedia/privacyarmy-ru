<?php

declare(strict_types=1);

final class SimpleMailer
{
    private string $from;
    private string $fromName;

    public function __construct()
    {
        $this->from = (string) getenv('SMTP_FROM');
        $this->fromName = (string) getenv('SMTP_FROM_NAME') ?: 'PrivacyArmy';
    }

    public function sendPendingOrderEmail(array $order, array $product): void
    {
        $subject = 'We received your PrivacyArmy order ' . $order['order_id'];
        $body = "Hi,\n\n"
            . "We created your pending order for {$product['title']} (quantity {$order['quantity']}). "
            . "Please complete the hosted NOWPayments invoice at: {$order['payment_url']}\n\n"
            . "Order total: {$order['amount_usd']} USD\n"
            . "Condition: {$order['condition']}\n\n"
            . "Once the payment is confirmed, we will email next steps automatically.\n\n"
            . "— PrivacyArmy";
        $this->send((string) $order['customer_email'], $subject, $body);
    }

    public function sendPaymentConfirmedEmail(array $order, array $product): void
    {
        $subject = 'Payment confirmed for PrivacyArmy order ' . $order['order_id'];
        $body = "Hi,\n\n"
            . "Your payment is confirmed for {$product['title']}. Our team has been notified and fulfillment can begin.\n\n"
            . "Order total: {$order['amount_usd']} USD\n"
            . "Status: {$order['status']}\n\n"
            . "If we need any region or shipping details, support will follow up from this inbox.\n\n"
            . "— PrivacyArmy";
        $this->send((string) $order['customer_email'], $subject, $body);
    }

    public function sendAdminNotification(array $order, string $message): void
    {
        $adminEmail = (string) getenv('SUPPORT_EMAIL');
        if ($adminEmail === '') {
            return;
        }
        $subject = 'PrivacyArmy order update: ' . $order['order_id'];
        $body = $message . "\n\nOrder record:\n" . json_encode($order, JSON_PRETTY_PRINT);
        $this->send($adminEmail, $subject, $body);
    }

    public function sendSupportTicket(string $customerEmail, string $subjectLine, string $message): void
    {
        $adminEmail = (string) getenv('SUPPORT_EMAIL');
        if ($adminEmail === '') {
            throw new RuntimeException('SUPPORT_EMAIL is not configured.');
        }
        $subject = '[Support] ' . $subjectLine;
        $body = "Customer: {$customerEmail}\n\n{$message}";
        $this->send($adminEmail, $subject, $body, $customerEmail);
    }

    public function send(string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        if ($to === '') {
            throw new RuntimeException('Recipient email is required.');
        }

        $host = (string) getenv('SMTP_HOST');
        $port = (int) (getenv('SMTP_PORT') ?: 587);
        if ($host !== '' && $this->from !== '') {
            $this->sendViaSmtp($host, $port, $to, $subject, $body, $replyTo);
            return;
        }

        $headers = [
            'From: ' . $this->formatFromHeader(),
            'Content-Type: text/plain; charset=UTF-8',
        ];
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        if (!mail($to, $subject, $body, implode("\r\n", $headers))) {
            throw new RuntimeException('mail() fallback failed. Configure SMTP_* environment variables.');
        }
    }

    private function sendViaSmtp(string $host, int $port, string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        $transport = $port === 465 ? 'ssl://' . $host : $host;
        $stream = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 15);
        if (!$stream) {
            throw new RuntimeException('SMTP connection failed: ' . $errstr);
        }

        $this->expectCode($stream, 220);
        $this->command($stream, 'EHLO localhost', 250);

        if ($port === 587) {
            $this->command($stream, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Failed to enable TLS for SMTP connection.');
            }
            $this->command($stream, 'EHLO localhost', 250);
        }

        $user = (string) getenv('SMTP_USER');
        $pass = (string) getenv('SMTP_PASS');
        if ($user !== '') {
            $this->command($stream, 'AUTH LOGIN', 334);
            $this->command($stream, base64_encode($user), 334);
            $this->command($stream, base64_encode($pass), 235);
        }

        $from = $this->from ?: $user;
        $headers = [
            'From: ' . $this->formatFromHeader($from),
            'To: ' . $to,
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";

        $this->command($stream, 'MAIL FROM:<' . $from . '>', 250);
        $this->command($stream, 'RCPT TO:<' . $to . '>', [250, 251]);
        $this->command($stream, 'DATA', 354);
        fwrite($stream, $message . "\r\n");
        $this->expectCode($stream, 250);
        $this->command($stream, 'QUIT', 221);
        fclose($stream);
    }

    private function formatFromHeader(?string $email = null): string
    {
        $email = $email ?: $this->from;
        return sprintf('%s <%s>', $this->fromName, $email);
    }

    private function command($stream, string $command, int|array $expectedCodes): void
    {
        fwrite($stream, $command . "\r\n");
        $this->expectCode($stream, $expectedCodes);
    }

    private function expectCode($stream, int|array $expectedCodes): void
    {
        $response = '';
        while (($line = fgets($stream, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        $allowed = (array) $expectedCodes;
        if (!in_array($code, $allowed, true)) {
            throw new RuntimeException('Unexpected SMTP response: ' . trim($response));
        }
    }
}
