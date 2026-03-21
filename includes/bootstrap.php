<?php
declare(strict_types=1);

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

function current_role(string $default): string
{
    $role = parse_role($_GET['as'] ?? null);
    return $role ?? $default;
}

function require_role(array $required): string
{
    $role = parse_role($_GET['as'] ?? null);
    if ($role === null || !in_array($role, $required, true)) {
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
        'SELECT id, full_name, email, role, password_hash FROM users WHERE email = :email'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'full_name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
    ];
}
