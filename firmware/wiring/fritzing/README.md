# Sketsa Fritzing — PresenTech

Folder ini berisi sketsa **Fritzing** asli (bisa dibuka dan diedit), bukan cuma gambar.

| File | Isi |
|---|---|
| `presentech-wiring.fzz` | sketsa Fritzing lengkap: 4 part + 13 kabel, siap dibuka |
| `presentech-breadboard.png` | hasil **File → Export → as Image** dari sketsa di atas |
| `parts/*.fzpz` | part kustom (ESP32, MFRC522, LCD 20x4 I2C, buzzer) untuk dipasang sendiri |

## Cara membuka

1. Unduh `presentech-wiring.fzz`.
2. Buka Fritzing → **File → Open…** → pilih file tersebut.

Sketsa ini **self-contained**: keempat part dan semua SVG-nya sudah ikut di dalam file,
jadi tidak perlu memasang part apa pun dulu. Kalau ada dialog "part tidak ditemukan",
abaikan saja — Fritzing tetap memakai salinan yang ada di dalam `.fzz`.

Diuji dengan **Fritzing 0.9.3b** (versi gratis). Versi 1.x juga bisa membukanya.

## Kalau ingin part-nya masuk ke daftar Parts

Supaya part muncul di panel **Parts** dan bisa dipakai di sketsa lain:

1. Buka Fritzing → menu **Part → Import…**
2. Pilih salah satu `.fzpz` di folder `parts/`.
3. Ulangi untuk tiga file lainnya.

Setelah itu keempat part muncul di bin **Mine** (atau **My Parts**).

## Isi sketsa

| Part | Keterangan |
|---|---|
| ESP32 DevKit V1 (30 pin) | semua pin diberi label sesuai silk board asli |
| MFRC522 / RC522 | 8 pin: SDA, SCK, MOSI, MISO, IRQ, GND, RST, 3.3V |
| LCD 20x4 I2C | backpack PCF8574, 4 pin: GND, VCC, SDA, SCL |
| Buzzer aktif | 2 pin: + dan − |

Warna kabel mengikuti tabel di [`../WIRING.md`](../WIRING.md):

| Warna | Sambungan |
|---|---|
| merah | 3V3 → 3.3V MFRC522 |
| hitam | GND (MFRC522, LCD, buzzer) |
| kuning | GPIO5 → SDA/SS MFRC522 |
| hijau | GPIO18 → SCK MFRC522 |
| biru langit | GPIO23 → MOSI MFRC522 |
| biru | GPIO19 → MISO MFRC522 |
| ungu | GPIO4 → RST MFRC522 |
| oranye | VIN 5V → VCC LCD |
| pink | GPIO21 → SDA LCD |
| teal | GPIO22 → SCL LCD |
| olive | GPIO27 → + buzzer |

Kabel di Fritzing selalu berupa **garis lurus** antar pin (memang begitu cara kerjanya).
Kalau ingin jalurnya lebih rapi, geser saja part-nya — kabel akan ikut menyesuaikan.

## Mengubah / membuat ulang gambar

- **Edit sketsa**: buka `.fzz`, geser part atau klik-pilih kabel lalu hapus, simpan.
- **Buat gambar baru**: setelah sketsa rapi, pilih **File → Export → as Image…**,
  lalu simpan sebagai PNG/JPG. Hasilnya sama seperti `presentech-breadboard.png`.

Tidak perlu memasang part lagi saat membuka `.fzz` di komputer lain.
