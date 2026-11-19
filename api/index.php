<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$rootDir = dirname(__DIR__);

// 1. Helper env() jika belum ada
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
        } else {
            $value = getenv($key);
            if ($value === false) {
                return $default;
            }
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                'true', '(true)'   => true,
                'false', '(false)' => false,
                'empty', '(empty)' => '',
                'null', '(null)'   => null,
                default            => $value,
            };
        }

        return $value;
    }
}

// 2. Muat file konfigurasi / .env jika ada
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

// 3. Autoloader untuk class di root repository
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

// 4. Tangkap error fatal / uncaught exception agar tidak blank putih
set_exception_handler(function (Throwable $e): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h3>Terjadi Error pada Aplikasi</h3>';
    echo '<p><strong>Pesan:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . (int) $e->getLine() . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    exit;
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h3>Fatal Error</h3>';
        echo '<p><strong>Pesan:</strong> ' . htmlspecialchars($err['message']) . '</p>';
        echo '<p><strong>File:</strong> ' . htmlspecialchars($err['file']) . ':' . (int) $err['line'] . '</p>';
    }
});

// 5. Jalankan app.php jika ada
$appFile = $rootDir . '/app.php';
if (is_file($appFile)) {
    require $appFile;
    $buffered = ob_get_clean();

    // Jika app.php menghasilkan output langsung, tampilkan
    if ($buffered !== false && trim($buffered) !== '') {
        echo $buffered;
        exit;
    }

    // Coba panggil controller umum jika ada
    foreach (['AuthController', 'AbsensiController', 'DashboardController', 'HomeController'] as $ctrl) {
        if (class_exists($ctrl)) {
            $instance = new $ctrl();
            foreach (['index', 'login', 'dashboard', 'home'] as $method) {
                if (method_exists($instance, $method)) {
                    ob_start();
                    $instance->$method();
                    $out = ob_get_clean();
                    if ($out !== false && trim($out) !== '') {
                        echo $out;
                        exit;
                    }
                }
            }
        }
    }

    // Jika tetap kosong, tampilkan file PHP & class yang terdeteksi agar bisa langsung dihubungkan
    $phpFiles = array_map('basename', glob($rootDir . '/*.php') ?: []);
    $declaredClasses = get_declared_classes();
    $userClasses = array_slice($declaredClasses, -15);

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="font-family:sans-serif;padding:24px;max-width:780px;">';
    echo '<h3>app.php dimuat, tetapi belum mencetak tampilan HTML.</h3>';
    echo '<p><strong>Query saat ini:</strong> <code>' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '-') . '</code></p>';
    echo '<p><strong>File PHP di root:</strong> <code>' . htmlspecialchars(implode(', ', $phpFiles)) . '</code></p>';
    echo '<p><strong>Class terdeteksi (terbaru):</strong> <code>' . htmlspecialchars(implode(', ', $userClasses)) . '</code></p>';
    echo '<p>Kirimkan tangkapan layar info ini (atau isi beberapa baris awal/akhir <code>app.php</code>) agar kita panggil tampilan utamanya.</p>';
    echo '</div>';
    exit;
}

ob_end_flush();
