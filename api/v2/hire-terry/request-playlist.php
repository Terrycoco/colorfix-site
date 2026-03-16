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

function slugify_request_value(string $value, string $fallback = 'requester'): string {
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : $fallback;
}

function ensure_dir(string $path): bool {
    return is_dir($path) || mkdir($path, 0775, true) || is_dir($path);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST required'], 405);
}

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$projectType = trim((string)($_POST['projectType'] ?? ''));
$goals = trim((string)($_POST['goals'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($name === '' || $email === '' || $projectType === '' || $goals === '') {
    respond(['ok' => false, 'error' => 'name, email, project type, and goals are required'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['ok' => false, 'error' => 'Valid email required'], 400);
}
if (empty($_FILES['photos'])) {
    respond(['ok' => false, 'error' => 'At least one photo is required'], 400);
}

$photoFiles = $_FILES['photos'];
$photoCount = is_array($photoFiles['name'] ?? null) ? count($photoFiles['name']) : 0;
$hasUploadedPhoto = false;
for ($i = 0; $i < $photoCount; $i++) {
    if (($photoFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $hasUploadedPhoto = true;
        break;
    }
}
if (!$hasUploadedPhoto) {
    respond(['ok' => false, 'error' => 'At least one photo is required'], 400);
}

$mailConfigPath = dirname(__DIR__, 3) . '/config/mail.php';
if (!is_file($mailConfigPath)) {
    respond(['ok' => false, 'error' => 'Mail config missing'], 500);
}

$baseContentDir = dirname(__DIR__, 3) . '/content/playlist-request-files';
$requestFolder = slugify_request_value($name) . '-' . date('Ymd-His');
$requestDir = $baseContentDir . '/' . $requestFolder;

if (!ensure_dir($requestDir)) {
    respond(['ok' => false, 'error' => 'Failed to create request folder'], 500);
}

$uploadedFiles = [];
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

if (!empty($_FILES['photos'])) {
    $files = $_FILES['photos'];
    $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;

    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmp = $files['tmp_name'][$i] ?? '';
        $orig = (string)($files['name'][$i] ?? 'photo');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }

        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            continue;
        }

        $safeBase = slugify_request_value(pathinfo($orig, PATHINFO_FILENAME), 'photo');
        $filename = sprintf('%02d-%s.%s', $i + 1, $safeBase, $ext);
        $target = $requestDir . '/' . $filename;

        if (!move_uploaded_file($tmp, $target)) {
            continue;
        }

        $uploadedFiles[] = [
            'name' => $orig,
            'stored_as' => $filename,
            'path' => 'content/playlist-request-files/' . $requestFolder . '/' . $filename,
        ];
    }
}

$mailer = new SmtpMailer(require $mailConfigPath);
$toEmail = 'terry@terrymarr.com';
$subject = 'playlist request';

$safeGoals = $goals !== '' ? nl2br(htmlspecialchars($goals, ENT_QUOTES, 'UTF-8')) : '—';
$safeNotes = $notes !== '' ? nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) : '—';
$safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeProjectType = htmlspecialchars($projectType, ENT_QUOTES, 'UTF-8');
$safeFolder = htmlspecialchars('content/playlist-request-files/' . $requestFolder, ENT_QUOTES, 'UTF-8');

$fileListHtml = '<p><strong>Uploaded files:</strong> None</p>';
$fileListText = "Uploaded files: None";
if ($uploadedFiles) {
    $items = array_map(
        static fn(array $file): string => '<li>' . htmlspecialchars($file['path'], ENT_QUOTES, 'UTF-8') . '</li>',
        $uploadedFiles
    );
    $fileListHtml = "<p><strong>Request folder:</strong> {$safeFolder}</p><p><strong>Uploaded files:</strong></p><ul>" . implode('', $items) . '</ul>';
    $fileListText = "Request folder: content/playlist-request-files/{$requestFolder}\nUploaded files:\n" .
        implode("\n", array_map(static fn(array $file): string => '- ' . $file['path'], $uploadedFiles));
}

$htmlBody = "
<p><strong>Name:</strong> {$safeName}</p>
<p><strong>Email:</strong> {$safeEmail}</p>
<p><strong>Project type:</strong> {$safeProjectType}</p>
<p><strong>Goals:</strong><br />{$safeGoals}</p>
<p><strong>Notes:</strong><br />{$safeNotes}</p>
{$fileListHtml}
";

$textBody = "Name: {$name}\nEmail: {$email}\nProject type: {$projectType}\nGoals:\n" . ($goals !== '' ? $goals : '—') .
    "\n\nNotes:\n" . ($notes !== '' ? $notes : '—') .
    "\n\n{$fileListText}";

try {
    $mailer->send($toEmail, $subject, $htmlBody, $textBody);
    respond([
        'ok' => true,
        'request_folder' => 'content/playlist-request-files/' . $requestFolder,
        'files' => $uploadedFiles,
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
