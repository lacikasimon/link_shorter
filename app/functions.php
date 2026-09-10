<?php
declare(strict_types=1);

const MIN_CODE_LENGTH = 3;
const MAX_CODE_LENGTH = 32;
const CODE_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input_string(array $input, string $key): string
{
    return isset($input[$key]) && is_string($input[$key]) ? $input[$key] : '';
}

function valid_url(string $url): bool
{
    if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)) {
        return false;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $parts = parse_url($url);
    return is_array($parts)
        && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        && !isset($parts['user']) && !isset($parts['pass']);
}

function valid_length(mixed $value): ?int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,2}$/D', (string) $value)) {
        return null;
    }
    $length = (int) $value;
    return $length >= MIN_CODE_LENGTH && $length <= MAX_CODE_LENGTH ? $length : null;
}

function load_config(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException('A config.php még nincs beállítva.');
    }
    $config = require $file;
    if (!is_array($config) || !is_array($config['db'] ?? null)) {
        throw new RuntimeException('Hiányos konfiguráció.');
    }
    $base = $config['base_url'] ?? null;
    if (!is_string($base) || !valid_url($base) || strpbrk($base, '?#') !== false) {
        throw new RuntimeException('Érvénytelen base_url.');
    }
    $config['base_url'] = rtrim($base, '/');
    $password = $config['admin_password'] ?? null;
    if (!is_string($password) || strlen($password) < 12 || $password === 'CSERELD_LE_EGY_SAJAT_JELSZORA') {
        throw new RuntimeException('Állíts be saját, legalább 12 karakteres kezelői jelszót.');
    }
    $config['default_length'] = valid_length($config['default_length'] ?? 5);
    if ($config['default_length'] === null) {
        throw new RuntimeException('A default_length 3 és 32 közötti egész szám legyen.');
    }
    foreach (['host', 'name', 'user', 'password'] as $key) {
        if (!isset($config['db'][$key]) || !is_string($config['db'][$key])) {
            throw new RuntimeException('Hiányos adatbázis-beállítás.');
        }
    }
    if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', $config['db']['host'])
        || !preg_match('/^[a-zA-Z0-9_$-]+$/D', $config['db']['name'])) {
        throw new RuntimeException('Érvénytelen adatbázisnév vagy kiszolgáló.');
    }
    $port = filter_var($config['db']['port'] ?? 3306, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) {
        throw new RuntimeException('Érvénytelen adatbázis-port.');
    }
    $config['db']['port'] = $port;
    $config['query_links'] = (bool) ($config['query_links'] ?? false);
    return $config;
}

function database(array $config): PDO
{
    $db = $config['db'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
        $db['user'], $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function schema_ready(PDO $pdo): bool
{
    $queries = [
        'SELECT id, code, destination, clicks, created_at FROM short_links LIMIT 0',
        'SELECT client_key, attempts, window_started FROM short_login_attempts LIMIT 0',
    ];
    foreach ($queries as $query) {
        try {
            $pdo->query($query);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1146) {
                return false;
            }
            throw $exception;
        }
    }
    return true;
}

function import_schema(PDO $pdo): void
{
    // Kizárólag a csomag saját sémája importálható, feltöltött SQL nem.
    $file = dirname(__DIR__) . '/schema.sql';
    if (!is_readable($file)) {
        throw new RuntimeException('A schema.sql nem olvasható.');
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('A schema.sql nem olvasható.');
    }
    $sql = str_starts_with($sql, "\xEF\xBB\xBF") ? substr($sql, 3) : $sql;
    $sql = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $sql);
    $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
    $tables = [];
    foreach ($statements as $statement) {
        if (!preg_match('/\ACREATE TABLE IF NOT EXISTS (short_links|short_login_attempts)\s*\(/i', $statement, $match)) {
            throw new RuntimeException('A schema.sql nem a támogatott telepítési séma.');
        }
        $tables[] = strtolower($match[1]);
    }
    sort($tables);
    if ($tables !== ['short_links', 'short_login_attempts']) {
        throw new RuntimeException('A schema.sql hiányos.');
    }
    // A MySQL DDL nem tranzakciós. Az IF NOT EXISTS miatt a félbeszakadt import újraindítható.
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    if (!schema_ready($pdo)) {
        throw new RuntimeException('Az adatbázis telepítése nem fejeződött be.');
    }
}

function installation_login_allowed(array $config): bool
{
    // Telepítés előtt még nincs meg a belépési kísérleteket tároló adatbázistábla.
    // A zárolt fájl alkalmazásonként és kliensenként külön számlál, új sütivel is.
    $scope = hash_hmac('sha256', $config['base_url'] . '|' . $config['db']['name'], $config['admin_password']);
    $directory = rtrim(sys_get_temp_dir(), '/\\') . '/rovid-install-' . $scope;
    if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException('A telepítés ideiglenes könyvtára nem hozható létre.');
    }
    $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = @fopen($directory . '/' . $key . '.json', 'c+');
    if ($file === false) {
        throw new RuntimeException('A telepítés belépési korlátja nem érhető el.');
    }
    try {
        if (!flock($file, LOCK_EX)) {
            throw new RuntimeException('A telepítés belépési korlátja nem zárolható.');
        }
        $state = json_decode(stream_get_contents($file), true);
        $now = time();
        if (!is_array($state) || !isset($state['started'], $state['attempts']) || $state['started'] <= $now - 900) {
            $state = ['started' => $now, 'attempts' => 0];
        }
        $state['attempts'] = min((int) $state['attempts'] + 1, 6);
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($file);
        if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
            throw new RuntimeException('A telepítés belépési korlátja nem menthető.');
        }
        return $state['attempts'] <= 5;
    } finally {
        fclose($file);
    }
}

function app_path(array $config): string
{
    return rtrim(parse_url($config['base_url'], PHP_URL_PATH) ?: '', '/') . '/';
}

function short_url(array $config, string $code): string
{
    return $config['base_url'] . ($config['query_links'] ? '/index.php?code=' : '/') . rawurlencode($code);
}

function random_code(int $length): string
{
    if (valid_length($length) === null) {
        throw new InvalidArgumentException('Érvénytelen kódhossz.');
    }
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= CODE_ALPHABET[random_int(0, strlen(CODE_ALPHABET) - 1)];
    }
    return $code;
}

function reserved_code(string $code): bool
{
    return in_array(strtolower($code), ['app', 'assets', 'tests', 'dist', 'index', 'config', 'readme',
        'schema', 'favicon', 'robots', 'cgi', 'cpanel', 'webmail', 'webdisk'], true)
        || file_exists(dirname(__DIR__) . '/' . $code);
}

function create_link(PDO $pdo, string $destination, int $length): string
{
    if (!valid_url($destination) || valid_length($length) === null) {
        throw new InvalidArgumentException('Érvénytelen link vagy kódhossz.');
    }
    $insert = $pdo->prepare('INSERT INTO short_links (code, destination) VALUES (?, ?)');
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $code = random_code($length);
        if (reserved_code($code)) {
            continue;
        }
        try {
            $insert->execute([$code, $destination]);
            return $code;
        } catch (PDOException $exception) {
            // A UNIQUE index kezeli az egyidejű kódütközéseket is.
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
        }
    }
    throw new OverflowException('Ezzel a hosszal most nem sikerült szabad kódot találni. Válassz több karaktert.');
}

function start_session(array $config): void
{
    $secure = strtolower(parse_url($config['base_url'], PHP_URL_SCHEME)) === 'https';
    session_name('rovid_' . substr(hash('sha256', $config['base_url']), 0, 12));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => app_path($config), 'secure' => $secure,
        'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function authenticated(array $config): bool
{
    $fingerprint = hash('sha256', $config['admin_password']);
    return isset($_SESSION['auth'], $_SESSION['expires']) && is_string($_SESSION['auth'])
        && hash_equals($fingerprint, $_SESSION['auth']) && $_SESSION['expires'] > time();
}

function csrf_valid(array $post): bool
{
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], input_string($post, 'csrf'));
}

function login_allowed(PDO $pdo, array $config): bool
{
    // Csak a közvetlen kliens címét használjuk; a proxy fejlécek nem megbízhatók.
    $key = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $config['admin_password']);
    $statement = $pdo->prepare(
        'INSERT INTO short_login_attempts (client_key, attempts, window_started) VALUES (?, 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
         attempts = IF(window_started <= UTC_TIMESTAMP() - INTERVAL 15 MINUTE, 1, attempts + 1),
         window_started = IF(window_started <= UTC_TIMESTAMP() - INTERVAL 15 MINUTE, UTC_TIMESTAMP(), window_started)'
    );
    $statement->execute([$key]);
    $statement = $pdo->prepare('SELECT attempts FROM short_login_attempts WHERE client_key = ?');
    $statement->execute([$key]);
    $allowed = (int) $statement->fetchColumn() <= 5;
    if (random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM short_login_attempts WHERE window_started < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 1000');
    }
    return $allowed;
}

function finish_login(PDO $pdo, array $config): void
{
    $key = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $config['admin_password']);
    $pdo->prepare('DELETE FROM short_login_attempts WHERE client_key = ?')->execute([$key]);
    session_regenerate_id(true);
    $_SESSION['auth'] = hash('sha256', $config['admin_password']);
    $_SESSION['expires'] = time() + 8 * 60 * 60;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function go_home(array $config): never
{
    header('Location: ' . $config['base_url'] . '/', true, 303);
    exit;
}

function display_date(string $date): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y. m. d. H:i');
}
