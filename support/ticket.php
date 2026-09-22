<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

try {
    $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));

    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || $subject === '' || $message === '') {
        throw new InvalidArgumentException('Please provide a valid email, subject, and message.');
    }

    $mailer = new SimpleMailer();
    $mailer->sendSupportTicket($customerEmail, $subject, $message);

    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Support request sent</title><body><p>Your message has been sent to PrivacyArmy support.</p><p><a href="/support/faq/index.html">Return to support</a></p></body></html>';
} catch (Throwable $exception) {
    http_response_code(400);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Support request failed</title><body><p>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p><p><a href="/support/faq/index.html">Return to support</a></p></body></html>';
}
