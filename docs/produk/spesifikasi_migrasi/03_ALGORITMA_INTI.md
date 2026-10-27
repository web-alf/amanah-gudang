# 03 — Algoritma Inti (wajib identik)

Semua algoritma di sini **menentukan angka stok**. Implementasi baru harus menghasilkan hasil DB identik untuk input identik. Pesan galat ditulis verbatim (bahasa Indonesia) karena dipakai pengguna & diuji di `08_KASUS_UJI.md`.

Notasi: pseudocode + SQL asli. `?` = parameter terikat. Semua fungsi menerima koneksi yang **sudah berada dalam transaksi** kecuali disebut lain.

Sumber lama: `_lib.php` (baris disebut per fungsi), `_stok_pesan.php`, `_kardus.php`, `_lacak.php`, `_perpindahan_ubah.php`.

---

## 0. Urutan kunci (lock order) — WAJIB

```
1. stok (per barang_id, urut ASC)       ← kunci_stok()
2. perpindahan / penyesuaian_stok / pelepasan_stok (dokumen, FOR UPDATE)
3. batch_barang (FOR UPDATE, via FEFO / by id)
4. dokumen_counter
```

Pelanggaran urutan = deadlock (gejala lapangan: halaman "freeze" sampai `innodb_lock_wait_timeout`). Kecuali: `sahkan_perpindahan`, `catat_transit`, `ubah_penyesuaian` membaca dokumen dulu lalu `kunci_stok` — diterima karena `kunci_stok` dipanggil sebelum menyentuh `batch_barang`.

---

## 1. Konstanta & validator nilai

```
MAKS_QTY_BARIS   = 1_000_000
EXP_PALING_TUA   = '2000-01-01'
EXP_TAHUN_DEPAN  = 50
MAKS_TITIK_TRANSIT = 3

KONDISI_BARANG (kunci → [label, warna, ikon, layak_salur, ditawarkan]):
  baru              → ['Baru',               true,  true]
  bekas_layak       → ['Layak',              true,  true]
  rusak             → ['Rusak/Bocor',        false, true]
  expired           → ['Expired',            false, true]
  bekas_tidak_layak → ['Tidak layak (lama)', false, false]   # legacy, tetap sah

SUMBER_BARANG: pembelian | donasi
```

```
kondisi_sah(k)      = k if k in KONDISI_BARANG else 'baru'
sumber_sah(s)       = s if s in SUMBER_BARANG  else 'pembelian'
kondisi_layak_salur() = ['baru','bekas_layak']
kondisi_tidak_layak_sql() = "'rusak','expired','bekas_tidak_layak'"
jenis_hanya_layak(j) = j in ['penyaluran','kirim_toko','antar_toko']

periksa_qty_wajar(qty, apa='Jumlah'):
  if qty > MAKS_QTY_BARIS: throw
    "{apa} {format_id(qty)} tidak wajar (batasnya 1.000.000 per baris). Biasanya ini barcode yang tertembak ke kotak Jumlah — periksa lagi isiannya."
  # format_id = pemisah ribuan titik

tanggal_expired_sah(tgl) -> ?Y-m-d:        # TIDAK melempar; invalid → NULL
  tgl = trim(tgl); if '' → null
  tgl = tgl[0:10]
  parse strict 'Y-m-d' (31 Feb ditolak; hasil format ulang harus == tgl) else null
  if tgl < EXP_PALING_TUA → null
  if tgl > today + EXP_TAHUN_DEPAN tahun → null
  return tgl

tanggal_dokumen_sah(masuk) -> {tgl, simpan, galat:null, geser}:
  default: tgl=today, simpan=now('Y-m-d H:i:s'), geser=false
  if masuk=='' → default
  parse strict '!Y-m-d' else default (TIDAK ditolak, hanya diabaikan)
  tgl = parsed; geser = tgl != today
  simpan = geser ? tgl+' '+now('H:i:s') : now()
  # galat selalu null — tanggal masa depan/lampau diterima.
```

---

## 2. Nomor dokumen — `nomor_dokumen(tabel, kolom, prefix)` (`_lib.php:3766`)

Format: **`PREFIX-YYYYMMDD-NNNN`** (4 digit, reset harian, zero-pad; boleh >9999 → 5 digit natural).

| Prefix | Tabel.kolom |
|---|---|
| TM | transaksi_masuk.no_transaksi |
| TK | transaksi_keluar.no_transaksi |
| PP | perpindahan.no_perpindahan |
| SJ | perpindahan.no_surat_jalan |
| DN | donasi.no_donasi |
| PL | pelepasan_stok.no_pelepasan |
| PS | penyesuaian_stok.no_penyesuaian |
| KRD | kardus.kode |

```
awal   = prefix + '-' + today('Ymd') + '-'
kunci  = tabel + '.' + kolom + '.' + awal
posisi = len(awal) + 1
try:
  -- 1. seed counter dari data yang sudah ada (idempoten)
  INSERT IGNORE INTO dokumen_counter (kunci, urut)
    SELECT ?, COALESCE(MAX(CAST(SUBSTRING({kolom}, {posisi}) AS UNSIGNED)), 0)
    FROM {tabel} WHERE {kolom} LIKE ?            -- [kunci, awal+'%']
  -- 2. increment atomik
  UPDATE dokumen_counter SET urut = LAST_INSERT_ID(urut + 1) WHERE kunci = ?
  if rowCount > 0: urut = SELECT LAST_INSERT_ID()
catch PDOException: urut = 0
if urut <= 0:   -- fallback non-atomik
  urut = SELECT COALESCE(MAX(CAST(SUBSTRING({kolom},{posisi}) AS UNSIGNED)),0)+1 FROM {tabel} WHERE {kolom} LIKE ?
return awal + str_pad(urut, 4, '0', LEFT)
```

Non-MySQL: ganti `LAST_INSERT_ID` dengan `UPDATE … RETURNING urut` atau sequence per kunci. Kontrak: **monoton, unik dalam tx concurrent, tidak melompat besar**.

### `dengan_nomor_dokumen(tabel, kolom, prefix, fn(no), percobaan=5)` (`:3826`)

```
for i in 1..percobaan:
  no = nomor_dokumen(...)
  try: return fn(no)            # fn membuka+commit transaksinya sendiri
  catch PDOException e:
    rollback jika masih dalam tx
    bentrokNo = e.code=='23000' && no in e.message
    deadlock  = e.code in ['40001','HY000'] && ('Deadlock' in msg || 'Lock wait timeout' in msg)
    if !(bentrokNo || deadlock): rethrow
    sleep random 10–60 ms
throw RuntimeException('Gagal membuat nomor dokumen unik setelah beberapa percobaan.')
```

### `kode_berikut(tabel, awalan, lebar=3)` (`:3716`) — kode master (RAK-, BRG-, DN- donatur, dst.)

```
SELECT kode FROM {tabel} WHERE kode LIKE ? AND kode REGEXP ?
 ORDER BY LENGTH(kode) DESC, kode DESC LIMIT 1       -- [awalan+'-%', '^'+awalan+'-[^0-9]*[0-9]{1,8}$']
ambil ekor angka → +1, pad ke max(lebar, len(ekor lama)); jika tak ada → awalan+'-'+pad(1)
```

---

## 3. Kunci & agregat stok

### `kunci_stok(barangIds)` (`:3885`) — **selalu pertama dalam tx**
```
ids = unique(int(ids)) sorted ASC
for id in ids:
  r = SELECT qty_total FROM stok WHERE barang_id = ? FOR UPDATE
  if r is false:
     INSERT IGNORE INTO stok (barang_id, qty_total) VALUES (?, 0)
     SELECT qty_total FROM stok WHERE barang_id = ? FOR UPDATE
```

### `recalc_stok(barangId)` (`:3913`) — stok.qty_total = stok GUDANG saja (toko tidak dihitung)
```sql
INSERT INTO stok (barang_id, qty_total)
VALUES (:b, (SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang WHERE barang_id = :b AND toko_id IS NULL))
ON DUPLICATE KEY UPDATE qty_total = (SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang WHERE barang_id = :b AND toko_id IS NULL)
```
Dipanggil setelah SETIAP mutasi batch, per barang unik.

### Agregat baca (semua `+ sql_tanpa_kardus` = ` AND bb.kardus_id IS NULL`)
| Fungsi | WHERE |
|---|---|
| `stok_tersedia(b, rak?)` | `barang_id=? AND qty_tersisa>0 [AND lokasi_rak_id=?]` — **tanpa rak = SEMUA lokasi termasuk toko** |
| `stok_di_toko(b, toko)` | `barang_id=? AND toko_id=? AND qty_tersisa>0` |
| `stok_di_gudang(b, g)` | `JOIN lokasi_rak lr ON lr.id=bb.lokasi_rak_id WHERE lr.gudang_id=? AND lr.deleted_at IS NULL AND qty_tersisa>0` |
| `stok_layak_salur(b, g?)` | `+ kondisi IN (layak)`; g → rak subquery; else `toko_id IS NULL` |
| `stok_layak_salur_lokasi(b, rak?, g?, toko?)` | prioritas toko → gudang → rak → `toko_id IS NULL`; `+ kondisi IN (layak)` |

---

## 4. FEFO

### `fefo_sql(kolom, b, rak?, gudang?, toko?, hanyaLayak, batchPilih?, tanpaKardus=true)` (`:4021`)
```
SELECT {kolom} FROM batch_barang bb WHERE bb.barang_id = ? AND bb.qty_tersisa > 0
  + prioritas lokasi (hanya SATU):
      toko   → AND bb.toko_id = ?
      gudang → AND bb.lokasi_rak_id IN (SELECT lr.id FROM lokasi_rak lr WHERE lr.gudang_id = ? AND lr.deleted_at IS NULL)
      rak    → AND bb.lokasi_rak_id = ?
      none   → AND bb.toko_id IS NULL
  + tanpaKardus → AND bb.kardus_id IS NULL
  + hanyaLayak  → AND bb.kondisi IN ('baru','bekas_layak')
ORDER BY
  [batchPilih != '' && ',' not in batchPilih → (bb.nomor_batch = ?) DESC,]
  (bb.tanggal_expired IS NULL), bb.tanggal_expired ASC, bb.id ASC
```
Catatan: nilai berkoma (hasil `catat_batch_pratinjau`) **bukan** pilihan — diabaikan.

### `ambil_fefo(b, qty, rak?, gudang?, toko?, hanyaLayak=false, batchPilih?)` (`:4095`)
```
rows = fefo_sql('bb.id AS batch_id, bb.nomor_batch, bb.kondisi, bb.sumber, bb.donatur_id, bb.tanggal_expired,
                 bb.lokasi_rak_id, bb.toko_id, bb.harga_beli, bb.supplier_id, bb.qty_tersisa', …) + ' FOR UPDATE'
sisa = qty; hasil = []; tersedia = sum(qty_tersisa)
for r in rows while sisa > 0:
  ambil = min(sisa, r.qty_tersisa); hasil.push(r + {ambil}); sisa -= ambil
if sisa > 0: throw hanyaLayak
  ? "Stok layak salur tidak mencukupi. Diminta {qty} unit, yang layak disalurkan hanya {tersedia} unit. Sisanya berkondisi bekas tidak layak dan harus lewat Perbaikan & Pelepasan lebih dulu."
  : "Stok tidak mencukupi. Diminta {qty} unit, tersedia hanya {tersedia} unit."
return hasil     # TIDAK mengubah qty — pemanggil: UPDATE batch_barang SET qty_tersisa = qty_tersisa - ? WHERE id = ?
```

### `ambil_fefo_supplier(b, qty, supplierId, batchPilih?)` (`:4163`)
Sama, dari `stok_supplier (id, nomor_batch, kondisi, sumber, donatur_id, tanggal_expired, qty, harga AS harga_beli, lokasi_rak_id)` `WHERE barang_id=? AND supplier_id=? AND qty>0` + ORDER sama + FOR UPDATE. Galat: `"Stok mitra tidak mencukupi. Diminta {qty} unit, tersedia hanya {tersedia} unit."`. Potong: `UPDATE stok_supplier SET qty = qty - ? WHERE id = ?`; setelahnya `DELETE FROM stok_supplier WHERE id = ? AND qty <= 0` untuk tiap baris tersentuh.

### `pratinjau_fefo(...)` (`:4326`) — sama tanpa FOR UPDATE, tanpa throw (kembalikan yang ada).

### `catat_batch_pratinjau(pp, detail)` (`:4369`)
Untuk tiap detail dengan `nomor_batch` kosong: `pilih = pratinjau_fefo(barang, qty, rakAsal, gudangAsal, tokoAsal, jenis_hanya_layak(jenis))`; `nomor = substr(join(', ', nomor_batch…), 0, 50)`; `exp = MIN(tanggal_expired non-null)`; `UPDATE perpindahan_detail SET nomor_batch=?, tanggal_expired=? WHERE id=? AND (nomor_batch IS NULL OR nomor_batch='')`.

---

## 5. Tambah stok — `tambah_ke_lokasi(...)` (`:4229`)

```
tambah_ke_lokasi(b, rak?, toko?, nomorBatch, expired, qty, hargaBeli=0, supplier?, kondisi='baru', sumber='pembelian', donatur?, kardus?=null) -> batch_id
  periksa_qty_wajar(qty)
  kondisi = kondisi_sah(kondisi); sumber = sumber_sah(sumber)
  expired = tanggal_expired_sah(expired)                      # invalid → NULL, tidak galat
  if donatur != null: sumber = 'donasi'                       # dipaksa
  if rak==null && toko==null: throw 'Lokasi tujuan tidak dapat ditentukan. Pastikan gudang tujuan sudah memiliki minimal satu rak, atau pilih rak / toko tujuan secara langsung.'
  if rak!=null && toko!=null: throw 'Satu batch tidak boleh berada di rak dan toko sekaligus.'
  id = SELECT id FROM batch_barang WHERE barang_id=? AND nomor_batch=? AND kondisi=? AND sumber=?
         AND donatur_id <=> ? AND lokasi_rak_id <=> ? AND toko_id <=> ? AND tanggal_expired <=> ? AND kardus_id <=> ? LIMIT 1
  if id:
    UPDATE batch_barang SET qty_awal = qty_awal + ?, qty_tersisa = qty_tersisa + ?,
       harga_beli = IF(harga_beli = 0, ?, harga_beli), supplier_id = COALESCE(supplier_id, ?) WHERE id = ?
  else:
    INSERT INTO batch_barang (barang_id, nomor_batch, tanggal_expired, tanggal_masuk, qty_awal, qty_tersisa, harga_beli,
       supplier_id, lokasi_rak_id, toko_id, kondisi, sumber, donatur_id, kardus_id)
    VALUES (?,?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?)
  return id
tambah_ke_rak(...) = tambah_ke_lokasi(toko=null)
```
`<=>` = NULL-safe equal. Pemanggil wajib `recalc_stok`.

---

## 6. Penyesuaian — `sesuaikan_batch(batchId, qtyFisik, alasan, userId?, catatan='')` (`:574`)

```
bb = SELECT bb.*, b.nama AS nama_barang FROM batch_barang bb JOIN barang b … WHERE bb.id=? FOR UPDATE
   else throw 'Batch tidak ditemukan.'
qtyFisik = max(0, qtyFisik); periksa_qty_wajar(qtyFisik, 'Jumlah fisik')
beda = qtyFisik - bb.qty_tersisa; if beda == 0: return 0        # tidak ada baris penyesuaian
if trim(alasan)=='': throw "Batch {nomor_batch} ({nama_barang}) berselisih {+beda|beda} unit. Isi dulu alasannya sebelum menyimpan."
UPDATE batch_barang SET qty_tersisa = ? WHERE id = ?
INSERT INTO penyesuaian_stok (no_penyesuaian, tanggal, lokasi_tipe, toko_id, lokasi_rak_id, barang_id, batch_id, jenis, qty, alasan, user_id)
VALUES (nomor_dokumen('penyesuaian_stok','no_penyesuaian','PS'), NOW(), toko_id?'toko':'gudang', toko_id|null, lokasi_rak_id|null,
        barang_id, batchId, beda>0?'koreksi_tambah':'koreksi_kurang', abs(beda), trim(alasan + (catatan?' — '+catatan:'')), userId)
return beda
```
Pemanggil (opname) : `kunci_stok(DISTINCT barang_id dari batch yang dihitung)` → `SELECT … FROM batch_barang WHERE id IN (…) AND lokasi_rak_id = rak FOR UPDATE` (kosong → `'Batch yang dihitung tidak ada di rak itu.'`) → loop sesuaikan_batch → recalc per barang tersentuh. Baris `qty_fisik` kosong = dilewati (bukan 0). Flash: `"Opname selesai — seluruh {cocok} batch cocok dengan catatan sistem."` / `"Opname selesai — {selisih} batch disesuaikan, {cocok} batch cocok."`. Galat form: `'Rak belum dipilih.'`, `'Tidak ada baris yang diisi. Isi jumlah fisik minimal satu batch.'`.

### `ubah_penyesuaian(id, qtyBaru, alasan)` (`:650`) — Manager, stok ikut bergerak
```
barangId = SELECT barang_id FROM penyesuaian_stok WHERE id=?  else 'Catatan penyesuaian itu sudah tidak ada.'
kunci_stok([barangId])
ps = SELECT * … FOR UPDATE else sama
if !boleh_ubah_penyesuaian(ps): 'Mengubah penyesuaian ini di luar wewenang akun Anda.'
   # boleh = boleh_kelola_master && (!terikat_satu_gudang || (ps.lokasi_tipe=='gudang' && ps.lokasi_rak_id in rak_saya))
alasan trim; ''→'Alasan wajib diisi.'; potong 255
qtyBaru<0 → 'Jumlah tidak boleh negatif.'; periksa_qty_wajar(qtyBaru)
lama=ps.qty; naik = ps.jenis=='koreksi_tambah'; riwayatSaja = lama > MAKS_QTY_BARIS; geser=0
if !riwayatSaja && qtyBaru != lama:
  geser = naik ? qtyBaru-lama : lama-qtyBaru
  if !ps.batch_id: 'Penyesuaian ini tidak terikat ke batch mana pun, jadi jumlahnya tidak bisa diubah — hanya alasannya.'
  bb = SELECT nomor_batch, qty_tersisa FROM batch_barang WHERE id=? FOR UPDATE else 'Batch penyesuaian ini sudah tidak ada, jadi jumlahnya tidak bisa diubah.'
  stokBatch = bb.qty_tersisa + geser
  if stokBatch<0: "Stok batch {nomor_batch} tinggal {qty_tersisa} — tidak cukup untuk perubahan ini (perlu {-geser}). Sebagian barangnya sudah keluar atau dipindahkan."
  periksa_qty_wajar(stokBatch, 'Stok batch'); UPDATE batch_barang SET qty_tersisa=? WHERE id=?
if qtyBaru==0: DELETE FROM penyesuaian_stok WHERE id=?  else UPDATE … SET qty=?, alasan=? WHERE id=?
if geser!=0: recalc_stok(barangId)
```

---

## 7. Pelepasan & perbaikan (`:829`, `:892`)

```
JENIS_PELEPASAN: dibuang | dijual | perbaikan   (label[0] dipakai di flash)
HASIL_PERBAIKAN: layak | dibuang | dijual

lepas_stok(batchId, qty, jenis, alasan, ekstra{biaya, nilai_jual, no_bukti_setor}) -> pelepasan_id
  jenis ∉ JENIS_PELEPASAN → 'Jenis pelepasan tidak dikenal.'
  trim(alasan)=='' → 'Alasan wajib diisi — barang ini titipan orang.'
  bb = SELECT * FROM batch_barang WHERE id=? FOR UPDATE else 'Batch tidak ditemukan.'
  qty<=0 → 'Jumlah harus lebih dari nol.'
  qty>bb.qty_tersisa → "Jumlahnya melebihi sisa batch ini ({qty_tersisa})."
  UPDATE batch_barang SET qty_tersisa = qty_tersisa - ? WHERE id = ?
  INSERT INTO pelepasan_stok (no_pelepasan, tanggal, batch_id, barang_id, donatur_id, qty, jenis, alasan, status, biaya, nilai_jual, no_bukti_setor, user_id)
   VALUES (PL, CURDATE(), …, jenis=='perbaikan'?'proses':'selesai', …)
  (pemanggil: kunci_stok dulu, recalc sesudah)

selesaikan_perbaikan(pid, hasil, ekstra) -> ?batch_baru_id
  hasil ∉ HASIL_PERBAIKAN → 'Hasil perbaikan tidak dikenal.'
  p = SELECT p.*, bb.nomor_batch, bb.lokasi_rak_id, bb.tanggal_expired, bb.harga_beli, bb.supplier_id
        FROM pelepasan_stok p LEFT JOIN batch_barang bb ON bb.id=p.batch_id WHERE p.id=? FOR UPDATE
      else 'Catatan perbaikan tidak ditemukan.'
  p.jenis!='perbaikan' → 'Catatan ini bukan perbaikan.'; p.status=='selesai' → 'Perbaikan ini sudah ditutup.'
  if hasil=='layak':
    baru = tambah_ke_rak(p.barang_id, p.lokasi_rak_id, (p.nomor_batch ?: 'PB'+today('ymd')) + '-R', p.tanggal_expired, p.qty,
                         p.harga_beli, p.supplier_id, 'bekas_layak', 'donasi', p.donatur_id); recalc
  UPDATE pelepasan_stok SET status='selesai', hasil=?, tanggal_selesai=CURDATE(), biaya=?, nilai_jual=(dijual?…:NULL),
         no_bukti_setor=(dijual?…:NULL), batch_kembali_id=? WHERE id=?
```
Halaman: non-Manager → 403 `"403 — Membuang, menjual, atau mengirim barang ke perbaikan hanya untuk tingkat Manager."`; batch ∉ `in_rak_saya` → `'Batch itu tidak ada di gudang Anda.'`; dijual tanpa bukti → `'Nomor bukti setor wajib diisi untuk penjualan — inilah yang menautkan uangnya ke barang yang keluar.'` (lepas) / `'Nomor bukti setor wajib diisi untuk penjualan.'` (selesai). Flash: `"{Label} tercatat — {qty} unit keluar dari stok tersedia."`, `"Perbaikan ditutup — {LabelHasil}."`.

---

## 8. Mesin perpindahan

### 8.1 Rute (`rute_sah`, `:4487`)
| jenis | asal | tujuan | butuh_penerimaan | hanya_layak | boleh kardus | ambil kardus siap | boleh transit |
|---|---|---|---|---|---|---|---|
| penerimaan | supplier, eksternal | rak, gudang | ✔ | | | | |
| transfer_gudang | gudang, rak | rak, gudang | ✔ | | ✔ | ✔ | ✔ |
| mutasi_rak | rak | rak | | | ✔ (kemas) | | |
| kirim_toko | gudang, rak | toko | ✔ | ✔ | ✔ | ✔ | ✔ |
| retur_toko | toko | rak, gudang | ✔ | | | | ✔ |
| antar_toko | toko | toko | ✔ | ✔ | | | ✔ |
| penyaluran | gudang, rak, toko, supplier | penerima | | ✔ | ✔ | ✔ | ✔ |
| retur | gudang, rak | supplier | | | | | |

`langsung_sah(j) = j=='mutasi_rak'` (tanpa persetujuan). `jenis_boleh_peran()` = toko → `[mutasi_rak, transfer_gudang]`; lainnya semua 8.

`periksa_rute(j, asal, tujuan)` → `'Jenis perpindahan tidak dikenal.'` | `"Asal \"{asal}\" tidak sah untuk jenis \"{j}\"."` | `"Tujuan \"{tujuan}\" tidak sah untuk jenis \"{j}\"."` | null.

### 8.2 `rak_tujuan(tujuanTipe, tujuanId, rakAsal?)` (`:4448`)
```
'rak': if rakAsal==tujuanId → 'Rak asal dan rak tujuan tidak boleh sama.'; return tujuanId
not 'gudang' or !tujuanId → null
SELECT lr.id FROM lokasi_rak lr
 LEFT JOIN (SELECT lokasi_rak_id, SUM(qty_tersisa) terisi FROM batch_barang WHERE qty_tersisa > 0 GROUP BY lokasi_rak_id) x ON x.lokasi_rak_id = lr.id
 WHERE lr.gudang_id = ? AND lr.deleted_at IS NULL AND (? IS NULL OR lr.id <> ?)
 ORDER BY (COALESCE(lr.kapasitas, 999999) - COALESCE(x.terisi, 0)) DESC, lr.kode ASC LIMIT 1
none → 'Gudang tujuan belum memiliki rak yang bisa dipakai. Tambahkan minimal satu rak di gudang tersebut lebih dulu (selain rak asal).'
```

### 8.3 Reservasi stok (`_stok_pesan.php`)
```
STATUS_MEMESAN_STOK = ['menunggu_persetujuan','dikirim']
  buat SJ / kirim draft  → cek vs STATUS_MEMESAN_STOK
  setujui                → cek vs ['dikirim'] saja (yang menunggu tidak saling mengunci)
  draft tidak memesan; Barang Keluar & penyesuaian tidak memeriksa pesanan.

syarat_asal_dokumen(tipe, id, a='p'):
  gudang → "((a.asal_tipe='gudang' AND a.asal_id=id) OR (a.asal_tipe='rak' AND a.asal_id IN (SELECT id FROM lokasi_rak WHERE gudang_id=id)))"
  rak    → "(a.asal_tipe='rak' AND a.asal_id=id)";  toko → "(a.asal_tipe='toko' AND a.asal_id=id)"; lain → null

stok_dipesan(tipe, id, barangIds, status, kecualiPp?) -> {barang_id: {qty, dok[]}}
  SELECT d.barang_id, SUM(d.qty) qty,
         GROUP_CONCAT(DISTINCT COALESCE(p.no_surat_jalan, p.no_perpindahan) ORDER BY p.id SEPARATOR ', ') dok
  FROM perpindahan_detail d JOIN perpindahan p ON p.id=d.perpindahan_id
  WHERE d.barang_id IN (…) AND p.status IN (…) AND {syarat} AND p.id <> ? GROUP BY d.barang_id

stok_lokasi_asal(b, tipe, id, hanyaLayak):
  hanyaLayak → stok_layak_salur_lokasi(b, rak?, gudang?, toko?)
  else gudang→stok_di_gudang, rak→stok_tersedia(b,rak), toko→stok_di_toko, lain→stok_tersedia(b)

periksa_stok_dokumen(jenis, asalTipe, asalId, items[{barang_id,qty}], statusPesan=STATUS_MEMESAN_STOK, kecualiPp?) -> string[]
  jenis=='' || jenis=='penerimaan' || asalTipe in [supplier,eksternal,''] || !asalId → []
  total per barang (qty>0); kosong → []
  hanyaLayak = jenis_hanya_layak(jenis); pesan = stok_dipesan(...)
  per barang: ada = stok_lokasi_asal; dpsn = pesan.qty; bisa = max(0, ada-dpsn); if diminta<=bisa continue
    m = "{nama}[ ({kode})]: diminta {diminta} {satuan}, {hanyaLayak?'stok siap salur':'stok'} di lokasi asal {ada}"
      + (dpsn>0 ? ", sudah dipesan dokumen lain {dpsn} ({dok[0..3]}{>3?', …'})" : "")
      + " — yang masih bisa dikirim {bisa} {satuan}."
      + (hanyaLayak ? " Barang Rusak/Bocor, Expired, dan tidak layak tidak ikut dihitung." : "")
  satuan default 'unit'
```

### 8.4 `sahkan_perpindahan(ppId, olehUserId)` (`:4578`) — dalam tx pemanggil
```
pp = SELECT * FROM perpindahan WHERE id=? FOR UPDATE else 'Dokumen tidak ditemukan.'
status ∉ [menunggu_persetujuan, draft] → "Dokumen sudah diproses sebelumnya (status: {status})."
detail = SELECT * FROM perpindahan_detail WHERE perpindahan_id=?  kosong → 'Dokumen tidak memiliki rincian barang.'
kurang = periksa_stok_dokumen(jenis, asal_tipe, asal_id, detail, ['dikirim'], ppId)
  → 'Stok tidak cukup untuk menyetujui dokumen ini: ' + join(' • ', kurang)
adaPenerima = butuh_penerimaan(jenis)
if !adaPenerima: terapkan_perpindahan(pp, detail)  else catat_batch_pratinjau(pp, detail)
statusBaru = adaPenerima ? 'dikirim' : 'selesai'
for 5x: kandidat = nomor_dokumen('perpindahan','no_surat_jalan','SJ')
  try UPDATE perpindahan SET status=?, no_surat_jalan=?, approved_by=?, approved_at=NOW() WHERE id=?; break
  catch 23000 && kandidat in msg → sleep 5–40ms, continue; else rethrow
gagal → 'Nomor surat jalan sedang berebut dipakai. Coba lagi sesaat lagi.'
kardus_sesudah_sah(ppId, adaPenerima, olehUserId)
return {no_sj, status, ada_penerima, no_perpindahan}
```

### 8.5 `terapkan_perpindahan(pp, detail)` (`:4694`) — SATU-SATUNYA tempat stok berpindah
```
pesan = periksa_rute(...) → "Dokumen ini tidak dapat diproses: {pesan} Batalkan dokumen dan buat ulang dengan rute yang benar."
masuk  = jenis=='penerimaan'
pindah = jenis in [transfer_gudang, mutasi_rak, kirim_toko, retur_toko, antar_toko]
rakAsal/gudangAsal/tokoAsal dari asal_tipe; supplierId = asal_tipe=='supplier' ? asal_id : null
tokoTujuan = tujuan_tipe=='toko' ? tujuan_id : null
rakTujuan  = (tokoTujuan==null && (masuk||pindah)) ? rak_tujuan(tujuan_tipe, tujuan_id, rakAsal) : null
if (masuk||pindah) && rakTujuan==null && tokoTujuan==null → 'Lokasi tujuan perpindahan tidak dapat ditentukan.'
kunci_stok(all detail.barang_id)
for d in detail:
  qty = d.qty_diterima ?? d.qty; if qty<=0 continue
  if masuk:
    batchId = tambah_ke_lokasi(b, rakTujuan, tokoTujuan, d.nomor_batch ?: 'B'+today('ymd'), d.tanggal_expired, qty,
                               d.harga ?? 0, supplierId, d.kondisi ?? 'baru', d.sumber ?? 'pembelian', d.donatur_id)
    ringkasan[d.id] = {batch_id, teks: "{nomor} ({qty})"}
    catat_mutasi(pp.id, d.id, b, null, batchId, qty)
  else:
    dariMitra = asal_tipe=='supplier'
    batchDiminta = trim(d.nomor_batch) ?: null
    rakBaris = d.kardus_id ? kardus_rak_ambil(d.kardus_id, gudangAsal, rakAsal) : null
    try diambil = dariMitra ? ambil_fefo_supplier(b, qty, supplierId, batchDiminta)
                            : ambil_fefo(b, qty, rakBaris ?? rakAsal, rakBaris ? null : gudangAsal, tokoAsal, jenis_hanya_layak(jenis), batchDiminta)
    catch → rethrow "{nama}[ ({kode})] — {pesan asli}"
    for a in diambil:
      sudahDiTujuan = pindah && ((rakTujuan!=null && a.lokasi_rak_id==rakTujuan) || (tokoTujuan!=null && a.toko_id==tokoTujuan))
      if sudahDiTujuan: teks += "{nomor} ({ambil}, sudah di lokasi tujuan)"; continue      # tidak dipotong, tidak ditambah
      potong (batch_barang.qty_tersisa -= ambil | stok_supplier.qty -= ambil)
      batchTujuanBaru = pindah ? tambah_ke_lokasi(b, rakTujuan, tokoTujuan, a.nomor_batch, a.tanggal_expired, a.ambil,
                                   a.harga_beli, a.supplier_id, a.kondisi ?? 'baru', a.sumber ?? 'pembelian', a.donatur_id) : null
      if !dariMitra: catat_mutasi(pp.id, d.id, b, a.batch_id, batchTujuanBaru, a.ambil)
    ringkasan[d.id] = {batch_id: dariMitra ? null : diambil[0].batch_id, teks: join(', ')}
    if dariMitra: DELETE FROM stok_supplier WHERE id=? AND qty<=0 (tiap a)
  recalc_stok(b)
for ringkasan: UPDATE perpindahan_detail SET batch_id=?,
   keterangan = LEFT(CONCAT(IF(COALESCE(keterangan,'')='','',CONCAT(TRIM(keterangan),' | ')),'Batch: ',?),255) WHERE id=?
   -- teks dipotong mb_substr(…,0,180) sebelum dikirim
```
Jurnal: `catat_mutasi_perpindahan` → `INSERT INTO perpindahan_mutasi (perpindahan_id, detail_id, barang_id, batch_asal_id, batch_tujuan_id, qty)`. Stok mitra tidak dijurnal.

### 8.6 Penerimaan (`penerimaan.php:99`) — tx
```
403 bila !bolehTerima: "403 — Konfirmasi penerimaan barang dilakukan oleh petugas gudang atau toko tujuan."
pp FOR UPDATE else 'Dokumen tidak ditemukan.'; !boleh_terima → 'Dokumen ini bukan untuk lokasi Anda.'
status!='dikirim' → "Dokumen ini tidak menunggu penerimaan (status: {status})."
detail ORDER BY id; kosong → 'Dokumen tidak memiliki rincian barang.'
per baris: terima = clamp(int(qty_diterima[did] ?? qty), 0, qty); ket = trim(selisih_catatan[did])
  kardus_kondisi[kardus_id]=='hilang' → terima=0; ket = ket ?: 'Kardus hilang'
  terima<qty && ket=='' → 'Ada barang yang diterima kurang dari jumlah di surat jalan. Isi dulu catatan selisih pada baris tersebut sebelum menyimpan.'
  UPDATE perpindahan_detail SET qty_diterima=?, selisih_catatan=? WHERE id=? AND perpindahan_id=?
totalTerima<=0 → 'Seluruh baris tercatat diterima 0. Bila barang memang tidak datang sama sekali, jangan tandai diterima — hubungi pengirim agar dokumennya dibatalkan.'
terapkan_perpindahan(pp, detail)                       # stok berpindah DI SINI
kardus_sesudah_terima(...) (foto per kardus 'foto_kardus_{id}')
UPDATE perpindahan SET status='selesai', diterima_oleh=?, diterima_at=NOW(), catatan_terima=? WHERE id=?
flash: adaSelisih ? 'Barang diterima dengan SELISIH. Stok diperbarui sesuai hasil hitung fisik, dan selisihnya tercatat pada dokumen.'
                  : 'Barang diterima lengkap. Stok sudah diperbarui.'  (+ ' Kardus: a, b.')
deadlock → 'Petugas lain sedang memproses barang yang sama. Stok tidak berubah — silakan coba lagi.'
```

### 8.7 Batal / hapus draft / tolak (`:5135`, `:5170`, persetujuan.php)
```
batalkan_perpindahan(ppId, user, alasan): tx sendiri
  status ∉ [draft, menunggu_persetujuan, dikirim] → "Dokumen berstatus \"{status}\" tidak bisa dibatalkan — stoknya sudah berpindah. Buat dokumen retur bila barangnya perlu kembali."
  UPDATE perpindahan_detail SET qty_diterima=NULL WHERE perpindahan_id=?
  kardus_batal_dokumen(...)
  UPDATE perpindahan SET status='dibatalkan', alasan_tolak=?, updated_at=NOW()   -- 'Dibatalkan: '+substr(alasan,0,240)
hapus_draft_perpindahan(ppId): status!='draft' → 'Hanya draft yang bisa dihapus. Dokumen lain dibatalkan, bukan dihapus, supaya jejaknya tetap ada.'
  DELETE detail; kardus_hapus_dokumen; DELETE perpindahan
tolak: alasan '' → 'Alasan penolakan wajib diisi agar pembuat dokumen tahu apa yang harus diperbaiki.'
  UPDATE perpindahan SET status='ditolak', alasan_tolak=?, approved_by=?, approved_at=NOW() WHERE id=? AND status='menunggu_persetujuan'
  rowCount 0 → 'Dokumen sudah diproses sebelumnya.'
kirim (draft→menunggu): periksa_stok_dokumen(... STATUS_MEMESAN_STOK, ppId) → 'Draft belum bisa dikirim, stok tidak cukup: '+join(' • ')
  status!='draft' → 'Dokumen tidak dalam status draft.'
```

### 8.8 Ubah dokumen selesai — `balikkan_mutasi_perpindahan(ppId)` (`_perpindahan_ubah.php:99`) — Super Admin
```
rows = SELECT * FROM perpindahan_mutasi WHERE perpindahan_id=? ORDER BY id DESC FOR UPDATE; kosong → 0
kunci_stok(barang_id rows)
per m: if batch_tujuan_id: bb FOR UPDATE else 'Batch tujuan dari dokumen ini sudah tidak ada, jadi stoknya tidak bisa dibalikkan.'
          bb.qty_tersisa<qty → "Batch {nomor} di lokasi tujuan tinggal {sisa} dari {qty} yang dikirim dokumen ini — sebagian sudah keluar lagi, jadi jumlah/lokasinya tidak bisa diubah. Catat selisihnya lewat penyesuaian stok atau dokumen baru."
          UPDATE batch_barang SET qty_tersisa=qty_tersisa-?, qty_awal=GREATEST(0,qty_awal-?) WHERE id=?
       if batch_asal_id: bb FOR UPDATE else 'Batch asal dari dokumen ini sudah tidak ada, jadi stoknya tidak bisa dibalikkan.'
          periksa_qty_wajar(sisa+qty,'Stok batch asal'); UPDATE … qty_tersisa=qty_tersisa+?
DELETE FROM perpindahan_mutasi WHERE perpindahan_id=?; recalc per barang
```
`ubah_perpindahan`: status ∉ [draft, menunggu_persetujuan, dikirim, selesai] → `"Dokumen berstatus \"{label}\" tidak bisa diubah."`; selesai+sentuhStok tanpa jejak → `'Dokumen ini selesai sebelum pencatat mutasi dipasang, jadi stoknya tidak bisa dibalikkan. Yang bisa diubah hanya data pengiriman (resi, penerima, catatan). Untuk membetulkan jumlah atau lokasinya, buat dokumen baru atau penyesuaian stok.'`; sesudah balik → terapkan ulang. Baris qty 0 = hapus; `'Dokumen harus punya minimal satu baris barang. Untuk membatalkan dokumen, pakai tombol Batalkan.'`. Jejak `Batch: …` di keterangan dipertahankan bila stok tidak disentuh (regex `/(\s*\|\s*)?Batch: .*$/u`).

---

## 9. Barang Masuk (TM) & Keluar (TK) — ringkasan tx

**Masuk** (`masuk.php`): `dengan_nomor_dokumen(TM)` → tx: `kunci_stok` → `INSERT transaksi_masuk (no_transaksi, tanggal=NOW(), supplier_id, user_id, total, catatan)` → bila donasi: `donatur_cocok_atau_buat`, `INSERT donasi (no_donasi=DN, donatur_id, supplier_id, tanggal=CURDATE(), jenis, cara_terima, ekspedisi?, no_resi?, catatan, dibuat_oleh)`, `UPDATE transaksi_masuk SET donasi_id`, `INSERT donasi_detail (donasi_id, barang_id, kondisi, nomor_batch, tanggal_expired, qty, nilai_taksir=qty*harga)` → per item `tambah_ke_rak(...)` + `INSERT transaksi_masuk_detail (transaksi_masuk_id, barang_id, batch_id, nomor_batch, tanggal_expired, lokasi_rak_id, qty, harga, subtotal)` → `recalc_stok` per barang unik → commit. Nomor batch default `'B'+today('ymd')`. Validasi: lihat 04 §Form.

**Keluar** (`keluar.php`): merge qty per barang; pre-check `stok_layak_salur(b, gudangPotong)` → `"{nama}: diminta {q} {satuan}, yang layak disalurkan hanya {ada}."`; tx TK: `kunci_stok` → `INSERT transaksi_keluar (no_transaksi, tanggal=NOW(), user_id, penerima, untuk_bayi, divisi_cabang, catatan, keperluan)` → per barang `ambil_fefo(b, q, null, gudangPotong, null, hanyaLayak=true)` → `UPDATE batch_barang SET qty_tersisa=qty_tersisa-? WHERE id=?` → `INSERT transaksi_keluar_detail (transaksi_keluar_id, barang_id, batch_id, qty)` → recalc. Flash `"Barang keluar {no} tersimpan — {n} jenis, {q} unit. Batch terpakai: B1 (n), …"`.

### Donatur (`:2542`)
```
kunci_telepon(t): hapus non-digit; ''→''; prefix '62' → '0'+sisa; tidak diawali '0' → '0'+t
kunci_nama(n): rapatkan spasi, trim, lowercase (mb)
donatur_cocok_atau_buat(d{nama,telepon,jenis,wilayah,anonim}, supplierId?) -> {id, baru, kode, bentrok}
  kandidat = SELECT id,kode,nama,telepon,… FROM donatur WHERE deleted_at IS NULL [AND (supplier_id=? OR supplier_id IS NULL)]
  samaNama && samaHp → return lama (bentrok null)
  samaHp && kt!='' && nama beda → bentrok = baris pertama (tidak menghentikan)
  jenis ∉ [pribadi,komunitas,lembaga] → 'pribadi'; kode = kode_berikut('donatur','DN')
  INSERT INTO donatur (kode, nama, jenis, supplier_id, wilayah, telepon, anonim, is_active) VALUES (…,1)
```

---

## 10. Kardus (`_kardus.php`)

```
KARDUS_STATUS : draft | dikemas | siap_dikirim | dalam_perjalanan | diterima | dibatalkan
KARDUS_KONDISI: baik | rusak | hilang | perlu_pemeriksaan
jenis_boleh_kardus(j)      = j in [transfer_gudang, kirim_toko, penyaluran, mutasi_rak]
jenis_ambil_kardus_siap(j) = j in [transfer_gudang, kirim_toko, penyaluran]

kardus_buat_untuk_dokumen(ppId, pp, kepala{idx: {nama, berat_kg, panjang_cm, lebar_cm, tinggi_cm, catatan, ada?}}, items, userId)
  idx<=0 skip; tanpa item kardus_idx==idx → "Kardus {idx} kosong — isi barangnya atau hapus kardusnya."
  angka: ''→null; <0 atau > maks (berat 5000, dimensi 1000) → 'Angka berat/dimensi kardus tidak wajar.'
  ada (kardus siap kirim): kardus_siap_periksa(kunci) → UPDATE kardus SET perpindahan_id=?, status='dikemas'; riwayat 'kirim'
  baru: INSERT INTO kardus (kode=KRD, nama, gudang_id, lokasi_rak_id, tanggal_kemas=pp.tanggal, petugas_id, berat_kg, panjang_cm, lebar_cm, tinggi_cm, catatan, status='dikemas', perpindahan_id, ditutup_at=NOW()); riwayat 'buat'

kardus_siap_periksa(kid, jenis, asalTipe, asalId):
  "Kardus #{kid} tidak ditemukan." | 'Kardus siap kirim hanya bisa dibawa surat jalan transfer gudang, kirim toko, atau penyaluran.'
  status!='siap_dikirim' → "Kardus {kode} berstatus {Label} — hanya kardus Siap dikirim yang bisa dicentang."
  rak/gudang ≠ asal → "Kardus {kode} berada di {rak|gudang}, bukan di lokasi asal surat jalan ini."

kardus_rak_ambil(kid, gudangAsal, rakAsal): rak kardus bila == rakAsal, atau gudangnya == gudangAsal; else null

kardus_sesudah_sah(ppId, adaPenerima, user): per kardus dokumen
  mutasi_rak → status='siap_dikirim', lokasi_rak_id=tujuan_id, gudang_id=COALESCE(gudang rak tujuan, gudang_id); riwayat 'siap'
  adaPenerima → 'dalam_perjalanan'; riwayat 'berangkat'
  else → 'diterima', diterima_at=NOW(), diterima_oleh; riwayat 'terima'
kardus_sesudah_terima(ppId, detail, kondisi[], catatan[], foto[], user):
  selisih = ada detail kardus dengan qty_diterima != qty; kd = kondisi[kid] ∈ KARDUS_KONDISI else 'baik'; selisih && 'baik' → 'perlu_pemeriksaan'
  UPDATE kardus SET status='diterima', kondisi=?, diterima_at=NOW(), diterima_oleh=?, catatan_terima=?, foto_terima=COALESCE(?,foto_terima)
kardus_batal_dokumen: kardus pinjaman (ada mutasi_rak selesai lain yang memuatnya) → kembali perpindahan_id=kemas, status='siap_dikirim' (riwayat 'lepas_sj'); else 'dibatalkan', alasan_batal
kardus_hapus_dokumen: pinjaman → kembali siap_dikirim; else DELETE kardus (riwayat cascade)
kardus_riwayat: INSERT INTO kardus_riwayat (kardus_id, aksi, keterangan, user_id)
```

---

## 11. Titik transit (`_lacak.php`)

```
jenis_boleh_transit(j) = j in [transfer_gudang, kirim_toko, antar_toko, retur_toko, penyaluran]
periksa_titik_transit(isian[], jenis, asalTipe, asalId, tujuanTipe, tujuanId) -> int[] (unik, urut)
  kosong → []
  !jenis_boleh_transit → 'Titik transit hanya untuk dokumen yang barangnya menempuh perjalanan (transfer, kirim toko, penyaluran).'
  > MAKS_TITIK_TRANSIT → 'Titik transit paling banyak 3.'
  gid == gudang asal (gudang / gudang pemilik rak) → 'Gudang asal tidak bisa menjadi titik transit.'
  gid == gudang tujuan → 'Gudang tujuan tidak bisa menjadi titik transit.'
  COUNT gudang aktif (deleted_at IS NULL AND is_active=1) != n → 'Salah satu gudang transit tidak ditemukan atau sudah nonaktif.'
simpan_titik_transit: INSERT INTO perpindahan_titik (perpindahan_id, urutan=i+1, lokasi_tipe='gudang', lokasi_id)

catat_transit(titikId, aksi, userId, catatan, angkut{kendaraan,no_polisi,pengemudi}) — dalam tx
  aksi ∉ [tiba, berangkat] → 'Tindakan tidak dikenali.'
  t FOR UPDATE else 'Titik transit itu sudah tidak ada.'; !boleh_catat_transit → 'Titik transit ini bukan lokasi yang Anda pegang.'
  pp FOR UPDATE else 'Dokumennya sudah tidak ada.'; status!='dikirim' → "Dokumen ini berstatus \"{label}\", bukan dalam perjalanan — tidak ada yang perlu ditandai."
  tiba && tiba_at → "Tiba di titik ini sudah dicatat pada {tgl_jam}."
  berangkat: !tiba_at → 'Tandai "tiba" dulu sebelum "berangkat".'; berangkat_at → "Berangkat dari titik ini sudah dicatat pada {tgl_jam}."
  UPDATE perpindahan_titik SET {aksi}_at=NOW(), {aksi}_oleh=?, catatan=CASE WHEN ?='' THEN catatan ELSE ? END WHERE id=?
  berangkat & ada angkut → UPDATE … SET kendaraan=?, no_polisi=?, pengemudi=? (potong 60/20/100)
  Urutan antar titik SENGAJA tidak dijaga.
boleh_catat_transit(t): super → true; gudang & terikat → lokasi_id ∈ gudang_saya; toko & is_toko → ∈ toko_saya

posisi_kiriman(pp, titik) -> {teks, kelas, transit}
  draft|menunggu_persetujuan|disetujui → 'Belum berangkat'; ditolak → 'Ditolak'; dibatalkan → 'Dibatalkan'
  diterima|selesai → butuh_penerimaan ? "Diterima di {tujuan}" : 'Selesai'
  dikirim: titik dengan tiba_at && !berangkat_at → "Di {lokasi} (transit)"; else "Menuju {titik pertama belum berangkat (transit) | tujuan}"
linimasa_kiriman: urutan langkah Dibuat → Disetujui/berangkat dari asal → [Tiba di T, Berangkat dari T]* → Diterima/Selesai di tujuan;
  keadaan: selesai | kini (langkah berikut) | nanti | lewat (dilewati tanpa dicatat / dibatalkan)
```

---

## 12. Snapshot harian dasbor

`api/dashboard.php` & `dashboard.php` melakukan upsert `stok_harian (tanggal=CURDATE(), total_stok, total_jenis, nilai_stok)` setiap kali dipanggil. Grafik stok 7/30 hari membaca tabel ini. Implementasi baru: lakukan hal sama **atau** cron harian 23:59 — pilih satu, dokumentasikan.
