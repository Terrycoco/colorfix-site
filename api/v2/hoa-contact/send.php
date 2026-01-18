<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../autoload.php';

use App\Lib\SmtpMailer;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST required'], 405);
}

$rawInput = file_get_contents('php://input') ?: '';
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = $_POST;
}
if (!is_array($input)) {
    respond(['ok' => false, 'error' => 'Invalid input'], 400);
}

$name = trim((string)($input['name'] ?? ''));
$role = trim((string)($input['role'] ?? ''));
$company = trim((string)($input['company'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$note = trim((string)($input['note'] ?? ''));
$honeypot = trim((string)($input['company_url'] ?? ''));

if ($honeypot !== '') {
    respond(['ok' => true]); // silently ignore bots
}

if ($name === '' || $company === '' || $email === '') {
    respond(['ok' => false, 'error' => 'name, company, and email are required'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['ok' => false, 'error' => 'Valid email required'], 400);
}

$mailConfigPath = dirname(__DIR__, 3) . '/config/mail.php';
if (!is_file($mailConfigPath)) {
    respond(['ok' => false, 'error' => 'Mail config missing'], 500);
}
$mailConfig = require $mailConfigPath;
$mailer = new SmtpMailer($mailConfig);

$toEmail = 'terry@terrymarr.com';
$subject = 'HOA Landing Contact';
$safeNote = $note !== '' ? nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) : '—';
$htmlBody = "
<p><strong>Name:</strong> " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</p>
<p><strong>Role:</strong> " . htmlspecialchars($role !== '' ? $role : '—', ENT_QUOTES, 'UTF-8') . "</p>
<p><strong>HOA / Company:</strong> " . htmlspecialchars($company, ENT_QUOTES, 'UTF-8') . "</p>
<p><strong>Email:</strong> " . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</p>
<p><strong>Note:</strong><br />{$safeNote}</p>
";
$textBody = "Name: {$name}\nRole: " . ($role !== '' ? $role : '—') . "\nHOA / Company: {$company}\nEmail: {$email}\nNote:\n" . ($note !== '' ? $note : '—');

try {
    $mailer->send($toEmail, $subject, $htmlBody, $textBody);
    respond(['ok' => true]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
