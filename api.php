<?php
/**
 * Umma Bites — API sederhana (tanpa database)
 * - Menyimpan setiap order ke file JSON di folder ./data
 * - Login admin + riwayat transaksi + omset
 * Butuh: PHP 7.4+ (hosting biasa sudah cukup). Letakkan satu folder dengan umma-bites.html
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/* ====== PENGATURAN ====== */
// Hash PIN admin (bukan PIN asli). Untuk ganti PIN lihat catatan di bagian bawah file.
const ADMIN_HASH = '$6$059bf378ccf17b64$SjP8/.XK9CDMdhkV/Eb3qI//qooCzi4CUIXPNcfIaoC8sY3Ht7rwFuIoxkHeb9b.pTtnVqH6L1yKGaekLXXZp/';
const STORE_KEY  = 'f60ddda2c8d40af7fee9';   // bagian acak nama file data; jangan diubah setelah ada data
const PRICE      = 2000;
/* ======================== */

$DIR = __DIR__ . '/data';

function out(array $d, int $c = 200): void {
    http_response_code($c);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function body(): array {
    $r = file_get_contents('php://input');
    $j = json_decode($r === false ? '' : $r, true);
    return is_array($j) ? $j : [];
}
function ensure_dir(): void {
    global $DIR;
    if (!is_dir($DIR)) { @mkdir($DIR, 0755, true); }
    $ht = $DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
    $ix = $DIR . '/index.html';
    if (!is_file($ix)) { @file_put_contents($ix, ''); }
}
function cstr($v, int $max): string {
    if (!is_string($v)) return '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    if ($v === null) return '';
    return mb_substr(trim($v), 0, $max);
}
function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? '0'); }

/* Pembatas laju sederhana (per IP) */
function rl(string $key, int $win, ?int $max = null, bool $hit = false, bool $clear = false): bool {
    global $DIR;
    ensure_dir();
    $fp = @fopen($DIR . '/rl-' . STORE_KEY . '.json', 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $d = json_decode($raw ? $raw : '{}', true);
    if (!is_array($d)) $d = [];
    $now = time();
    foreach ($d as $k => $v) {
        $d[$k] = array_values(array_filter((array)$v, function ($t) use ($now) { return $t > $now - 3700; }));
        if (!$d[$k]) unset($d[$k]);
    }
    $arr = array_values(array_filter($d[$key] ?? [], function ($t) use ($now, $win) { return $t > $now - $win; }));
    $ok = ($max === null) || (count($arr) < $max);
    if ($clear) { unset($d[$key]); }
    elseif ($hit) { $arr[] = $now; $d[$key] = $arr; }
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($d));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}

function store_path(): string { global $DIR; return $DIR . '/orders-' . STORE_KEY . '.json'; }

function read_orders(): array {
    ensure_dir();
    $p = store_path();
    if (!is_file($p)) return [];
    $j = json_decode((string)@file_get_contents($p), true);
    return is_array($j) ? $j : [];
}

/* Tambah order (dengan kunci file). Return: 'ok' | 'dup' | 'err' */
function add_order(array $o): string {
    ensure_dir();
    $fp = @fopen(store_path(), 'c+');
    if (!$fp) return 'err';
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $d = json_decode($raw ? $raw : '[]', true);
    if (!is_array($d)) $d = [];
    foreach ($d as $x) { if (isset($x['id']) && $x['id'] === $o['id']) { flock($fp, LOCK_UN); fclose($fp); return 'dup'; } }
    $d[] = $o;
    ftruncate($fp, 0); rewind($fp);
    $w = fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $w === false ? 'err' : 'ok';
}

/* Ubah status pembayaran transfer. Return: 'ok' | 'nf' | 'bad' | 'err' */
function set_paid(string $id, bool $v, int $recv): string {
    ensure_dir();
    $fp = @fopen(store_path(), 'c+');
    if (!$fp) return 'err';
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $d = json_decode($raw ? $raw : '[]', true);
    if (!is_array($d)) $d = [];
    $found = false;
    foreach ($d as $k => $x) {
        if (isset($x['id']) && $x['id'] === $id) {
            $total = (int)($x['total'] ?? 0);
            if (!$v && $recv >= $total && $total > 0) { flock($fp, LOCK_UN); fclose($fp); return 'bad'; }
            $d[$k]['paid'] = $v;
            $d[$k]['recv'] = $v ? $total : max(0, $recv);
            $d[$k]['paid_at'] = $v ? date('c') : null;
            $found = true;
            break;
        }
    }
    if (!$found) { flock($fp, LOCK_UN); fclose($fp); return 'nf'; }
    ftruncate($fp, 0); rewind($fp);
    $w = fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $w === false ? 'err' : 'ok';
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $sec = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('ubadm');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $sec, 'httponly' => true, 'samesite' => 'Lax']);
    @session_start();
}
function is_admin(): bool {
    start_session();
    if (empty($_SESSION['adm'])) return false;
    if (time() - (int)($_SESSION['t'] ?? 0) > 8 * 3600) { $_SESSION = []; return false; }
    $_SESSION['t'] = time();
    return true;
}

function validate_order(array $b): ?array {
    $id = (string)($b['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $id)) return null;
    $nm = cstr($b['nm'] ?? '', 60);
    if (mb_strlen($nm) < 2) return null;
    $wa = preg_replace('/\D/', '', (string)($b['wa'] ?? ''));
    if (!preg_match('/^08\d{8,11}$/', (string)$wa)) return null;
    $mk = (string)($b['mk'] ?? '');
    if (!in_array($mk, ['pertamina', 'ojol', 'pp', 'pm'], true)) return null;
    $ml = cstr($b['ml'] ?? '', 120);
    $sj = cstr($b['sj'] ?? '', 80);
    $al = cstr($b['al'] ?? '', 300);
    $pt = cstr($b['pt'] ?? '', 120);
    $its = $b['items'] ?? null;
    if (!is_array($its) || count($its) < 1 || count($its) > 30) return null;
    $items = []; $pcs = 0;
    foreach ($its as $it) {
        if (!is_array($it)) return null;
        $n = cstr($it['n'] ?? '', 60);
        $q = (int)($it['q'] ?? 0);
        if ($n === '' || $q < 1 || $q > 9999) return null;
        $items[] = ['n' => $n, 'q' => $q, 'h' => $q * PRICE, 't' => cstr($it['t'] ?? '', 400)];
        $pcs += $q;
    }
    $pay = 'tf';   // pembayaran hanya via transfer
    $lat = null; $lng = null;
    if (isset($b['lat'], $b['lng']) && is_numeric($b['lat']) && is_numeric($b['lng'])) {
        $la = (float)$b['lat']; $lo = (float)$b['lng'];
        if ($la >= -90 && $la <= 90 && $lo >= -180 && $lo <= 180) { $lat = round($la, 6); $lng = round($lo, 6); }
    }
    return [
        'id' => $id, 'ts' => date('c'), 'd' => date('Y-m-d'), 'lat' => $lat, 'lng' => $lng, 'pay' => $pay, 'paid' => false, 'recv' => 0,
        'nm' => $nm, 'wa' => $wa, 'mk' => $mk, 'ml' => $ml, 'sj' => $sj,
        'al' => $al, 'pt' => $pt, 'items' => $items, 'pcs' => $pcs, 'total' => $pcs * PRICE,
    ];
}

/* ====== ROUTER ====== */
$a = (string)($_GET['a'] ?? '');

if ($a === 'order') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['error' => 'Metode tidak diizinkan.'], 405);
    $ipk = 'o|' . sha1(client_ip());
    if (!rl($ipk, 3600, 30, true)) out(['error' => 'Terlalu banyak permintaan.'], 429);
    $o = validate_order(body());
    if ($o === null) out(['error' => 'Data pesanan tidak valid.'], 400);
    $r = add_order($o);
    if ($r === 'err') out(['error' => 'Gagal menyimpan.'], 500);
    out(['ok' => true, 'dup' => $r === 'dup']);
}

if ($a === 'login') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['error' => 'Metode tidak diizinkan.'], 405);
    $lk = 'l|' . sha1(client_ip());
    if (!rl($lk, 900, 5)) out(['error' => 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.'], 429);
    if (!rl('lg|all', 3600, 40)) out(['error' => 'Login dikunci sementara karena terlalu banyak percobaan gagal. Coba lagi nanti.'], 429);
    $b = body();
    $p = substr(preg_replace('/\D/', '', (string)($b['pin'] ?? '')), 0, 12);
    if ($p !== '' && password_verify($p, ADMIN_HASH)) {
        rl($lk, 900, null, false, true);
        start_session();
        session_regenerate_id(true);
        $_SESSION['adm'] = true;
        $_SESSION['t'] = time();
        out(['ok' => true]);
    }
    usleep(500000);
    rl($lk, 900, null, true);
    rl('lg|all', 3600, null, true);
    out(['error' => 'PIN salah.'], 401);
}

if ($a === 'me') {
    out(['admin' => is_admin()]);
}

if ($a === 'logout') {
    start_session();
    $_SESSION = [];
    @session_destroy();
    out(['ok' => true]);
}

if ($a === 'paid') {
    if ((string)($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['error' => 'Metode tidak diizinkan.'], 405);
    if (!is_admin()) out(['error' => 'Belum login.'], 401);
    $b = body();
    $id = (string)($b['id'] ?? '');
    $v = !empty($b['paid']);
    if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $id)) out(['error' => 'ID tidak valid.'], 400);
    $recv = isset($b['recv']) ? (int)$b['recv'] : 0;
    $r = set_paid($id, $v, $recv);
    if ($r === 'bad') out(['error' => 'Nominal harus lebih kecil dari total. Gunakan Tandai lunas.'], 400);
    if ($r === 'nf') out(['error' => 'Transaksi tidak ditemukan.'], 404);
    if ($r !== 'ok') out(['error' => 'Gagal menyimpan.'], 500);
    out(['ok' => true, 'paid' => $v]);
}

if ($a === 'list') {
    if (!is_admin()) out(['error' => 'Belum login.'], 401);
    $all = array_reverse(read_orders());   // terbaru di atas
    out(['ok' => true, 'orders' => $all]);
}

out(['error' => 'Endpoint tidak dikenal.'], 404);

/*
 * GANTI PIN ADMIN (angka saja, disarankan 6 digit atau lebih):
 * 1) Buat file sementara, mis. hash.php berisi:  <?php echo password_hash('PIN_BARU', PASSWORD_DEFAULT);
 * 2) Buka di browser, salin hasilnya ke ADMIN_HASH di atas, lalu HAPUS hash.php.
 * Data order tersimpan di: data/orders-<STORE_KEY>.json (bisa di-backup lewat File Manager/FTP).
 */
