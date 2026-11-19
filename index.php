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
$allowedTables = ['karyawans', 'absensi', 'cutis', 'lemburs', 'divisis', 'gajis', 'izins', 'users'];
$selectedTable = $_GET['table'] ?? 'karyawans';
if (!in_array($selectedTable, $allowedTables, true)) {
    $selectedTable = 'karyawans';
}

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
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo '<p><strong>Jumlah Tabel di Neon:</strong> ' . count($tables) . '</p>';
if ($tables) {
    echo '<ul>';
    foreach ($tables as $t) {
        echo '<li>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul>';
}

echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>App Absensi</title></head><body>';
echo '<h2>Status Aplikasi Absensi (Vercel)</h2>';
echo '<p><strong>Struktur Repository:</strong> File berada di root tanpa folder <code>bootstrap/app.php</code> atau <code>vendor/autoload.php</code>.</p>';
echo '<p><strong>Status Database:</strong> ' . $dbStatus . '</p>';
echo '</body></html>';
