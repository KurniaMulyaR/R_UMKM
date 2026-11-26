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
                    ['name' => 'Kasir & pembayaran', 'status' => 'Sebagian', 'summary' => 'POS, diskon, split tender, tunai, transfer, kasbon, QRIS manual, dan QRIS Xendit otomatis (kunci per usaha). Printer struk dan scanner belum dikonfirmasi.'],
                    ['name' => 'Katalog produk & barcode', 'status' => 'Sebagian', 'summary' => 'Tambah/edit produk, harga, barcode, kategori, dan arsip. Varian lanjutan dan konversi satuan belum ada.'],
                    ['name' => 'Persediaan & stok', 'status' => 'Sebagian', 'summary' => 'Stok awal, mutasi penjualan/pembelian/retur, dan opname dengan persetujuan serta jurnal selisih. Transfer antar-outlet (kirim, terima dengan selisih, batal) dan kelola outlet tersedia. Penyesuaian massal belum ada.'],
                    ['name' => 'Pelanggan & piutang', 'status' => 'Sebagian', 'summary' => 'Data pelanggan dan pelunasan kasbon dasar tersedia. Umur piutang per pelanggan dan batas kredit (ditegakkan di kasir) tersedia. Reminder penagihan belum ada.'],
                    ['name' => 'Shift kasir', 'status' => 'Ada', 'summary' => 'Buka/tutup shift dan hitung selisih kas dasar.'],
                    ['name' => 'Transaksi, void & retur', 'status' => 'Ada', 'summary' => 'Riwayat transaksi, void dengan jurnal pembalik, dan retur penjualan sebagian/penuh (stok, kasbon, refund, shift).'],
                    ['name' => 'Dashboard & laporan', 'status' => 'Sebagian', 'summary' => 'Ringkasan omzet, transaksi, perkiraan laba kotor, stok menipis, dan transaksi terbaru. Laporan periodik belum ada.'],
                    ['name' => 'Tenant, outlet & hak akses', 'status' => 'Sebagian', 'summary' => 'Akun usaha, outlet awal, dan peran dasar. Pengelolaan banyak outlet serta permission granular belum ada.'],
                    ['name' => 'Audit & event transaksi', 'status' => 'Sebagian', 'summary' => 'Audit log dan outbox untuk transaksi inti. Viewer audit dan pemrosesan event terjadwal belum ada.'],
                ],
            ],
            [
                'group' => 'Pembelian, dokumen & keuangan',
                'features' => [
                    ['name' => 'Supplier, PO & penerimaan barang', 'status' => 'Ada', 'summary' => 'Master supplier, pesanan pembelian dengan persetujuan, penerimaan sebagian, dan biaya rata-rata bergerak. Retur pembelian belum ada.'],
                    ['name' => 'Tagihan & pembayaran supplier', 'status' => 'Ada', 'summary' => 'Tagihan otomatis saat barang diterima, pembayaran bertahap, retur pembelian termasuk refund atas tagihan yang sudah dibayar, dan laporan umur hutang.'],
                    ['name' => 'Invoice, surat jalan & kwitansi', 'status' => 'Sebagian', 'summary' => 'Invoice, surat jalan, dan kwitansi (dengan terbilang) bernomor otomatis, siap cetak/PDF dari detail transaksi. Pengiriman WhatsApp/email dan template logo belum ada.'],
                    ['name' => 'Pengeluaran & rekonsiliasi kas/bank', 'status' => 'Ada', 'summary' => 'Biaya operasional per kategori terhubung shift dan jurnal, plus rekonsiliasi kas/bank/QRIS terhadap saldo rekening.'],
                    ['name' => 'COA, jurnal & tutup periode', 'status' => 'Ada', 'summary' => 'COA sistem + akun sendiri, jurnal otomatis dan manual, import jurnal CSV, buku besar, tutup/buka periode dengan kunci posting.'],
                    ['name' => 'Laporan usaha', 'status' => 'Sebagian', 'summary' => 'Laba rugi, neraca, arus kas (ekspor CSV), buku besar, umur hutang dan piutang. Ekspor PDF/Excel untuk laporan lain belum ada.'],
                ],
            ],
            [
                'group' => 'Modul khusus usaha',
                'features' => [
                    ['name' => 'Sinkronisasi offline & perangkat', 'status' => 'Belum dibangun', 'summary' => 'Antrean offline, registrasi perangkat, sinkronisasi idempoten, dan penanganan konflik. Tabel dasar sudah disiapkan.'],
                    ['name' => 'Menu, resep & biaya bahan', 'status' => 'Belum dibangun', 'summary' => 'Resep bahan, pengurangan bahan otomatis, dan costing untuk usaha makanan.'],
                    ['name' => 'Nomor seri & garansi', 'status' => 'Belum dibangun', 'summary' => 'Serial unit, riwayat garansi, dan servis untuk toko elektronik.'],
                    ['name' => 'Aset & perawatan', 'status' => 'Sebagian', 'summary' => 'Register aset, umur, nilai buku garis lurus, jadwal dan riwayat perawatan, pelepasan, dan posting jurnal penyusutan bulanan sudah ada. Jurnal perolehan aset tersedia; pembelian aset lewat PO belum.'],
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


