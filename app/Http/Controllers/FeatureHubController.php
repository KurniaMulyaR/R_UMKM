<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class FeatureHubController extends Controller
{
    public function __invoke(): View
    {
        $modules = [
            [
                'group' => 'Operasional inti',
                'features' => [
                    ['name' => 'Kasir & pembayaran', 'status' => 'Sebagian', 'summary' => 'POS, diskon, split tender, tunai, transfer, QRIS manual, dan kasbon. Gateway QRIS belum terhubung.'],
                    ['name' => 'Katalog produk & barcode', 'status' => 'Sebagian', 'summary' => 'Tambah/edit produk, harga, barcode, kategori, dan arsip. Varian lanjutan dan konversi satuan belum ada.'],
                    ['name' => 'Persediaan & stok', 'status' => 'Sebagian', 'summary' => 'Stok awal dan mutasi penjualan tercatat. Opname, penyesuaian, transfer antar-outlet, dan retur stok belum tersedia.'],
                    ['name' => 'Pelanggan & piutang', 'status' => 'Sebagian', 'summary' => 'Data pelanggan dan pelunasan kasbon dasar tersedia. Aging piutang, limit kredit, dan reminder belum ada.'],
                    ['name' => 'Shift kasir', 'status' => 'Ada', 'summary' => 'Buka/tutup shift dan hitung selisih kas dasar.'],
                    ['name' => 'Transaksi, void & retur', 'status' => 'Sebagian', 'summary' => 'Riwayat transaksi, void pemilik, serta pembalikan stok/jurnal. Alur retur dan refund penuh belum ada.'],
                    ['name' => 'Dashboard & laporan', 'status' => 'Sebagian', 'summary' => 'Ringkasan omzet, transaksi, perkiraan laba kotor, stok menipis, dan transaksi terbaru. Laporan periodik belum ada.'],
                    ['name' => 'Tenant, outlet & hak akses', 'status' => 'Sebagian', 'summary' => 'Akun usaha, outlet awal, dan peran dasar. Pengelolaan banyak outlet serta permission granular belum ada.'],
                    ['name' => 'Audit & event transaksi', 'status' => 'Sebagian', 'summary' => 'Audit log dan outbox untuk transaksi inti. Viewer audit dan pemrosesan event terjadwal belum ada.'],
                ],
            ],
            [
                'group' => 'Pembelian, dokumen & keuangan',
                'features' => [
                    ['name' => 'Supplier, PO & penerimaan barang', 'status' => 'Ada', 'summary' => 'Master supplier, pesanan pembelian dengan persetujuan, penerimaan sebagian, dan biaya rata-rata bergerak. Retur pembelian belum ada.'],
                    ['name' => 'Tagihan & pembayaran supplier', 'status' => 'Sebagian', 'summary' => 'Tagihan otomatis saat barang diterima dan pembayaran bertahap sudah ada; retur pembelian dan umur hutang belum.'],
                    ['name' => 'Invoice, surat jalan & kwitansi', 'status' => 'Belum dibangun', 'summary' => 'Dokumen pesanan, pemenuhan, invoice, bukti terima, dan cetak.'],
                    ['name' => 'Pengeluaran & rekonsiliasi kas/bank', 'status' => 'Belum dibangun', 'summary' => 'Pencatatan biaya usaha, mutasi kas, settlement, dan rekonsiliasi.'],
                    ['name' => 'COA, jurnal & tutup periode', 'status' => 'Sebagian', 'summary' => 'Jurnal penjualan dan akun bawaan tercatat. Pengelolaan akun, laporan neraca/laba-rugi, dan tutup periode belum ada.'],
                    ['name' => 'Laporan usaha', 'status' => 'Belum dibangun', 'summary' => 'Laporan penjualan, laba-rugi, arus kas, stok, hutang, dan piutang per periode/outlet.'],
                ],
            ],
            [
                'group' => 'Modul khusus usaha',
                'features' => [
                    ['name' => 'Sinkronisasi offline & perangkat', 'status' => 'Belum dibangun', 'summary' => 'Antrean offline, registrasi perangkat, sinkronisasi idempoten, dan penanganan konflik. Tabel dasar sudah disiapkan.'],
                    ['name' => 'Menu, resep & biaya bahan', 'status' => 'Belum dibangun', 'summary' => 'Resep bahan, pengurangan bahan otomatis, dan costing untuk usaha makanan.'],
                    ['name' => 'Nomor seri & garansi', 'status' => 'Belum dibangun', 'summary' => 'Serial unit, riwayat garansi, dan servis untuk toko elektronik.'],
                    ['name' => 'Aset & perawatan', 'status' => 'Belum dibangun', 'summary' => 'Register aset, jadwal perawatan, penyusutan, dan pelepasan aset.'],
                    ['name' => 'Sewa alat', 'status' => 'Belum dibangun', 'summary' => 'Booking, ketersediaan barang, kontrak sewa, deposit, dan pengembalian.'],
                    ['name' => 'Pembiayaan/cicilan syariah', 'status' => 'Belum dibangun', 'summary' => 'Pengajuan, akad, jadwal angsuran, pembayaran, dan restrukturisasi.'],
                    ['name' => 'Karyawan & payroll', 'status' => 'Belum dibangun', 'summary' => 'Data karyawan, absensi, komponen gaji, potongan, dan proses payroll.'],
                ],
            ],
            [
                'group' => 'Digital, komunitas & platform',
                'features' => [
                    ['name' => 'Toko online, domain & SEO', 'status' => 'Belum dibangun', 'summary' => 'Halaman toko, domain khusus, katalog publik, dan analitik halaman.'],
                    ['name' => 'Marketplace & sosial media', 'status' => 'Belum dibangun', 'summary' => 'Pemetaan produk, sinkronisasi order/settlement, kampanye, dan publikasi konten.'],
                    ['name' => 'Iklan Meta Ads', 'status' => 'Belum dibangun', 'summary' => 'Buat dan pantau kampanye iklan Meta dari data produk dan penjualan, dengan batas anggaran dan persetujuan pemilik.'],
                    ['name' => 'Inbox terpadu & notifikasi WhatsApp', 'status' => 'Belum dibangun', 'summary' => 'Chat dan komentar dari sosmed serta WhatsApp dalam satu kotak masuk, plus notifikasi komentar baru ke penjual.'],
                    ['name' => 'Landing page toko & template', 'status' => 'Belum dibangun', 'summary' => 'Halaman toko per usaha dengan tampilan yang bisa diubah pemilik, katalog publik, dan tombol pesan via WhatsApp.'],
                    ['name' => 'Layanan SEO & domain', 'status' => 'Belum dibangun', 'summary' => 'Pengaturan SEO halaman toko, pembelian atau penyambungan domain, dan SSL otomatis.'],
                    ['name' => 'Komunitas pengguna', 'status' => 'Belum dibangun', 'summary' => 'Forum berbagi ilmu memakai sistem, sumber belajar, moderasi, dan pelaporan konten.'],
                    ['name' => 'Campaign UMKM terbaik', 'status' => 'Belum dibangun', 'summary' => 'Seleksi usaha berpenghasilan baik dengan persetujuan pemilik, lalu kampanye profil dan cerita sukses.'],
                    ['name' => 'AI untuk audit & draf', 'status' => 'Belum dibangun', 'summary' => 'Analisis anomali dan pembuatan draf yang tetap memerlukan persetujuan manusia.'],
                    ['name' => 'Panel master pemilik platform', 'status' => 'Belum dibangun', 'summary' => 'Kontrol seluruh UMKM terdaftar: grafik pertumbuhan, langganan, feature access, support, dan log aktivitas.'],
                ],
            ],
        ];

        return view('features.index', compact('modules'));
    }
}


