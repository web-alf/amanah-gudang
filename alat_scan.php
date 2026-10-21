<?php
/**
 * AMANAH — Alat Uji Scanner
 *
 * Halaman ini tidak menyimpan apa pun. Gunanya satu: memastikan sebuah
 * scanner — USB, Bluetooth, atau nirkabel ber-dongle — benar-benar terbaca
 * sistem, dan menunjukkan APA yang dikirimnya.
 *
 * Kenapa perlu ada. Semua scanner menyamar sebagai keyboard, tapi tiap
 * merek punya setelan pabrik yang berbeda: ada yang menutup kodenya dengan
 * Enter, ada yang dengan Tab, ada yang tidak menutup sama sekali, dan ada
 * yang menambahkan awalan. Kalau sebuah scanner "tidak jalan", penyebabnya
 * hampir selalu salah satu dari itu — bukan aplikasinya. Tanpa alat ini,
 * satu-satunya cara mengetahuinya adalah menebak.
 *
 * Semua pemeriksaan berjalan di browser. Tidak ada yang dikirim ke server.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

$PAGE_TITLE = 'Alat Uji Scanner';
$ACTIVE     = 'ceksurat';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div>
    <h1>Alat Uji Scanner</h1>
    <p>Pastikan scanner terbaca, dan lihat apa yang dikirimnya — tanpa menyimpan data apa pun</p>
  </div>
</div>

<div class="card">
  <h3>Tembak barcode apa saja ke sini</h3>
  <div class="sub">Tidak perlu mengklik kotak mana pun. Arahkan scanner ke barcode apa pun lalu tembak.</div>

  <div id="panggung" class="uji-panggung">
    <div class="uji-nunggu" id="nunggu">
      <svg aria-hidden="true"><use href="#i-scan"></use></svg>
      <b>Menunggu tembakan…</b>
      <span>Kalau tidak ada reaksi sama sekali, scanner belum terpasang sebagai keyboard.
        Periksa langkah di bawah.</span>
    </div>
    <div id="hasil" class="uji-hasil" hidden></div>
  </div>

  <div class="baris" style="margin-top:14px">
    <button type="button" class="btn btn-ghost" id="ulang">Bersihkan</button>
    <span class="mut" style="font-size:12.5px">Halaman ini tidak menyimpan apa pun ke basis data.</span>
  </div>
</div>

<div class="card">
  <h3>Kalau scanner tidak terbaca</h3>
  <ol class="uji-langkah">
    <li><b>Pasangkan dulu sebagai keyboard.</b> Di setelan Bluetooth komputer atau HP,
      pasangkan scanner seperti memasangkan keyboard. Di buku manualnya mode ini biasa
      disebut <i>HID</i> atau <i>Keyboard mode</i>. Kalau scanner berada di mode
      <i>SPP</i> / <i>serial</i>, ia tidak akan pernah terbaca browser — ubah modenya
      dengan memindai barcode setelan di buku manual.</li>
    <li><b>Uji di luar aplikasi.</b> Buka Notepad atau kotak pencarian apa pun, lalu tembak.
      Kalau kodenya tidak muncul di sana juga, masalahnya ada di pemasangan perangkat,
      bukan di aplikasi ini.</li>
    <li><b>Cek baterainya.</b> Scanner Bluetooth yang baterainya menipis mengirim
      karakter lebih lambat dan tersendat. Aplikasi ini sudah memberi kelonggaran,
      tapi baterai yang hampir habis tetap bisa membuatnya terbaca seperti ketikan tangan.</li>
    <li><b>Layar terkunci atau berpindah halaman?</b> Tembakan hanya masuk ke halaman yang
      sedang terbuka. Pastikan jendela aplikasi yang sedang aktif.</li>
  </ol>
</div>

<style>
.uji-panggung{border:1px dashed var(--border-kuat);border-radius:var(--r-md);
  background:var(--surface-3);padding:22px;min-height:190px;display:flex;
  align-items:center;justify-content:center;text-align:center}
.uji-nunggu{color:var(--muted);display:grid;gap:7px;justify-items:center}
.uji-nunggu svg{width:34px;height:34px;opacity:.55}
.uji-nunggu b{color:var(--text-2);font-size:15px}
.uji-nunggu span{font-size:12.5px;max-width:430px;line-height:1.65}
.uji-hasil{width:100%;text-align:left}
.uji-kode{font-family:ui-monospace,Consolas,monospace;font-size:21px;font-weight:700;
  color:var(--text);word-break:break-all;background:var(--surface-2);
  border:1px solid var(--border);border-radius:var(--r-sm);padding:12px 14px;margin-bottom:14px}
.uji-fakta{display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:11px}
.uji-fakta div{background:var(--surface-2);border:1px solid var(--border);
  border-radius:var(--r-sm);padding:10px 12px}
.uji-fakta dt{font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;
  color:var(--muted);font-weight:700;margin-bottom:4px}
.uji-fakta dd{font-size:14.5px;font-weight:600;color:var(--text)}
.uji-langkah{margin:6px 0 0 18px;line-height:1.85;color:var(--text-2);font-size:13.5px}
.uji-langkah li{margin-bottom:9px}
.uji-langkah b{color:var(--text)}
</style>

<script>
/* Penangkap sendiri, TIDAK memakai penangkap global di scan.js.
   Halaman ini justru harus melaporkan tembakan apa adanya — termasuk yang
   terlalu lambat sehingga di halaman lain akan ditolak sebagai ketikan
   tangan. Kalau ia memakai penyaring yang sama, scanner bermasalah tidak
   akan pernah muncul di sini, padahal itulah yang perlu dilihat. */
(function () {
  var buf = [], t0 = 0, tPrev = 0, tunggu = null;
  var nunggu = document.getElementById('nunggu');
  var hasil  = document.getElementById('hasil');

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function selesai(penutup) {
    if (tunggu) { clearTimeout(tunggu); tunggu = null; }
    if (!buf.length) { return; }

    var kode  = buf.map(function (b) { return b.k; }).join('');
    var jeda  = buf.slice(1).map(function (b) { return b.j; });
    var total = tPrev - t0;
    var rata  = jeda.length ? Math.round(jeda.reduce(function (a, b) { return a + b; }, 0) / jeda.length) : 0;
    var maks  = jeda.length ? Math.max.apply(null, jeda) : 0;

    /* Ambangnya disamakan dengan scan.js (90 ms). Yang dilaporkan bukan
       sekadar "terbaca", melainkan apakah halaman LAIN akan menerimanya.

       Yang dijaga adalah JUMLAH SAMPEL, bukan "rata-rata harus di atas nol".
       Rata-rata 0 ms berarti rentetannya lebih cepat daripada resolusi jam
       — itu justru bukti paling kuat bahwa pengirimnya mesin, bukan tangan.
       Syarat rata > 0 sempat membuat scanner tercepat dilaporkan sebagai
       ketikan tangan. */
    var mesin = jeda.length >= 2 && rata < 90 && kode.length >= 3;

    hasil.innerHTML =
      '<div class="uji-kode">' + esc(kode) + '</div>' +
      '<div class="uji-fakta">' +
        '<div><dt>Panjang kode</dt><dd>' + kode.length + ' karakter</dd></div>' +
        '<div><dt>Rata-rata jeda</dt><dd>' + rata + ' ms</dd></div>' +
        '<div><dt>Jeda terlama</dt><dd>' + maks + ' ms</dd></div>' +
        '<div><dt>Total waktu</dt><dd>' + total + ' ms</dd></div>' +
        '<div><dt>Akhiran</dt><dd>' + penutup + '</dd></div>' +
        '<div><dt>Terbaca sebagai</dt><dd style="color:' +
            (mesin ? 'var(--green-t)' : 'var(--amber)') + '">' +
            (mesin ? 'Scanner ✓' : 'Ketikan tangan') + '</dd></div>' +
      '</div>' +
      (mesin
        ? '<p class="petunjuk" style="margin-top:12px">Scanner ini bekerja. Di halaman Barang Masuk, ' +
          'Barang Keluar, Penerimaan, dan Surat Jalan, tembakannya akan langsung masuk daftar.</p>'
        : '<p class="petunjuk" style="margin-top:12px">Jedanya terlalu panjang untuk dikenali sebagai ' +
          'mesin. Kalau ini memang hasil tembakan scanner dan bukan ketikan tangan, ' +
          'periksa baterainya — sambungan Bluetooth yang lemah memperlambat pengiriman.</p>');

    nunggu.hidden = true;
    hasil.hidden  = false;
    buf = [];
  }

  document.addEventListener('keydown', function (ev) {
    if (ev.ctrlKey || ev.altKey || ev.metaKey) { return; }
    var a = document.activeElement, tag = (a && a.tagName || '').toUpperCase();
    if (tag === 'INPUT' || tag === 'TEXTAREA' || (a && a.isContentEditable)) { return; }

    var now = Date.now();

    if (ev.key === 'Enter' || ev.key === 'Tab') {
      if (buf.length) { ev.preventDefault(); selesai(ev.key === 'Tab' ? 'Tab' : 'Enter (CR)'); }
      return;
    }
    if (ev.key.length !== 1) { return; }

    ev.preventDefault();
    if (!buf.length) { t0 = now; }
    buf.push({ k: ev.key, j: buf.length ? now - tPrev : 0 });
    tPrev = now;

    // Scanner tanpa akhiran: dilaporkan setelah rentetannya berhenti.
    if (tunggu) { clearTimeout(tunggu); }
    tunggu = setTimeout(function () { selesai('tidak ada'); }, 400);
  });

  document.getElementById('ulang').addEventListener('click', function () {
    buf = []; hasil.hidden = true; nunggu.hidden = false;
  });
}());
</script>

<?php require __DIR__ . '/_footer.php'; ?>
