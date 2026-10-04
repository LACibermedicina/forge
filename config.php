<?php
/**
 * TestForge AI — bootstrap de configuração
 * - Carrega .env (sem dependências externas)
 * - Abre sessão, define helpers de JSON e autorização
 * Nenhuma chave é exposta ao front-end.
 */
declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');
define('UPLOAD_DIR', DATA_DIR . '/uploads');
define('DB_PATH', DATA_DIR . '/testforge.sqlite');
define('APP_VERSION', '1.0.0');

date_default_timezone_set(envv('APP_TZ', 'America/Sao_Paulo'));

foreach ([DATA_DIR, UPLOAD_DIR] as $d) {
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
}

/** Lê .env para getenv(), sem sobrescrever variáveis já existentes. */
function env_load(string $path): void
{
    if (!is_file($path)) { return; }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if (getenv($k) === false || getenv($k) === '') {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}
env_load(APP_ROOT . '/.env');

function envv(string $key, string $default = ''): string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/* ------------------------------------------------------------------ */
/*  Sessão + respostas JSON                                            */
/* ------------------------------------------------------------------ */
function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
    session_name('tf_session');
    session_start();
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function fail(string $msg, int $code = 400, array $extra = []): void
{
    json_out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

function ok($data = [], array $extra = []): void
{
    json_out(array_merge(['ok' => true, 'data' => $data], $extra));
}

function body(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    $cache = is_array($j) ? $j : [];
    if (empty($cache) && !empty($_POST)) { $cache = $_POST; }
    return $cache;
}

function param(string $key, $default = null)
{
    $b = body();
    if (array_key_exists($key, $b)) { return $b[$key]; }
    if (array_key_exists($key, $_GET)) { return $_GET[$key]; }
    return $default;
}

function s(string $key, string $default = ''): string
{
    $v = param($key, $default);
    return is_scalar($v) ? trim((string)$v) : $default;
}

/* ------------------------------------------------------------------ */
/*  Identidade / papéis                                                */
/* ------------------------------------------------------------------ */
const ROLES = ['admin', 'solicitante', 'testador'];

function current_user(): ?array
{
    boot_session();
    $id = $_SESSION['uid'] ?? null;
    if (!$id) { return null; }
    static $u = null;
    if ($u && (int)$u['id'] === (int)$id) { return $u; }
    $stmt = pdo()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
    $stmt->execute([$id]);
    $u = $stmt->fetch() ?: null;
    return $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) { fail('Sessão expirada ou não autenticada. Faça login.', 401); }
    return $u;
}

/** Aceita um papel ou lista de papéis. Admin sempre passa. */
function require_role(...$roles): array
{
    $u = require_login();
    if ($u['role'] === 'admin') { return $u; }
    if (!in_array($u['role'], $roles, true)) {
        fail('Seu perfil (' . $u['role'] . ') não tem permissão para esta ação.', 403);
    }
    return $u;
}

function csrf_token(): string
{
    boot_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
