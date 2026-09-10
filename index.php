<?php
declare(strict_types=1);

require __DIR__ . '/app/functions.php';
require __DIR__ . '/app/views.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');

// Helyi útvonal a telepítési oldalhoz; hosztfejlécből nem készítünk URL-t.
$setupPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/';
try {
    $config = load_config(__DIR__ . '/config.php');
} catch (Throwable $exception) {
    error_log('Rovid configuration: ' . $exception->getMessage());
    http_response_code(503);
    render_problem('Már csak a beállítás van hátra.', 'Add meg a saját tárhelyed adatait a használat megkezdéséhez.', $setupPath, true);
}

$path = app_path($config);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
    header('Allow: GET, POST, HEAD');
    http_response_code(405);
    render_problem('Ez a kérés nem támogatott.', 'Nyisd meg az oldalt a böngésződből.', $path);
}

try {
    // Az átirányításhoz nem kell bejelentkezés vagy munkamenet.
    if (array_key_exists('code', $_GET)) {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            http_response_code(405);
            render_problem('Ez a kérés nem támogatott.', 'A rövid linket közvetlenül nyisd meg.', $path);
        }
        $code = input_string($_GET, 'code');
        if (!preg_match('/^[a-zA-Z0-9]{3,32}$/D', $code)) {
            http_response_code(404);
            render_problem('Ez a link nem található.', 'Ellenőrizd a linket. A kis- és nagybetűk különböznek.', $path);
        }
        $pdo = database($config);
        $statement = $pdo->prepare('SELECT id, destination FROM short_links WHERE code = ?');
        $statement->execute([$code]);
        $link = $statement->fetch();
        if (!$link || !valid_url($link['destination'])) {
            http_response_code(404);
            render_problem('Ez a link nem található.', 'Ellenőrizd a linket. A kis- és nagybetűk különböznek.', $path);
        }
        if ($method === 'GET') {
            $pdo->prepare('UPDATE short_links SET clicks = clicks + 1 WHERE id = ?')->execute([$link['id']]);
        }
        header('Location: ' . $link['destination'], true, 302);
        exit;
    }

    start_session($config);
    $error = '';
    $action = input_string($_POST, 'action');
    if ($method === 'POST' && !csrf_valid($_POST)) {
        http_response_code(403);
        $error = 'A munkamenet lejárt vagy a kérés érvénytelen. Frissítsd az oldalt, majd próbáld újra.';
    }

    $pdo = database($config);
    if (!schema_ready($pdo)) {
        if ($method === 'POST' && $action === 'install' && $error === '') {
            try {
                if (!installation_login_allowed($config)) {
                    http_response_code(429);
                    header('Retry-After: 900');
                    $error = 'Túl sok belépési kísérlet. Próbáld újra 15 perc múlva.';
                } elseif (!hash_equals($config['admin_password'], input_string($_POST, 'password'))) {
                    http_response_code(401);
                    $error = 'A megadott jelszó nem megfelelő.';
                } else {
                    import_schema($pdo);
                    finish_login($pdo, $config);
                    $_SESSION['notice'] = 'Az adatbázis telepítése sikerült. Már létre is hozhatod az első rövid linkedet.';
                    go_home($config);
                }
            } catch (Throwable $exception) {
                error_log('Rovid installation: ' . $exception->getMessage());
                http_response_code(503);
                $error = 'Az importálás nem sikerült. Ellenőrizd, hogy a schema.sql fájl fel van-e töltve, az adatbázis-felhasználónak van-e CREATE jogosultsága, és a PHP ideiglenes könyvtára írható-e. A részletek a PHP hibanaplóban találhatók. A hiba javítása után újra megpróbálhatod.';
            }
        } elseif ($method === 'POST' && $error === '') {
            http_response_code(409);
            $error = 'Először importáld az adatbázissémát az alábbi gombbal.';
        }
        render_installation($config, $error);
    }

    if ($method === 'POST' && $error === '') {
        if ($action === 'install') {
            http_response_code(409);
            $error = 'Az adatbázis már telepítve van. Nincs szükség újabb importálásra.';
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            go_home($config);
        }
        if ($action === 'login') {
            if (!login_allowed($pdo, $config)) {
                http_response_code(429);
                header('Retry-After: 900');
                $error = 'Túl sok belépési kísérlet. Próbáld újra 15 perc múlva.';
            } elseif (hash_equals($config['admin_password'], input_string($_POST, 'password'))) {
                finish_login($pdo, $config);
                go_home($config);
            } else {
                http_response_code(401);
                $error = 'A megadott jelszó nem megfelelő.';
            }
        }
    }

    if (!authenticated($config)) {
        if ($method === 'POST' && $error === '') {
            http_response_code(401);
            $error = 'A folytatáshoz jelentkezz be.';
        }
        render_login($config, $error);
    }

    $destination = '';
    $length = $config['default_length'];
    if ($method === 'POST' && $action === 'create' && $error === '') {
        $destination = trim(input_string($_POST, 'url'));
        $requestedLength = valid_length($_POST['length'] ?? null);
        $length = $requestedLength ?? $length;
        if (!valid_url($destination)) {
            http_response_code(422);
            $error = 'Adj meg egy teljes, legfeljebb 8192 bájtos http:// vagy https:// linket, szóköz és beágyazott belépési adatok nélkül.';
        } elseif ($requestedLength === null) {
            http_response_code(422);
            $error = 'A kód hossza 3 és 32 közötti egész szám legyen.';
        } else {
            try {
                $code = create_link($pdo, $destination, $length);
                $_SESSION['result'] = ['code' => $code, 'destination' => $destination];
                go_home($config);
            } catch (OverflowException $exception) {
                http_response_code(409);
                $error = $exception->getMessage();
            }
        }
    }

    $result = $_SESSION['result'] ?? null;
    $success = input_string($_SESSION, 'notice');
    unset($_SESSION['result'], $_SESSION['notice']);
    $total = (int) $pdo->query('SELECT COUNT(*) FROM short_links')->fetchColumn();
    $requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $page = min($requestedPage, max(1, (int) ceil($total / 20)));
    $statement = $pdo->prepare('SELECT code, destination, clicks, created_at FROM short_links ORDER BY id DESC LIMIT 20 OFFSET ?');
    $statement->bindValue(1, ($page - 1) * 20, PDO::PARAM_INT);
    $statement->execute();
    render_dashboard($config, $statement->fetchAll(), $total, $page, $error, $destination, $length, $result, $success);
} catch (Throwable $exception) {
    error_log('Rovid application: ' . $exception->getMessage());
    http_response_code(503);
    render_problem('Az oldal most nem érhető el.', 'Próbáld újra később. Ha te kezeled az oldalt, ellenőrizd az adatbázis-beállításokat és a PHP hibanaplót. Hiányzó táblák esetén a kezdőlapon indíthatod el a séma importálását.', $path);
}
