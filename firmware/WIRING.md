# Wiring Alat PresenTech — ESP32 + MFRC522 + LCD 20x4

Panduan merakit perangkat pembaca kartu RFID PresenTech. Pin di bawah **sama dengan**
nilai bawaan `firmware/PresentechAlat/config.example.h`. Kalau kamu mengubah pin di
`config.h`, ubah juga tabel ini agar dokumentasi dan alat tetap cocok.

---

## 1. Daftar komponen

| No | Komponen | Jumlah | Catatan |
|---|---|---|---|
| 1 | ESP32 DevKit V1 (30/38 pin, WROOM-32) | 1 | Board dengan regulator 3.3V onboard. |
| 2 | Modul MFRC522 13.56 MHz | 1 | Sudah termasuk header; **wajib 3.3V**. |
| 3 | Kartu RFID Mifare Classic 13.56 MHz | 1+ | Untuk uji tap dan siswa. |
| 4 | LCD karakter 20x4 + modul I2C PCF8574 | 1 | Alamat umum `0x27` atau `0x3F`. |
| 5 | Buzzer aktif 3.3–5V (modul) | 1 | Umpan balik berhasil/gagal. |
| 6 | Breadboard / PCB + kabel jumper | secukupnya | Kabel SPI usahakan < 20 cm. |
| 7 | Adaptor 5V ≥ 2A + jack | 1 | LCD backlight + ESP32 butuh arus cukup. |
| 8 | Level shifter I2C 4 kanal (opsional, disarankan) | 1 | Melindungi GPIO21/22 saat LCD diberi 5V. |
| 9 | Transistor NPN 2N2222 + resistor 1kΩ (opsional) | 1 | Bila memakai buzzer pasif/5V besar. |

---

## 2. Diagram sambungan

```html
<p align="center">
  <a href="wiring/presentech-wiring.svg">
    <img src="wiring/presentech-wiring.png" width="960"
         alt="Diagram wiring PresenTech: ESP32, MFRC522, LCD I2C 20x4, dan buzzer">
  </a>
</p>
```

Warna kabel pada gambar **hanya saran** agar mudah ditelusuri; yang wajib cocok adalah
**label GPIO** dan **tegangannya** (MFRC522 3,3 V, LCD 5 V). Titik hitam menandakan
sambungan GND yang menyatu. Klik gambar untuk membuka versi SVG (bisa di-zoom tanpa pecah).

### Tampak breadboard (gaya Fritzing)

Pandangan kedua: bentuk fisik modul dan jalur kabel, mirip tampilan **breadboard view**
di Fritzing — cocok untuk pegangan saat merakit.

```html
<p align="center">
  <a href="wiring/presentech-wiring-breadboard.svg">
    <img src="wiring/presentech-wiring-breadboard.png" width="980"
         alt="Tampak breadboard gaya Fritzing: ESP32 DevKit V1, MFRC522, LCD 20x4 I2C, buzzer">
  </a>
</p>
```

Kabel pada gambar dibuat **menumpuk di atas modul** seperti aslinya, jadi ada jalur yang
melintasi board. Yang perlu ditiru hanya **titik masuk dan keluar** tiap kabel.

### Tampak pin ESP32 (board 30 pin)

```
                     USB (sisi bawah)
          ┌─────────────────────────────┐
   3V3    ┤ 1                        30 ├  VIN (5V)
   EN     ┤ 2                        29 ├  GND
   G36    ┤ 3                        28 ├  G15
   G39    ┤ 4                        27 ├  G2
   G34    ┤ 5                        26 ├  G4    → RST MFRC522
   G35    ┤ 6                        25 ├  G16
   G32    ┤ 7                        24 ├  G17
   G33    ┤ 8                        23 ├  G5    → SDA/SS MFRC522
   G25    ┤ 9                        22 ├  G18   → SCK MFRC522
   G26    ┤ 10                       21 ├  G19   → MISO MFRC522
   G27    ┤ 11                       20 ├  G21   → SDA LCD
   G14    ┤ 12                       19 ├  G3 (RX0)
   G12    ┤ 13                       18 ├  G1 (TX0)
   G13    ┤ 14                       17 ├  G22   → SCL LCD
   GND    ┤ 15                       16 ├  G23   → MOSI MFRC522
          └─────────────────────────────┘
```

Nomor pin fisik bisa berbeda antar board; **selalu ikuti label silkscreen**, bukan
nomor urut di gambar. Pada board 30 pin, **GPIO0** (tombol BOOT) dan **GPIO2** (LED)
tidak dibawa ke header — keduanya sudah ada di board.

> Board 38 pin punya tata letak berbeda dan GPIO0/GPIO2 ikut di header. Firmware tetap
> bisa dipakai, cukup sesuaikan pin di `config.h` bila silkscreen-nya lain.

---

## 3. Tabel pin lengkap

### MFRC522 (SPI)

| Pin modul | Pin ESP32 | Warna saran | Keterangan |
|---|---|---|---|
| SDA / SS | **GPIO5** | kuning | Chip select, aktif LOW. |
| SCK | **GPIO18** | hijau | SPI clock. |
| MOSI | **GPIO23** | biru muda | Data ESP32 → modul. |
| MISO | **GPIO19** | biru | Data modul → ESP32. |
| IRQ | — | — | Tidak dipakai firmware. |
| GND | **GND** | hitam | Ground bersama. |
| RST | **GPIO4** | ungu | Reset modul. |
| 3.3V | **3V3** | merah | **Jangan 5V** — modul rusak. |

### LCD 20x4 I2C

| Pin modul | Pin ESP32 | Warna saran | Keterangan |
|---|---|---|---|
| GND | **GND** | hitam | Ground bersama. |
| VCC | **VIN / 5V** | oranye | Backlight butuh 5V. |
| SDA | **GPIO21** | merah muda | I2C data (butuh level shifter bila VCC 5V). |
| SCL | **GPIO22** | hijau toska | I2C clock (idem). |

### Buzzer & tombol

| Komponen | Pin ESP32 | Warna saran | Keterangan |
|---|---|---|---|
| Buzzer aktif `+` | **GPIO27** | hijau zaitun | `BUZZER_ACTIVE 1` di `config.h`. |
| Buzzer aktif `−` | GND | hitam | Buzzer pasif: pakai `tone()`, set `BUZZER_ACTIVE 0`. |
| Tombol BOOT | **GPIO0 → GND** | Sudah ada di board; `INPUT_PULLUP` internal. |
| LED status | **GPIO2** | LED bawaan board (aktif HIGH). |

Bila memakai buzzer 5V berarus > 20 mA, jangan dihubungkan langsung:

```
GPIO27 ──[ 1kΩ ]── B (2N2222)
                    C ── buzzer (−)
                    E ── GND
buzzer (+) ── 5V
```

---

## 4. Daya dan keamanan (penting)

1. **MFRC522 hanya 3.3V.** Memberi 5V dapat langsung merusak chip.
2. **LCD 20x4 umumnya butuh 5V.** Modul I2C PCF8574 sering memiliki pull-up
   SDA/SCL ke VCC. Bila VCC = 5V, pin GPIO21/22 akan menerima 5V saat idle —
   ESP32 tidak 5V-tolerant. Pilihan:
   - **Disarankan:** pasang level shifter I2C 4 kanal (HV = 5V, LV = 3.3V,
     GND bersama) untuk SDA & SCL.
   - **Praktik cepat di lab:** sambung langsung, banyak modul tetap bekerja, tetapi
     ini di luar spesifikasi dan berisiko jangka panjang. Kalau memilih cara ini,
     jangan tinggalkan alat menyala berhari-hari tanpa pengawasan.
   - Alternatif: coba beri VCC LCD 3.3V. Sebagian modul tetap tampil (backlight
     lebih redup, kontras perlu diputar).
3. **Ground harus bersama** untuk ESP32, MFRC522, LCD, dan catu daya.
4. **Arus:** ESP32 bisa menarik ±500 mA saat WiFi transmit. Pakai adaptor 5V ≥ 2A;
   jangan memberi daya MFRC522 dari pin 3V3 board bila board juga menyalakan LCD
   lewat USB — gejalanya kartu kadang tidak terbaca.
5. **Pin yang dihindari:** GPIO6–11 (flash internal), GPIO12 (strapping, bisa gagal
   boot), GPIO34–39 (input saja), GPIO1/3 (Serial monitor).
6. **Kabel SPI pendek.** Di atas ±30 cm, MFRC522 sering gagal inisialisasi.
7. **Antena RFID** jangan menempel logam. Jarak baca normal 1–3 cm; arahkan kumparan
   sejajar kartu.

---

## 5. Langkah perakitan

1. **Cek alamat LCD.** Sambungkan LCD (GND, VCC, SDA, SCL) lalu jalankan sketch
   I2C scanner berikut. Catat alamat yang muncul (`0x27` atau `0x3F`), lalu samakan
   `LCD_I2C_ADDR` di `config.h`.

   ```cpp
   #include <Wire.h>

   void setup() {
     Wire.begin(21, 22);
     Serial.begin(115200);
     for (byte address = 1; address < 127; address++) {
       Wire.beginTransmission(address);
       if (Wire.endTransmission() == 0) {
         Serial.printf("I2C ditemukan: 0x%02X\n", address);
       }
     }
   }

   void loop() {}
   ```

2. **Pasang MFRC522** sesuai tabel pin. Periksa ulang 3.3V dan GND sebelum menyalakan.
3. **Pasang buzzer** ke GPIO27 (dan transistor bila perlu).
4. **Pasang tombol** bila board belum punya tombol BOOT; sambungkan GPIO0 ke GND
   lewat tombol.
5. **Nyalakan alat** dengan adaptor 5V. LCD harus menampilkan `PRESENTECH` lalu
   `MENYAMBUNG WIFI` / `SETUP WIFI`.
6. **Upload firmware** (`firmware/PresentechAlat`) sesuai panduan di
   [`firmware/README.md`](README.md).
7. **Daftarkan alat** di dashboard Admin → Perangkat IoT → Tambah. Salin device key
   `ptd_...` ke `config.h`, lalu upload ulang.
8. **Tempel kartu** yang sudah didaftarkan di menu Kartu RFID. LCD harus menampilkan
   nama siswa dan status.

---

## 6. Uji cepat setelah dirakit

| Uji | Cara | Hasil yang benar |
|---|---|---|
| LCD hidup | Nyalakan alat | Baris `PRESENTECH` muncul, backlight menyala. |
| RFID terdeteksi | Serial Monitor 115200, tempel kartu | Muncul `Tap kartu: XXXXXXXXXX`. |
| Buzzer | Kirim `beep` di Serial Monitor | Berbunyi 2 kali. |
| WiFi | Serial Monitor | `WiFi : <SSID> \| TERHUBUNG` dan ada IP alat. |
| Server | Kirim `status` | `Server : http://.../api [OK]`. |
| Heartbeat | Dashboard → Perangkat IoT | Status alat berubah **Online**. |
| Absensi | Tempel kartu terdaftar | LCD `ABSENSI BERHASIL` + nama + status. |

---

## 7. Bila ada masalah

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| LCD menyala tapi kosong | Alamat I2C salah / kontras | Jalankan I2C scanner, putar potensiometer kontras. |
| LCD tampil blok hitam | Kontras terlalu tinggi | Putar potensiometer ke arah sebaliknya. |
| Kartu tidak terbaca | MFRC522 dapat 5V, kabel SPI panjang, atau daya kurang | Cek 3.3V, pendekkan kabel, pakai adaptor 2A. |
| Alat restart saat WiFi connect | Catu daya lemah | Ganti adaptor; tambah kapasitor 470 µF di 5V–GND. |
| Buzzer tidak bunyi | Buzzer pasif dianggap aktif | Set `BUZZER_ACTIVE 0` atau ganti buzzer aktif. |
| Status tetap Offline | IP server salah / beda jaringan | Cek `{API}/health` dari HP, perbaiki `API_BASE_URL`. |
| Device key salah (401) | Key ter-rotate / ada spasi | Salin ulang key atau `key ptd_...` lewat Serial. |

Wiring ini sudah dipakai firmware di repo ini; kalau pin diubah, ubah `config.h`
dan tabel di dokumen ini sekaligus.

---

## 8. Mengubah diagram

Ada dua sumber gambar di folder [`wiring/`](wiring/):

| SVG | Isi | Ukuran PNG |
|---|---|---|
| `presentech-wiring-breadboard.svg` | tampak breadboard (gaya Fritzing) | 2800×1800 |
| `presentech-wiring.svg` | diagram skematik berwarna | 2400×1760 |

Setelah mengedit SVG, render ulang PNG-nya agar preview di GitHub ikut berubah:

```powershell
# dari folder firmware/wiring
& "C:\Program Files\Google\Chrome\Application\chrome.exe" `
  --headless=new --disable-gpu --hide-scrollbars `
  --force-device-scale-factor=2 --window-size=1400,900 `
  --screenshot=presentech-wiring-breadboard.png presentech-wiring-breadboard.svg

& "C:\Program Files\Google\Chrome\Application\chrome.exe" `
  --headless=new --disable-gpu --hide-scrollbars `
  --force-device-scale-factor=2 --window-size=1200,880 `
  --screenshot=presentech-wiring.png presentech-wiring.svg
```

PNG dibuat 2x supaya tetap tajam di layar Retina/HP.

### Soal file `.fzz` Fritzing

Fritzing **tidak** punya part ESP32, MFRC522, maupun LCD 20x4 I2C di library resminya
(`fritzing-parts`), dan aplikasi Fritzing 1.0.8 berbayar. Karena itu repo ini memakai
gambar SVG/PNG di atas, bukan sketsa `.fzz`. Kalau tetap ingin menggambar di Fritzing,
part yang perlu diunduh dulu:

- **ESP32 DevKit V1** — part komunitas (forum Fritzing / SparkFun ESP32 Thing).
- **RFID RC522** — part komunitas "RFID-RC522".
- **LCD 20x4 I2C** — part komunitas "LCD 20x4 with I2C backpack".
- **Buzzer** — sudah ada di library inti (`Buzzer`).
