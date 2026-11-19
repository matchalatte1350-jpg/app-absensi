<?php

declare(strict_types=1);
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        $val = getenv($key);
        if ($val === false && isset($_ENV[$key])) {
            $val = $_ENV[$key];
        }
        if ($val === false && isset($_SERVER[$key])) {
            $val = $_SERVER[$key];
        }
        if ($val === false) {
            return $default;
        }
        $lower = strtolower((string) $val);
        if ($lower === 'true' || $lower === '(true)') return true;
        if ($lower === 'false' || $lower === '(false)') return false;
        if ($lower === 'null' || $lower === '(null)') return null;
        if ($lower === 'empty' || $lower === '(empty)') return '';
        return $val;
    }
}
session_start();
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
if (ob_get_level() > 0) {
    ob_end_flush();
}

$rootDir = dirname(__DIR__);

// 1. Muat konfigurasi environment jika ada
foreach ([$rootDir . '/.env', $rootDir . '/env.php', $rootDir . '/config.php'] as $envFile) {
    if (is_file($envFile)) {
        if (str_ends_with($envFile, '.php')) {
            require_once $envFile;
        } else {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $val] = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val, " \t\n\r\0\x0B\"'");
                $_ENV[$key] = $val;
                putenv("$key=$val");
            }
        }
    }
}

// 2. Autoloader untuk class di root repository (mis. AbsensiController, AuthController, Absensi, Cuti)
spl_autoload_register(function (string $class) use ($rootDir): void {
    $shortName = basename(str_replace('\\', '/', $class));
    $candidates = [
        $rootDir . '/' . $shortName . '.php',
        $rootDir . '/controllers/' . $shortName . '.php',
        $rootDir . '/models/' . $shortName . '.php',
        $rootDir . '/app/' . $shortName . '.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// 3. Jika diakses lewat /status atau ?status=1, baru tampilkan info koneksi & tabel
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$trimmedPath = trim($uriPath, '/');

if ($trimmedPath === 'status' || isset($_GET['status'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Status Aplikasi Absensi</h2>';
    echo '<p>Root Directory: ' . htmlspecialchars($rootDir) . '</p>';
    echo '<p>Endpoint Utama: / (atau /login, /dashboard, /absensi)</p>';
    exit;
}

// 4. Cari file entry point / halaman utama aplikasi di root (selain index.php status)
$entryCandidates = [
    $rootDir . '/app.php',
    $rootDir . '/home.php',
    $rootDir . '/dashboard.php',
    $rootDir . '/login.php',
    $rootDir . '/router.php',
];

foreach ($entryCandidates as $entry) {
    if (is_file($entry)) {
        require $entry;
        exit;
    }
}

// 5. Fallback routing langsung ke Controller/View di root jika tidak ada file entry khusus
$routeMap = [
    ''          => ['AuthController', 'index'],
    'login'     => ['AuthController', 'login'],
    'logout'    => ['AuthController', 'logout'],
    'dashboard' => ['AbsensiController', 'index'],
    'absensi'   => ['AbsensiController', 'index'],
    'cuti'      => ['CutiController', 'index'],
];

$routeKey = strtolower(explode('/', $trimmedPath)[0] ?? '');
if (isset($routeMap[$routeKey])) {
    [$controllerClass, $method] = $routeMap[$routeKey];
    if (class_exists($controllerClass)) {
        $controller = new $controllerClass();
        if (method_exists($controller, $method)) {
            $controller->$method();
            exit;
        }
        if (method_exists($controller, 'index')) {
            $controller->index();
            exit;
        }
    }
}

// 6. Jika masih belum ketemu, tampilkan daftar file PHP di root agar mudah disesuaikan
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
echo '<h3>Entry point aplikasi belum terhubung otomatis</h3>';
echo '<p>File di root terdeteksi. Jika halaman utama berada di file tertentu (misalnya <code>login.php</code> atau <code>dashboard.php</code>), beri tahu nama filenya agar langsung dipanggil.</p>';
echo '<ul>';
foreach (glob($rootDir . '/*.php') ?: [] as $phpFile) {
    echo '<li>' . htmlspecialchars(basename($phpFile)) . '</li>';
}
echo '</ul>';
$dbUrl = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '');
if ($dbUrl !== '') {
    try {
        $parsed = parse_url($dbUrl);
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? 5432;
        $dbname = ltrim($parsed['path'] ?? 'neondb', '/');
        $user = $parsed['user'] ?? '';
        $pass = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $allowedTables = ['karyawans', 'absensi', 'cutis', 'lemburs', 'divisis', 'gajis', 'izins', 'users'];
        $selectedTable = $_GET['table'] ?? 'karyawans';
        if (!in_array($selectedTable, $allowedTables, true)) {
            $selectedTable = 'karyawans';
        }

        echo '<div style="margin:20px 0;font-family:sans-serif;">';
        echo '<p><strong>Tabel:</strong> ';
        foreach ($allowedTables as $tbl) {
            if (in_array($tbl, $tables, true)) {
                echo '<a href="?table=' . urlencode($tbl) . '" style="margin-right:10px;">' . htmlspecialchars($tbl, ENT_QUOTES, 'UTF-8') . '</a>';
            }
        }
        echo '</p>';

        if (in_array($selectedTable, $tables, true)) {
            $rowsStmt = $pdo->query('SELECT * FROM "' . $selectedTable . '" LIMIT 20');
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
            echo '<h3>Data Tabel: ' . htmlspecialchars($selectedTable, ENT_QUOTES, 'UTF-8') . '</h3>';
            if ($rows) {
                echo '<table border="1" cellpadding="6" cellspacing="0"><tr>';
                foreach (array_keys($rows[0]) as $col) {
                    echo '<th>' . htmlspecialchars((string)$col, ENT_QUOTES, 'UTF-8') . '</th>';
                }
                echo '</tr>';
                foreach ($rows as $row) {
                    echo '<tr>';
                    foreach ($row as $val) {
                        echo '<td>' . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</table>';
            } else {
                echo '<p>Belum ada data di tabel ini.</p>';
            }
        }
        echo '</div>';
    } catch (Throwable $e) {
        echo '<p style="color:red;">Error DB: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    }
}
