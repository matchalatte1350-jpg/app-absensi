<?php
// index.php untuk struktur flat di Vercel PHP

header('Content-Type: text/html; charset=utf-8');

$autoload = __DIR__ . '/vendor/autoload.php';
$bootstrapApp = __DIR__ . '/bootstrap/app.php';

if (file_exists($autoload) && file_exists($bootstrapApp)) {
    require_once $autoload;
    $app = require_once $bootstrapApp;
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle(
        $request = Illuminate\Http\Request::capture()
    )->send();
    $kernel->terminate($request, $response);
    exit;
}

// Fallback halaman status & cek koneksi database Neon jika folder vendor/bootstrap tidak ada di repo
$dbUrl = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '');
$dbStatus = 'DATABASE_URL belum diatur di Environment Variables Vercel.';

if ($dbUrl !== '') {
    if (extension_loaded('pdo_pgsql')) {
        try {
            $parts = parse_url($dbUrl);
            $host = $parts['host'] ?? '';
            $port = $parts['port'] ?? 5432;
            $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
            $user = $parts['user'] ?? '';
            $pass = $parts['pass'] ?? '';

            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $dbStatus = 'Terhubung ke PostgreSQL (Neon).';
        } catch (Throwable $e) {
            $dbStatus = 'Gagal koneksi ke Neon: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    } else {
        $dbStatus = 'Ekstensi pdo_pgsql tidak aktif di runtime PHP.';
    }
}

echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>App Absensi</title></head><body>';
echo '<h2>Status Aplikasi Absensi (Vercel)</h2>';
echo '<p><strong>Struktur Repository:</strong> File berada di root tanpa folder <code>bootstrap/app.php</code> atau <code>vendor/autoload.php</code>.</p>';
echo '<p><strong>Status Database:</strong> ' . $dbStatus . '</p>';
echo '</body></html>';
