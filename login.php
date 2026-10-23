<?php
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/_lib.php';

// Sudah login? langsung ke halaman sesuai role.
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . beranda_peran());
    exit;
}

$error = '';
if (isset($_GET['timeout'])) {
    $error = 'Sesi Anda berakhir karena tidak ada aktivitas. Silakan masuk kembali.';
}
if (isset($_GET['nonaktif'])) {
    $error = 'Akun Anda dinonaktifkan atau dihapus oleh Super Admin, sehingga sesinya diakhiri.';
}
if (isset($_GET['diakhiri'])) {
    /* Pesannya menyebut SEBAB yang tercatat, bukan menebak. Orang yang
       terlempar keluar berulang kali butuh tahu apakah itu ulahnya sendiri
       dari perangkat lain, tindakan Super Admin, atau sesuatu yang menyentuh
       basis data langsung — ketiganya butuh penanganan yang berbeda. */
    $peta = [
        'lain'   => 'Sesi ini Anda akhiri sendiri dari perangkat lain. Silakan masuk kembali.',
        'admin'  => 'Sesi ini diakhiri oleh Super Admin. Hubungi beliau bila Anda merasa '
                  . 'ini tidak semestinya.',
        'sistem' => 'Sesi ini berakhir tanpa melewati aplikasi — biasanya karena data sesi '
                  . 'diubah langsung di basis data, atau tabel sesinya dibersihkan. '
                  . 'Bila ini terjadi berulang kali, laporkan ke pengelola sistem.',
    ];
    $error = $peta[$_GET['diakhiri']] ?? $peta['sistem'];
    $error .= ' Anda dapat melihat daftar perangkat Anda di Pengaturan › Perangkat '
            . 'setelah masuk.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        if ($email === '' || $pass === '') {
            $error = 'Email dan password wajib diisi.';
        } else {
            /* Pembatasan percobaan login — berbasis TABEL, bukan sesi.
               Hitungan di $_SESSION dulu bisa dibuang bersama cookie-nya;
               yang di tabel bertahan per email dan per IP. Lihat _pembatas.php. */
            require_once __DIR__ . '/_pembatas.php';
            $sisaKunci = pembatas_login_terkunci($pdo, $email);
            if ($sisaKunci > 0) {
                $error = pembatas_pesan($sisaKunci);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT u.id, u.nama, u.email, u.password, u.is_active, u.supplier_id, u.gudang_id, u.idle_menit,
                            r.nama AS role, r.label AS role_label
                     FROM users u JOIN roles r ON r.id = u.role_id
                     WHERE u.email = ? AND u.deleted_at IS NULL
                     LIMIT 1"
                );
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if (!$user || !password_verify($pass, $user['password'])) {
                    pembatas_login_gagal($pdo, $email, 'web');
                    $error = 'Email atau password salah.';
                } elseif ((int) $user['is_active'] !== 1) {
                    $error = 'Akun Anda nonaktif. Hubungi Super Admin.';
                } elseif ($user['role'] === 'supplier' && empty($user['supplier_id'])) {
                    $error = 'Akun supplier Anda belum dikaitkan ke data supplier. Hubungi admin gudang.';
                } else {
                    // ---------------------------------------------------
                    // Batas 3 perangkat. Diperiksa SETELAH password benar
                    // supaya pesannya tidak membocorkan email mana yang
                    // terdaftar kepada orang yang menebak-nebak.
                    // ---------------------------------------------------
                    [$bolehMasuk, $perangkatHidup] = boleh_tambah_perangkat($pdo, (int) $user['id']);
                    if (!$bolehMasuk) {
                        $daftar = [];
                        foreach ($perangkatHidup as $ph) {
                            $daftar[] = $ph['perangkat'] . ' (aktif ' . tgl_jam_id($ph['terakhir_at']) . ')';
                        }
                        /* Angka yang disebut adalah batas AKUN INI, bukan
                           konstanta global. Menyebut "3 perangkat" kepada
                           akun yang batasnya 1 membuat orang menghitung
                           sesinya sendiri, menemukan satu, lalu menyimpulkan
                           sistemnya keliru. */
                        $maksAkun = count($perangkatHidup);
                        $error = 'Akun ini sudah aktif di ' . $maksAkun . ' perangkat: '
                               . implode('; ', $daftar) . '. Keluar dari salah satunya lebih dulu, '
                               . 'atau minta Super Admin mengakhiri sesinya lewat Pengaturan › Perangkat.';
                        try {
                            catat_aktivitas($pdo, 'login', 'auth', (int) $user['id'],
                                'Login ditolak: sudah aktif di ' . $maksAkun . ' perangkat');
                        } catch (Throwable $ex) {
                            error_log('[AMANAH] catat tolak perangkat: ' . $ex->getMessage());
                        }
                    } else {
                        // Login sukses — amankan sesi.
                        session_regenerate_id(true);
                        unset($_SESSION['gagal']);

                        $_SESSION['user_id']       = $user['id'];
                        $_SESSION['role']          = $user['role'];
                        $_SESSION['last_activity'] = time();

                        /* Pilihan "Ingat saya" — menentukan umur COOKIE-nya,
                           bukan lama sesinya.

                           Dicentang   cookie berumur, tahan tutup peramban
                                       dan mati ulang komputer
                           Tidak       cookie peramban, hilang saat ditutup —
                                       yang benar untuk komputer bersama

                           Yang memutuskan sesi masih sah tetap
                           last_activity + idle_menit di server. Centang ini
                           tidak memperpanjang wewenang apa pun; ia hanya
                           menentukan apakah cookie-nya ikut mati saat
                           peramban ditutup.

                           Disetel SESUDAH session_regenerate_id(), karena
                           fungsi itu menerbitkan cookie baru dengan umur
                           bawaan — kalau dibalik, pilihannya tertimpa. */
                        $_SESSION['ingat'] = isset($_POST['ingat']);
                        segarkan_cookie_sesi();
                        pembatas_login_bersih($pdo, $email);
                        /* Batas menganggur akun ini, dibaca sekali di sini lalu
                           disegarkan tiap pemuatan halaman oleh penjaga sesi. */
                        $_SESSION['idle_menit'] = (int) ($user['idle_menit'] ?: IDLE_MENIT_BAWAAN);
                        $_SESSION['user'] = [
                            'id'          => $user['id'],
                            'nama'        => $user['nama'],
                            'email'       => $user['email'],
                            'role'        => $user['role'],
                            'role_label'  => $user['role_label'],
                            'supplier_id' => $user['supplier_id'],
                            'gudang_id'   => $user['gudang_id'],
                        ];

                        // Dicatat SETELAH session_regenerate_id(), karena
                        // sidiknya diambil dari ID sesi yang baru.
                        catat_perangkat($pdo, (int) $user['id']);

                        try {
                            $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
                            catat_aktivitas($pdo, 'login', 'auth', (int) $user['id'],
                                'Login berhasil · ' . perangkat_ringkas($_SERVER['HTTP_USER_AGENT'] ?? ''));
                        } catch (Throwable $ex) {
                            error_log('[AMANAH] login log gagal: ' . $ex->getMessage());
                        }

                        $kembali = login_kembali_aman($_POST['ke'] ?? '');
                        header('Location: ' . ($kembali !== '' ? $kembali : beranda_peran()));
                        exit;
                    }
                }
            }
        }
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk · <?= ident(APP_NAMA, APP_LEMBAGA) ?></title>
<link rel="stylesheet" href="assets/font.css">
<style>
  /* Halaman login berdiri sendiri (belum ada sesi), jadi tokennya ditulis
     ulang di sini — tapi nilainya PERSIS sama dengan assets/amanah.css
     supaya kesan pertama sudah sesuai dengan isi aplikasinya. */
  :root{
    --bg:#FAFAFA; --surface:#FFFFFF; --surface-3:#EFF2F9;
    --border:#E8E8EC; --border-kuat:#CBD3E2;
    --text:#0A0A0A; --muted:#9C9C9C; --muted-2:#B5B5B5;
    --primary:#6366F1; --primary-2:#4F46E5; --red:#EF4444; --red-t:#B01818;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:"DM Sans","Segoe UI",system-ui,-apple-system,Roboto,sans-serif;
    background:var(--bg);min-height:100vh;display:grid;place-items:center;padding:20px;
    color:var(--text);-webkit-font-smoothing:antialiased;position:relative;overflow-x:hidden}
  /* Satu cahaya indigo di belakang kartu — pengganti gradien penuh layar,
     supaya latar tetap terang dan kartunya yang jadi pusat perhatian. */
  body::before{content:"";position:absolute;top:-30vh;left:50%;transform:translateX(-50%);
    width:min(900px,140vw);height:70vh;pointer-events:none;
    background:radial-gradient(ellipse 50% 100% at 50% 0%,rgba(99,102,241,.22),transparent 70%)}
  .card{position:relative;background:var(--surface);border:1px solid var(--border);
    border-radius:12px;box-shadow:0 24px 60px -20px rgba(15,23,42,.14);
    width:100%;max-width:412px;padding:38px 32px;text-align:center}
  .logo{width:64px;height:64px;border-radius:14px;margin:0 auto 16px;display:grid;place-items:center;
    background:var(--primary);
    box-shadow:0 8px 26px -8px rgba(99,102,241,.9)}
  .logo svg{width:31px;height:31px;stroke:#fff;fill:none;stroke-width:1.9;
    stroke-linecap:round;stroke-linejoin:round}
  h1{font-family:"General Sans","Segoe UI",system-ui,sans-serif;font-size:25px;font-weight:700;letter-spacing:-.03em;margin-bottom:4px}
  .kepanjangan{color:var(--primary);font-size:11.5px;font-weight:650;letter-spacing:.02em;margin-bottom:4px}
  .sub{color:var(--muted);font-size:12.5px;margin-bottom:26px}
  .field{text-align:left;margin-bottom:14px}
  label{display:block;font-size:11.5px;font-weight:680;color:var(--muted);margin-bottom:7px;
    letter-spacing:.02em}
  input{width:100%;padding:12px 13px;border:1px solid var(--border-kuat);border-radius:10px;
    font-size:14px;outline:none;background:var(--surface-3);color:var(--text);font-family:inherit;
    transition:border-color .18s,box-shadow .18s}
  input::placeholder{color:var(--muted-2)}
  input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.12)}
  /* Centang "Ingat saya". label{display:block} di atas berlaku untuk
     SELURUH label di halaman ini, jadi baris ini harus mengembalikannya
     ke flex — kalau tidak, kotak centang dan teksnya bertumpuk. */
  label.ingat{display:flex;align-items:center;gap:9px;margin:2px 0 6px;
    font-size:13px;font-weight:500;color:var(--text);cursor:pointer;
    letter-spacing:normal;text-align:left}
  label.ingat input{width:auto;padding:0;margin:0;accent-color:var(--primary);
    width:16px;height:16px;flex:none;cursor:pointer}
  .ingat-ket{font-size:11.5px;color:var(--muted);text-align:left;
    line-height:1.5;margin:0 0 14px}
  .btn{width:100%;padding:12.5px;border:none;border-radius:6px;color:#fff;
    background:var(--primary);
    font-weight:650;font-size:15px;cursor:pointer;font-family:inherit;margin-top:6px;
    box-shadow:0 4px 16px -4px rgba(99,102,241,.5);transition:background .18s,transform .18s}
  .btn:hover{background:var(--primary-2);transform:translateY(-1px)}
  .btn:focus-visible{outline:2px solid var(--primary);outline-offset:3px}
  .err{background:rgba(239,68,68,.12);color:var(--red-t);border:1px solid rgba(239,68,68,.32);
    border-radius:10px;padding:11px 13px;font-size:12.5px;margin-bottom:17px;
    text-align:left;line-height:1.55}
  .foot{margin-top:24px;font-size:11px;color:var(--muted-2);line-height:1.7;
    border-top:1px solid var(--border);padding-top:17px}
  @media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>
  <form class="card" method="post" autocomplete="off">
    <div class="logo" aria-hidden="true"><svg viewBox="0 0 24 24"><path
      d="M21 8v8a2 2 0 0 1-1 1.73l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.73l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8z"/><path
      d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg></div>
    <h1><?= APP_NAMA ?></h1>
    <div class="kepanjangan"><?= APP_KEPANJANGAN ?></div>
    <div class="sub"><?= e(ident(APP_LEMBAGA, 'Silakan masuk')) ?></div>

    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>

    <div class="field">
      <label>Email</label>
      <input type="hidden" name="ke" value="<?= e(login_kembali_aman($_GET['ke'] ?? '')) ?>">
      <input type="email" name="email" placeholder="nama@contoh.com"
             value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" name="password" placeholder="••••••••" required>
    </div>
    <?php /* Pilihannya DIINGAT dari kiriman terakhir. Yang mencentangnya
             lalu salah ketik sandi tidak perlu mencentangnya lagi —
             kehilangan centang di percobaan kedua adalah cara halus
             membuat orang menyimpulkan fiturnya tidak bekerja. */ ?>
    <label class="ingat">
      <input type="checkbox" name="ingat" value="1"
             <?= isset($_POST['ingat']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>>
      <span>Ingat saya di perangkat ini</span>
    </label>
    <p class="ingat-ket">Jangan dicentang di komputer yang dipakai bergantian —
      tanpa centang, sesi berakhir begitu peramban ditutup.</p>

    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button class="btn" type="submit">Masuk</button>

    <div class="foot">
      <?= APP_TAGLINE ?><br>
      <?= APP_NAMA ?> v<?= APP_VERSI ?> · Lupa password? Minta Super Admin membuat tautan reset dari Kelola Pengguna.
    </div>
  </form>
</body>
</html>
