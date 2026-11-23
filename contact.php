<?php
declare(strict_types=1);

/**
 * YUSTAR contact endpoint.
 * PHP 8.1+, PHPMailer dependency in ../private/vendor, HTTPS only in production.
 * Settings and any consent/rate-limit artifacts live outside public_html.
 */

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

function respond(int $status, string $code, string $message = ''): never
{
    http_response_code($status);
    echo json_encode([
        'ok' => $status >= 200 && $status < 300,
        'code' => $code,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, 'method_not_allowed', 'Метод не поддерживается.');
}

// Host-specific configuration. Never publish this file below document root.
$privateDir = dirname(__DIR__, 2) . '/private';
$configPath = $privateDir . '/config.php';
$autoloader = $privateDir . '/vendor/autoload.php';
if (!is_file($configPath) || !is_file($autoloader)) {
    error_log('[yustar-contact] Mailer is not configured');
    respond(503, 'unavailable', 'Отправка временно недоступна. Пожалуйста, свяжитесь с нами по телефону или e-mail.');
}
$config = require $configPath;
if (!is_array($config)) {
    error_log('[yustar-contact] Configuration returned invalid type');
    respond(503, 'unavailable', 'Отправка временно недоступна.');
}
$required = ['site_origin', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_from', 'rate_limit_key'];
foreach ($required as $key) {
    if (empty($config[$key])) {
        error_log('[yustar-contact] Incomplete configuration: ' . $key);
        respond(503, 'unavailable', 'Отправка временно недоступна.');
    }
}

$siteOrigin = rtrim(strtolower((string) $config['site_origin']), '/');
// Origin check prevents cross-site posting and fails closed until the real hostname is set.
$origin = rtrim(strtolower((string) ($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
if ($origin === '' || !hash_equals($siteOrigin, $origin)) {
    respond(403, 'origin_rejected', 'Отправьте форму со страницы сайта.');
}

// Do not accept plaintext POSTs on the real domain. Local dev is only for validation.
$https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
$isLocal = in_array(parse_url($siteOrigin, PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
if (!$https && !$isLocal) {
    respond(403, 'https_required', 'Для отправки требуется защищённое соединение HTTPS.');
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if (!in_array($contentType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)) {
    respond(415, 'unsupported_content_type', 'Формат запроса не поддерживается.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 15000) {
    respond(413, 'too_large', 'Объём заявки слишком большой.');
}

// Named fields only. Never trust the browser-side validation or consent checkbox.
function field(string $name, int $maxBytes): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value)) {
        respond(422, 'invalid_data', 'Проверьте заполненные поля.');
    }
    $value = trim($value);
    if (strlen($value) > $maxBytes || !preg_match('//u', $value)) {
        respond(422, 'invalid_data', 'Проверьте длину и кодировку полей.');
    }
    return str_replace("\0", '', $value);
}

$name = field('name', 400);
$organization = field('organization', 800);
$contact = field('contact', 300);
$task = field('task', 8000);
$consent = field('personal_data_consent', 12);
$consentVersion = field('consent_version', 64);
$honeypot = field('website', 200);

// Honeypot: silently discard obvious bot submissions, no data is sent or retained.
if ($honeypot !== '') {
    respond(200, 'accepted');
}
if ($name === '' || !preg_match('/\S/u', $name) || preg_match('/[\r\n]/', $name)) {
    respond(422, 'invalid_name', 'Укажите ваше имя.');
}
if ($organization !== '' && preg_match('/[\r\n]/', $organization)) {
    respond(422, 'invalid_organization', 'Проверьте название организации.');
}
if ($contact === '' || preg_match('/[\r\n]/', $contact)) {
    respond(422, 'invalid_contact', 'Укажите телефон или e-mail для связи.');
}
$isEmail = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
$digits = preg_replace('/\D/', '', $contact);
$isPhone = preg_match('/^\+?[\d\s().-]+$/D', $contact) === 1
    && strlen($digits) >= 10 && strlen($digits) <= 15;
if (!$isEmail && !$isPhone) {
    respond(422, 'invalid_contact', 'Укажите корректный телефон или e-mail.');
}
if ($consent !== 'yes' || $consentVersion !== '2026-11-11.1') {
    respond(422, 'consent_required', 'Для обработки обращения необходимо согласие на обработку персональных данных.');
}

// Rate-limit requests by keyed hash; raw IPs and form values are not stored in limiter files.
$runtime = $privateDir . '/runtime';
if (!is_dir($runtime) || !is_writable($runtime)) {
    error_log('[yustar-contact] Private runtime directory is not writable');
    respond(503, 'unavailable', 'Отправка временно недоступна.');
}
$rawIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$limitFile = $runtime . '/rate-' . hash_hmac('sha256', $rawIp, (string) $config['rate_limit_key']) . '.json';
$fp = @fopen($limitFile, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    if ($fp) fclose($fp);
    error_log('[yustar-contact] Rate limiter is unavailable');
    respond(503, 'unavailable', 'Отправка временно недоступна.');
}
$now = time();
$raw = stream_get_contents($fp);
$events = json_decode($raw ?: '[]', true);
$events = is_array($events) ? array_values(array_filter($events, static fn($x) => is_int($x) && $x > $now - 1800)) : [];
if (count($events) >= 3) {
    flock($fp, LOCK_UN);
    fclose($fp);
    header('Retry-After: 1800');
    respond(429, 'rate_limited', 'Слишком много попыток отправки. Попробуйте позже или свяжитесь с нами по телефону.');
}
$events[] = $now;
rewind($fp);
ftruncate($fp, 0);
fwrite($fp, json_encode($events));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
@chmod($limitFile, 0600);

// Eliminate dormant limiter files without keeping raw client addresses.
if (random_int(1, 250) === 1) {
    foreach (glob($runtime . '/rate-*.json') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $now - 86400) @unlink($file);
    }
}

require_once $autoloader;
$reference = strtoupper(bin2hex(random_bytes(6)));
$submittedAt = gmdate('Y-m-d\TH:i:s\Z');

$body = "Новая заявка с сайта ООО «ЮСТАР ГРУПП»\n"
    . "Номер: {$reference}\n"
    . "Дата/время (UTC): {$submittedAt}\n\n"
    . "Имя: {$name}\n"
    . "Организация: " . ($organization !== '' ? $organization : 'Не указана') . "\n"
    . "Контакт: {$contact}\n"
    . "Задача:\n" . ($task !== '' ? $task : 'Не указана') . "\n\n"
    . "Согласие на обработку персональных данных: ДА (обязательная непредзаполненная галочка).\n"
    . "Редакция согласия: {$consentVersion}\n"
    . "Адрес согласия: {$siteOrigin}/consent.html\n"
    . "Момент отправки: {$submittedAt}\n"
    . "Цель: ответ на обращение и подготовка технической консультации/предложения.\n";

try {
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = (string) $config['smtp_host'];
    $mailer->Port = (int) $config['smtp_port'];
    $mailer->SMTPAuth = true;
    $mailer->Username = (string) $config['smtp_username'];
    $mailer->Password = (string) $config['smtp_password'];
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mailer->Timeout = 12;
    $mailer->SMTPDebug = 0;
    $mailer->CharSet = 'UTF-8';
    $mailer->isHTML(false);
    $mailer->setFrom((string) $config['smtp_from'], 'ЮСТАР ГРУПП — сайт');
    $mailer->addAddress('ustarg@ya.ru');
    if ($isEmail) {
        $mailer->addReplyTo($contact, $name);
    }
    $mailer->Subject = "Новая заявка с сайта ЮСТАР ГРУПП №{$reference}";
    $mailer->Body = $body;
    $mailer->send();
} catch (Throwable $e) {
    // Never disclose credentials or SMTP diagnostics to a visitor or application logs.
    error_log('[yustar-contact] SMTP dispatch failed for reference ' . $reference);
    respond(502, 'mail_failed', 'Не удалось отправить заявку. Попробуйте ещё раз или напишите нам на ustarg@ya.ru.');
}

// Minimal evidence of consent (no names or contacts; link it with the message ID).
$record = json_encode([
    'id' => $reference,
    'at_utc' => $submittedAt,
    'consent_version' => $consentVersion,
    'consent_checked' => true,
    'source' => '/#contact',
    'mail_accepted_by_smtp' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
if (@file_put_contents($runtime . '/consent-receipts.jsonl', $record, FILE_APPEND | LOCK_EX) === false) {
    error_log('[yustar-contact] Consent receipt write failed for reference ' . $reference);
}
respond(200, 'sent', 'Заявка отправлена.');
