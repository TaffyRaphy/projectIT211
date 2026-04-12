<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

ini_set('session.cookie_path', '/');
ini_set('session.cookie_samesite', 'Lax');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function app_env(string $key): string
{
    $value = getenv($key);
    return is_string($value) ? $value : '';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $databaseUrl = app_env('DATABASE_URL');
    if ($databaseUrl === '') {
        $databaseUrl = app_env('POSTGRES_URL');
    }
    if ($databaseUrl === '') {
        $databaseUrl = app_env('POSTGRES_PRISMA_URL');
    }
    if ($databaseUrl === '') {
        throw new RuntimeException('DATABASE_URL/POSTGRES_URL is not set.');
    }

    $parts = parse_url($databaseUrl);
    if ($parts === false) {
        throw new RuntimeException('Invalid DATABASE_URL.');
    }

    $host = $parts['host'] ?? 'localhost';
    $port = isset($parts['port']) ? (int) $parts['port'] : 5432;
    $user = $parts['user'] ?? '';
    $pass = $parts['pass'] ?? '';
    $dbName = ltrim((string) ($parts['path'] ?? ''), '/');
    parse_str((string) ($parts['query'] ?? ''), $query);
    $sslmode = $query['sslmode'] ?? 'require';

    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $dbName, $sslmode);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET TIME ZONE 'Asia/Manila'");

    return $pdo;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function parse_role(?string $value): ?string
{
    return in_array($value, ['admin', 'staff', 'maintenance'], true) ? $value : null;
}

function auth_cookie_secret(): string
{
    $secret = app_env('APP_KEY');
    if ($secret !== '') {
        return $secret;
    }

    $fallback = app_env('DATABASE_URL');
    return $fallback !== '' ? hash('sha256', $fallback) : 'inventory-system-auth-fallback-key';
}

function set_auth_cookie(array $user): void
{
    $payload = base64_encode((string) json_encode([
        'id' => (int) ($user['id'] ?? 0),
        'full_name' => (string) ($user['full_name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
    ], JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', $payload, auth_cookie_secret());
    $value = $payload . '.' . $sig;

    setcookie('auth_user', $value, [
        'expires' => time() + (60 * 60 * 12),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_auth_cookie(): void
{
    setcookie('auth_user', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function read_auth_cookie_user(): ?array
{
    $value = $_COOKIE['auth_user'] ?? '';
    if (!is_string($value) || $value === '') {
        return null;
    }

    $parts = explode('.', $value, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$payload, $sig] = $parts;
    $expectedSig = hash_hmac('sha256', $payload, auth_cookie_secret());
    if (!hash_equals($expectedSig, $sig)) {
        return null;
    }

    $decoded = base64_decode($payload, true);
    if (!is_string($decoded) || $decoded === '') {
        return null;
    }

    $user = json_decode($decoded, true);
    if (!is_array($user)) {
        return null;
    }

    $id = isset($user['id']) ? (int) $user['id'] : 0;
    $role = parse_role(isset($user['role']) ? (string) $user['role'] : null);
    $email = isset($user['email']) ? (string) $user['email'] : '';
    $fullName = isset($user['full_name']) ? (string) $user['full_name'] : '';

    if ($id <= 0 || $role === null || $email === '' || $fullName === '') {
        return null;
    }

    return [
        'id' => $id,
        'role' => $role,
        'email' => $email,
        'full_name' => $fullName,
    ];
}

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) {
        $cookieUser = read_auth_cookie_user();
        if ($cookieUser === null) {
            return null;
        }

        $_SESSION['user'] = $cookieUser;
        return $cookieUser;
    }

    $id = isset($user['id']) ? (int) $user['id'] : 0;
    $role = parse_role(isset($user['role']) ? (string) $user['role'] : null);
    $email = isset($user['email']) ? (string) $user['email'] : '';
    $fullName = isset($user['full_name']) ? (string) $user['full_name'] : '';

    if ($id <= 0 || $role === null || $email === '' || $fullName === '') {
        return null;
    }

    return [
        'id' => $id,
        'role' => $role,
        'email' => $email,
        'full_name' => $fullName,
    ];
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) ($user['id'] ?? 0),
        'full_name' => (string) ($user['full_name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
    ];
    set_auth_cookie($_SESSION['user']);
}

function logout_user(): void
{
    $_SESSION = [];
    clear_auth_cookie();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect_to('api/index.php', ['error' => 'Please log in first']);
    }
    return $user;
}

function current_role(string $default): string
{
    $user = current_user();
    if ($user !== null) {
        return (string) $user['role'];
    }

    $role = parse_role($_GET['as'] ?? null);
    return $role ?? $default;
}

function require_role(array $required): string
{
    $user = require_login();
    $role = (string) $user['role'];
    if (!in_array($role, $required, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    return $role;
}

function query_param(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function int_query_param(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : $value;
}

function utc_to_ph(?string $value, string $format = 'Y-m-d h:i A'): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format($format);
    } catch (Throwable $e) {
        return $value;
    }
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function post_int(string $key): ?int
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? null : $value;
}

function post_float(string $key): ?float
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_FLOAT);
    return $value === false || $value === null ? null : (float) $value;
}

function redirect_to(string $path, array $params = []): void
{
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }
    $query = http_build_query($params);
    $url = $query === '' ? $path : ($path . '?' . $query);
    header('Location: ' . $url, true, 302);
    exit;
}

function validate_login(string $email, string $password): ?array
{
    $stmt = db()->prepare(
        "SELECT id, full_name, email, role, password_hash AS password_value
         FROM public.users
         WHERE LOWER(email) = LOWER(:email)
         LIMIT 1"
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    $storedPassword = (string) ($user['password_value'] ?? '');
    if ($storedPassword === '') {
        return null;
    }

    $normalizedStoredPassword = trim($storedPassword);
    if ($normalizedStoredPassword === '') {
        return null;
    }

    $passwordInfo = password_get_info($normalizedStoredPassword);
    $isHashed = isset($passwordInfo['algo']) && (int) $passwordInfo['algo'] !== 0;

    $isValid = false;
    if ($isHashed) {
        $isValid = password_verify($password, $normalizedStoredPassword);

        if (!$isValid && str_starts_with($normalizedStoredPassword, '$2b$')) {
            $phpBcrypt = '$2y$' . substr($normalizedStoredPassword, 4);
            $isValid = password_verify($password, $phpBcrypt);
        }
    } else {
        $sha1 = sha1($password);
        $md5 = md5($password);
        $sha256 = hash('sha256', $password);
        $cryptValue = crypt($password, $normalizedStoredPassword);
        $isValid = hash_equals($normalizedStoredPassword, $password)
            || hash_equals(strtolower($normalizedStoredPassword), strtolower($sha1))
            || hash_equals(strtolower($normalizedStoredPassword), strtolower($md5))
            || hash_equals(strtolower($normalizedStoredPassword), strtolower($sha256))
            || ($cryptValue !== '' && hash_equals($normalizedStoredPassword, $cryptValue));

        if (!$isValid && str_starts_with($normalizedStoredPassword, '$2b$')) {
            $phpBcrypt = '$2y$' . substr($normalizedStoredPassword, 4);
            $isValid = password_verify($password, $phpBcrypt);
        }
    }

    if (!$isValid) {
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'full_name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
    ];
}

function user_exists_by_email(string $email): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM public.users WHERE LOWER(email) = LOWER(:email) LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Write an entry to the audit_logs table.
 * Silently swallows all errors so it never crashes the main workflow.
 *
 * @param string   $actionType  e.g. 'login', 'create', 'update', 'approve', 'reject', 'complete', 'snapshot'
 * @param string   $tableName   e.g. 'equipment', 'equipment_requests', 'allocations', 'maintenance_logs', 'users'
 * @param int      $recordId    Primary key of the affected row
 * @param int|null $userId      The user performing the action (null = system)
 * @param array|null $oldValues Previous state (for updates)
 * @param array|null $newValues New state
 */
function log_audit(
    string $actionType,
    string $tableName,
    int $recordId,
    ?int $userId = null,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    // Resolve userId from session if not provided
    if ($userId === null) {
        $currentUser = current_user();
        $userId = $currentUser !== null ? (int) $currentUser['id'] : 0;
    }
    if ($userId <= 0) {
        return; // Cannot log without a user
    }

    try {
        db()->prepare(
            'INSERT INTO audit_logs (user_id, action_type, table_name, record_id, old_values, new_values)
             VALUES (:user_id, :action_type, :table_name, :record_id, :old_values, :new_values)'
        )->execute([
            ':user_id'     => $userId,
            ':action_type' => $actionType,
            ':table_name'  => $tableName,
            ':record_id'   => $recordId,
            ':old_values'  => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            ':new_values'  => $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('log_audit failed: ' . $e->getMessage());
    }
}

// Load notification helpers
require_once dirname(__FILE__) . '/NotificationService.php';
