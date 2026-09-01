<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Create config.php from config.php.example and configure the database.');
}

$config = require $configFile;
session_name('gallery_session');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();

function db(): PDO
{
    static $pdo;
    global $config;
    if (!$pdo) {
        $pdo = new PDO(
            "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
            $config['db_user'],
            $config['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec('SET NAMES utf8mb4');
    }
    return $pdo;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        fail('Требуется авторизация.', 401);
    }
    return $user;
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        fail('Недействительный запрос. Обновите страницу.', 403);
    }
}

function jsonInput(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        fail('Некорректные данные.', 400);
    }
    return $data;
}

function fail(string $message, int $status = 400): never
{
    jsonResponse(['ok' => false, 'message' => $message], $status);
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanText(mixed $value, int $maxLength, string $field): string
{
    $text = trim((string) $value);
    if ($text === '') {
        fail("Поле «{$field}» не может быть пустым.");
    }
    if (mb_strlen($text) > $maxLength) {
        fail("Поле «{$field}» должно содержать не более {$maxLength} символов.");
    }
    return $text;
}

function visitorKey(): string
{
    $user = currentUser();
    if ($user) {
        return 'user:' . $user['id'];
    }
    if (empty($_COOKIE['gallery_visitor'])) {
        $token = bin2hex(random_bytes(32));
        setcookie('gallery_visitor', $token, [
            'expires' => time() + 31536000,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        $_COOKIE['gallery_visitor'] = $token;
    }
    return 'guest:' . $_COOKIE['gallery_visitor'];
}
