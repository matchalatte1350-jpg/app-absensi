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

    if ($buffered !== false && trim($buffered) !== '') {
        echo $buffered;
        exit;
    }

    // Helper render file *.blade.php sederhana tanpa vendor Laravel
    $renderBlade = function (string $bladeFile, array $data = []) use ($rootDir): bool {
        if (!is_file($bladeFile)) {
            return false;
        }
        $raw = file_get_contents($bladeFile);
        if ($raw === false) {
            return false;
        }
        extract($data, EXTR_SKIP);

        // Konversi sintaks Blade dasar ke PHP murni agar aman di serverless
        $php = $raw;
        $php = preg_replace('/\{\{--\s*.*?\s*--\}\}/s', '', $php);
        $php = preg_replace('/@csrf/i', '<input type="hidden" name="_token" value="">', $php);
        $php = preg_replace('/@method\([\'"](.+?)[\'"]\)/i', '<input type="hidden" name="_method" value="$1">', $php);
        $php = preg_replace('/@if\s*\((.+?)\)/', '<?php if ($1): ?>', $php);
        $php = preg_replace('/@elseif\s*\((.+?)\)/', '<?php elseif ($1): ?>', $php);
        $php = preg_replace('/@else/i', '<?php else: ?>', $php);
        $php = preg_replace('/@endif/i', '<?php endif; ?>', $php);
        $php = preg_replace('/@foreach\s*\((.+?)\)/', '<?php foreach ($1): ?>', $php);
        $php = preg_replace('/@endforeach/i', '<?php endforeach; ?>', $php);
        $php = preg_replace('/\{\!\!\s*(.+?)\s*\!\!\}/s', '<?php echo $1; ?>', $php);
        $php = preg_replace('/\{\{\s*(.+?)\s*\}\}/s', '<?php echo htmlspecialchars((string) ($1), ENT_QUOTES, \'UTF-8\'); ?>', $php);

        $tmpFile = sys_get_temp_dir() . '/view_' . md5($bladeFile) . '.php';
        file_put_contents($tmpFile, $php);

        ob_start();
        require $tmpFile;
        $html = ob_get_clean();
        echo $html;
        return true;
    };

    $uriPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    $pageParam = $_GET['page'] ?? $_GET['table'] ?? '';

    // Coba panggil controller ber-namespace App\Http\Controllers
    $controllers = [
        'App\\Http\\Controllers\\AuthController',
        'App\\Http\\Controllers\\DashboardController',
        'App\\Http\\Controllers\\AbsensiController',
    ];

    foreach ($controllers as $ctrl) {
        if (class_exists($ctrl)) {
            $instance = new $ctrl();
            foreach (['index', 'login', 'showLoginForm', 'dashboard', 'home'] as $method) {
                if (method_exists($instance, $method)) {
                    ob_start();
                    $result = $instance->$method();
                    $out = ob_get_clean();

                    if ($out !== false && trim($out) !== '') {
                        echo $out;
                        exit;
                    }
                    if (is_string($result) && trim($result) !== '') {
                        echo $result;
                        exit;
                    }
                }
            }
        }
    }

    // Fallback tampilkan blade view utama yang tersedia di root
    $bladeCandidates = [
        $rootDir . '/auth-login.blade.php',
        $rootDir . '/dashboard.blade.php',
        $rootDir . '/welcome.blade.php',
    ];

    if ($pageParam !== '' && is_file($rootDir . '/' . basename((string) $pageParam) . '-index.blade.php')) {
        array_unshift($bladeCandidates, $rootDir . '/' . basename((string) $pageParam) . '-index.blade.php');
    }

    foreach ($bladeCandidates as $blade) {
        if ($renderBlade($blade)) {
            exit;
        }
    }

    echo '<p>Tidak ada tampilan yang ter-render untuk route <code>' . htmlspecialchars($uriPath ?: '/') . '</code>.</p>';
    exit;
}

ob_end_flush();
