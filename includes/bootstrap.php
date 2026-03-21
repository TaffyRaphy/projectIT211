<?php
declare(strict_types=1);

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

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
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

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) ($user['id'] ?? 0),
        'full_name' => (string) ($user['full_name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
    ];
}

function logout_user(): void
{
    $_SESSION = [];
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

function users_password_column(): ?string
{
    static $column = false;
    if ($column !== false) {
        return is_string($column) ? $column : null;
    }

    $stmt = db()->prepare(
        "SELECT column_name
         FROM information_schema.columns
                 WHERE table_name = 'users'
           AND column_name IN ('password_hash', 'password')
                 ORDER BY CASE WHEN table_schema = 'public' THEN 0 ELSE 1 END,
                                    CASE WHEN column_name = 'password_hash' THEN 0 ELSE 1 END
         LIMIT 1"
    );
    $stmt->execute();
    $value = $stmt->fetchColumn();
    $column = is_string($value) ? $value : null;
    return is_string($column) ? $column : null;
}

function validate_login(string $email, string $password): ?array
{
    $passwordColumn = users_password_column();
    if ($passwordColumn === null) {
        return null;
    }

    $stmt = db()->prepare(
        "SELECT id, full_name, email, role, {$passwordColumn} AS password_value
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
