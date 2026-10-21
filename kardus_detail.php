<?php
/**
 * AMANAH — Detail Kardus
 *
 * Dibuka lewat id, atau lewat kode (?k=KRD-…) dari pindaian label QR —
 * alamat yang stabil: kode kardus tidak berubah walau isi dokumennya
 * disunting. Menampilkan identitas, perpindahan yang membawanya, lokasi
 * terakhir, isi (dengan jumlah diterima bila sudah), penerimaan, riwayat.
 * Tidak ada tindakan yang mengubah stok di sini; pindaian pun tidak
 * menandai kardus diterima — penerimaan lewat menu Penerimaan.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();

if (!kardus_aktif($pdo)) {
    set_flash('err', 'Fitur kardus belum aktif: migrasi basis data belum dijalankan.'); header('Location: kardus.php'); exit;
}
$id   = (int) ($_GET['id'] ?? 0);
$kode = strtoupper(trim((string) ($_GET['k'] ?? '')));
if ($id <= 0 && $kode !== '') { $id = (int) kardus_cari_kode($pdo, $kode); }
$k = $id > 0 ? kardus_ambil($pdo, $id) : null;
if (!$k) {
    set_flash('err', $kode !== '' ? "Kardus dengan kode \"$kode\" tidak ditemukan." : 'Kardus tidak ditemukan.');
    header('Location: ' . (is_toko() ? 'penerimaan.php' : 'kardus.php')); exit;
}
if (!kardus_boleh_lihat($pdo, (int) $k['gudang_id'], $k['perpindahan_id'] ? (int) $k['perpindahan_id'] : null)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Kardus ini bukan untuk lokasi yang Anda pegang. <a href="' . beranda_peran() . '">Kembali</a></p>');
}

$isi = kardus_isi($pdo, $id); $riwayat = kardus_riwayat($pdo, $id); $ring = kardus_ringkas($isi);
[$sLbl, $sCls] = label_status_kardus($k['status']); [$kLbl, $kCls] = label_kondisi_kardus($k['kondisi']);
$ppId   = $k['perpindahan_id'] ? (int) $k['perpindahan_id'] : 0;
$asal   = $ppId ? nama_lokasi($pdo, $k['asal_tipe'], $k['asal_id'] ? (int) $k['asal_id'] : null, $k['asal_teks']) : ($k['gudang_nama'] ?: '—');
$tujuan = $ppId ? nama_lokasi($pdo, $k['tujuan_tipe'], $k['tujuan_id'] ? (int) $k['tujuan_id'] : null, $k['tujuan_teks']) : '—';
$urut = null; $jmlKardus = 0;
if ($ppId) { foreach (kardus_di_dokumen($pdo, $ppId) as $i => $kid) { $jmlKardus++; if ($kid === $id) { $urut = $i + 1; } } }
$dimensi = ($k['panjang_cm'] || $k['lebar_cm'] || $k['tinggi_cm'])
    ? rtrim(rtrim((string) $k['panjang_cm'], '0'), '.') . ' × ' . rtrim(rtrim((string) $k['lebar_cm'], '0'), '.') . ' × ' . rtrim(rtrim((string) $k['tinggi_cm'], '0'), '.') . ' cm' : '';
$sudahTerima = $k['status'] === 'diterima';
$AKSI_LABEL = ['buat' => 'Dibuat', 'berangkat' => 'Berangkat', 'terima' => 'Diterima', 'batal' => 'Dibatalkan', 'cetak_label' => 'Label dicetak',
               'tutup' => 'Ditutup', 'siap' => 'Siap dikirim', 'buka' => 'Dibuka kembali', 'kirim' => 'Masuk surat jalan', 'lepas_sj' => 'Dikeluarkan dari surat jalan', 'pindah' => 'Pindah lokasi', 'ubah_isi' => 'Isi diubah'];
[$ppLbl, $ppCls] = $ppId ? label_status((string) $k['pp_status']) : ['—', 't-gray'];

$PAGE_TITLE = 'Kardus ' . $k['kode']; $ACTIVE = 'kardus';
require __DIR__ . '/_header.php';
?>
<div class="jejak"><a href="kardus.php">Folder Kardus</a> <span>›</span> <b><?= e($k['kode']) ?></b></div>
<div class="page-head">
  <div><h1 style="font-family:'Courier New',monospace;letter-spacing:1px"><?= e($k['kode']) ?></h1>
    <p><?= $k['nama'] ? e($k['nama']) . ' · ' : '' ?><?= $urut ? "Kardus $urut dari $jmlKardus · " : '' ?>dikemas <?= e(tgl_id($k['tanggal_kemas'])) ?><?= $k['petugas_nama'] ? ' oleh ' . e($k['petugas_nama']) : '' ?></p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($k['status'] !== 'dibatalkan'): ?>
      <a class="btn btn-ghost" href="kardus_label.php?id=<?= (int) $id ?>" target="_blank" rel="noopener"><svg class="ikon" aria-hidden="true"><use href="#i-cetak"></use></svg> Cetak label</a>
    <?php endif; ?>
    <?php if ($ppId && $k['no_surat_jalan']): ?>
      <a class="btn btn-ghost" href="surat_jalan.php?id=<?= $ppId ?>" target="_blank" rel="noopener"><svg class="ikon" aria-hidden="true"><use href="#i-doc"></use></svg> Surat jalan</a>
    <?php endif; ?>
    <?php if ($ppId && $k['pp_status'] === 'dikirim'): ?>
      <a class="btn btn-primary" href="penerimaan.php?id=<?= $ppId ?>"><svg class="ikon" aria-hidden="true"><use href="#i-cek-bulat"></use></svg> Penerimaan</a>
    <?php endif; ?>
    <a class="btn btn-ghost" href="<?= is_toko() ? 'penerimaan.php' : 'kardus.php' ?>">← <?= is_toko() ? 'Penerimaan' : 'Folder' ?></a>
  </div>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
    <div><span class="tag <?= e($sCls) ?>"><?= e($sLbl) ?></span> <span class="tag <?= e($kCls) ?>">Kondisi: <?= e($kLbl) ?></span></div>
    <div style="text-align:right"><div class="mut-sm">Lokasi terakhir</div><div style="font-size:18px;font-weight:700">📍 <?= e(kardus_posisi($pdo, $k)) ?></div></div>
  </div>
  <div class="form-grid" style="margin-top:14px;font-size:13.5px">
    <div><span class="mut2">Nomor perpindahan</span><br><b><?= $ppId ? '<a href="persetujuan.php?id=' . $ppId . '">' . e($k['no_perpindahan']) . '</a>' : '—' ?></b> <span class="tag <?= e($ppCls) ?>" style="font-size:10.5px;padding:1px 7px"><?= e($ppLbl) ?></span></div>
    <div><span class="mut2">Nomor surat jalan</span><br><b><?= $k['no_surat_jalan'] ? e($k['no_surat_jalan']) : '— <small class="mut2">belum diterbitkan (menunggu persetujuan)</small>' ?></b></div>
    <div><span class="mut2">Asal</span><br><b><?= e($asal) ?></b></div>
    <div><span class="mut2">Tujuan</span><br><b><?= e($tujuan) ?></b><?= $k['penerima'] ? '<br><small class="mut2">penerima: ' . e($k['penerima']) . '</small>' : '' ?></div>
    <div><span class="mut2">Resi ekspedisi</span><br><b><?= ($k['pp_resi'] && $k['pp_resi'] !== $k['no_perpindahan']) ? e($k['pp_resi']) : '—' ?></b><?= $k['ekspedisi'] ? ' · ' . e($k['ekspedisi']) : '' ?><br><small class="mut2">kode kardus = identitas internal; resi = nomor dari ekspedisi</small></div>
    <div><span class="mut2">Angkutan</span><br><b><?= e(teks_angkutan($k['kendaraan'], $k['no_polisi'], $k['pengirim']) ?: '—') ?></b></div>
    <?php if ($k['berat_kg'] || $dimensi): ?><div><span class="mut2">Berat · dimensi</span><br><b><?= $k['berat_kg'] ? e(rtrim(rtrim((string) $k['berat_kg'], '0'), '.')) . ' kg' : '—' ?></b><?= $dimensi ? ' · ' . e($dimensi) : '' ?></div><?php endif; ?>
    <?php if ($k['catatan']): ?><div class="span-all"><span class="mut2">Catatan kardus</span><br><?= e($k['catatan']) ?></div><?php endif; ?>
    <?php if ($sudahTerima): ?>
      <div class="span-all"><span class="mut2">Penerimaan</span><br><b><?= e(tgl_jam_id($k['diterima_at'])) ?></b><?= $k['penerima_nama'] ? ' · ' . e($k['penerima_nama']) : '' ?><?= $k['catatan_terima'] ? '<br>' . e($k['catatan_terima']) : '' ?>
        <?php if ($k['foto_terima']): ?><br><a href="<?= e(url_unggahan($k['foto_terima'])) ?>" target="_blank" rel="noopener">Foto penerimaan</a><?php endif; ?></div>
    <?php endif; ?>
    <?php if ($k['status'] === 'dibatalkan' && $k['alasan_batal']): ?><div class="span-all"><span class="mut2">Alasan pembatalan</span><br><?= e($k['alasan_batal']) ?></div><?php endif; ?>
  </div>
  <?php if ($ppId && in_array($k['pp_status'], ['dikirim', 'selesai', 'diterima'], true)): ?>
    <div style="margin-top:12px"><a class="btn btn-ghost btn-sm" href="lacak.php?id=<?= $ppId ?>">🚚 Lacak kiriman</a></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Isi kardus</h3>
  <div class="sub"><?= $ring['jenis'] ?> jenis · <?= e(kardus_teks_satuan($ring['per_satuan'])) ?></div>
  <?php if (!$isi): ?><div class="kosong-blok"><p>Kardus ini tidak berisi apa pun.</p></div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Barang</th><th>Batch · expired</th><th class="num">Jumlah</th><?php if ($sudahTerima): ?><th class="num">Diterima</th><?php endif; ?><th>Keterangan</th></tr></thead>
    <tbody>
    <?php foreach ($isi as $r): $selisih = $sudahTerima && $r['qty_diterima'] !== null && (int) $r['qty_diterima'] !== (int) $r['qty']; ?>
      <tr>
        <td data-label="Barang"><b><?= e($r['nama_barang']) ?></b><br><small class="mut2"><?= e($r['kode_barang']) ?><?= $r['merek'] ? ' · ' . e($r['merek']) : '' ?></small></td>
        <td class="sekunder" data-label="Batch"><?= e($r['nomor_batch'] ?: '—') ?><?= $r['tanggal_expired'] ? '<br><small class="mut2">exp ' . e(tgl_id($r['tanggal_expired'])) . '</small>' : '' ?></td>
        <td class="num" data-label="Jumlah"><b><?= angka($r['qty']) ?></b> <?= e($r['satuan']) ?></td>
        <?php if ($sudahTerima): ?><td class="num" data-label="Diterima" <?= $selisih ? 'style="color:var(--red)"' : '' ?>><b><?= $r['qty_diterima'] !== null ? angka($r['qty_diterima']) : '—' ?></b><?= $selisih && $r['selisih_catatan'] ? '<br><small>' . e($r['selisih_catatan']) . '</small>' : '' ?></td><?php endif; ?>
        <td class="sekunder" data-label="Keterangan"><?= e($r['catatan'] ?: '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Riwayat</h3>
  <div class="sub">Setiap perubahan tercatat beserta pelakunya</div>
  <ol class="lini">
    <?php foreach ($riwayat as $r): ?>
      <li class="lini-selesai"><div class="lini-titik"></div>
        <div class="lini-isi"><b><?= e($AKSI_LABEL[$r['aksi']] ?? ucfirst($r['aksi'])) ?></b>
          <div class="mut-sm"><?= e(tgl_jam_id($r['created_at'])) ?><?= $r['oleh'] ? ' · ' . e($r['oleh']) : '' ?></div>
          <?php if ($r['keterangan']): ?><div style="font-size:13px"><?= e($r['keterangan']) ?></div><?php endif; ?></div></li>
    <?php endforeach; ?>
  </ol>
</div>
<style>
.lini{list-style:none;margin:0;padding:0 0 0 6px;border-left:2px solid var(--border)}
.lini li{position:relative;padding:0 0 16px 22px}.lini li:last-child{padding-bottom:4px}
.lini-titik{position:absolute;left:-8px;top:3px;width:14px;height:14px;border-radius:50%;background:var(--green);border:2px solid var(--green)}
</style>
<?php require __DIR__ . '/_footer.php'; ?>
