<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$rootDir = dirname(__DIR__);

// 1. Helper env()
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

// 2. Helper render Blade/PHP menjadi string HTML
if (!function_exists('render_blade_html')) {
    function render_blade_html(string $name, array $data = [], array $mergeData = []): string
    {
        $rootDir = dirname(__DIR__);
        $merged = array_merge($data, $mergeData);
        $cleanName = str_replace(['.', '/'], '-', trim($name));

        $candidates = [
            $rootDir . '/' . $cleanName . '.blade.php',
            $rootDir . '/' . str_replace('.', '/', trim($name)) . '.blade.php',
            $rootDir . '/' . basename($cleanName) . '-index.blade.php',
            $rootDir . '/' . basename($cleanName) . '-login.blade.php',
            $rootDir . '/' . $cleanName . '.php',
        ];

        $targetFile = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $targetFile = $candidate;
                break;
            }
        }

        if ($targetFile === null) {
            return '<p>View <code>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</code> tidak ditemukan.</p>';
        }

        $raw = (string) file_get_contents($targetFile);
        extract($merged, EXTR_SKIP);

        $php = $raw;
        $php = preg_replace('/\{\{--\s*.*?\s*--\}\}/s', '', $php);
        $php = preg_replace('/@csrf\b/i', '<input type="hidden" name="_token" value="">', $php);
        $php = preg_replace('/@method\([\'"](.+?)[\'"]\)/i', '<input type="hidden" name="_method" value="$1">', $php);
        $php = preg_replace_callback('/@error\s*\(\s*[\'"](.+?)[\'"]\s*\)(.*?)@enderror/is', function ($m) {
            return '<?php if (false): ?>' . $m[2] . '<?php endif; ?>';
        }, $php);
        $php = preg_replace('/@elseif\b([^\r\n]*)/i', '<?php elseif$1: ?>', $php);
        $php = preg_replace('/@if\b([^\r\n]*)/i', '<?php if$1: ?>', $php);
        $php = preg_replace('/@else\b/i', '<?php else: ?>', $php);
        $php = preg_replace('/@endif\b/i', '<?php endif; ?>', $php);
        $php = preg_replace('/@foreach\b([^\r\n]*)/i', '<?php foreach$1: ?>', $php);
        $php = preg_replace('/@endforeach\b/i', '<?php endforeach; ?>', $php);
        $php = preg_replace('/@[a-zA-Z0-9_:-]+(?:\s*\([^\r\n]*\))?/m', '', $php);
        $php = preg_replace('/\{\!\!\s*(.+?)\s*\!\!\}/s', '<?php echo $1; ?>', $php);
        $php = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s', function ($m) {
            return '<?php echo htmlspecialchars((string) (' . $m[1] . '), ENT_QUOTES, \'UTF-8\'); ?>';
        }, $php);

        $tmpFile = sys_get_temp_dir() . '/blade_' . md5($targetFile . '_' . $php) . '.php';
        file_put_contents($tmpFile, $php);

        ob_start();
        try {
            require $tmpFile;
            $out = (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > 1) {
                ob_end_clean();
            }
            $out = $raw;
        }

        return trim($out) !== '' ? $out : $raw;
    }
}

// 3. Helper global view()
if (!function_exists('view')) {
    function view(?string $name = null, array $data = [], array $mergeData = []): mixed
    {
        if ($name === null) {
            return array_merge($data, $mergeData);
        }
        $html = render_blade_html($name, $data, $mergeData);
        echo $html;
        return $html;
    }
}
// 3. Helper global view()
if (!function_exists('view')) {
    function view(?string $name = null, array $data = [], array $mergeData = []): mixed
    {
        if ($name === null) {
            return array_merge($data, $mergeData);
        }
        $html = render_blade_html($name, $data, $mergeData);
        echo $html;
        return $html;
    }
}

// --- LETAKKAN DI SINI (3b. Helper session() dan old()) ---
if (!function_exists('session')) {
    function session(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_SESSION ?? [];
        }
        return $_SESSION[$key] ?? $default;
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return $_POST[$key] ?? $default;
    }
}
// --------------------------------------------------------

// 4. Muat konfigurasi / .env jika ada

// 4. Muat konfigurasi / .env jika ada
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

// 5. Autoloader untuk class di root / subfolder
spl_autoload_register(function (string $class) use ($rootDir): void {
    $shortName = basename(str_replace('\\', '/', $class));
    $candidates = [
        $rootDir . '/' . str_replace('\\', '/', $class) . '.php',
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

// 6. Handler exception & fatal error
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

register_shutdown_function(function () use ($rootDir): void {
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
        return;
    }

    $finalHtml = '';
    while (ob_get_level() > 0) {
        $chunk = (string) ob_get_clean();
        if (trim($chunk) !== '') {
            $finalHtml = $chunk;
        }
    }

    if (trim($finalHtml) === '') {
        foreach (['auth-login', 'dashboard', 'welcome'] as $fallbackName) {
            if (is_file($rootDir . '/' . $fallbackName . '.blade.php')) {
                $finalHtml = render_blade_html($fallbackName);
                break;
            }
        }
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Length: ' . strlen($finalHtml));
    }
    echo $finalHtml;
});

// 7. Jalankan app.php jika ada
$appFile = $rootDir . '/app.php';
if (is_file($appFile)) {
    require_once $appFile;
}

// 8. Jalankan controller utama jika tersedia
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
                $result = $instance->$method();
                if (is_string($result) && trim($result) !== '') {
                    echo $result;
                }
                exit;
            }
        }
    }
}

// 9. Fallback render view login
echo render_blade_html('auth-login');
exit;
