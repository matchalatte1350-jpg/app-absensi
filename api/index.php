<?php

declare(strict_types=1);

session_start();

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
