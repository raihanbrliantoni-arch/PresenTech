/*
 * PresenTech — contoh konfigurasi alat (ESP32)
 *
 * CARA PAKAI
 *   1. Salin file ini menjadi "config.h" di folder yang sama.
 *   2. Isi WIFI_SSID, WIFI_PASSWORD, API_BASE_URL, dan DEVICE_KEY.
 *   3. config.h TIDAK ikut di-commit (sudah masuk .gitignore) karena
 *      berisi password WiFi dan device key sekolah.
 *
 * Nilai WIFI_* dan API_BASE_URL di bawah hanya dipakai saat alat
 * pertama kali menyala. Setelah itu alat menyimpan konfigurasi di NVS,
 * sehingga bisa diubah lewat portal setup (tombol BOOT 3 detik) atau
 * dari dashboard Admin (Perangkat IoT -> Ganti WiFi) tanpa flash ulang.
 * Device key juga bisa diganti dari Serial Monitor: "key ptd_...".
 */

#ifndef PRESENTECH_CONFIG_H
#define PRESENTECH_CONFIG_H

// ======================= WAJIB DIISI =======================

// WiFi sekolah / hotspot. ESP32 hanya mendukung 2.4 GHz.
#define WIFI_SSID       "WIFI_SEKOLAH"
#define WIFI_PASSWORD   "password_wifi"

// Alamat server PresenTech, HARUS diakhiri "/api".
// Samakan dengan IP komputer server: http://<IP>:8000/api
// Mode hotspot Windows selalu memakai 192.168.137.1.
#define API_BASE_URL    "http://192.168.1.6:8000/api"

// Device key dari dashboard: Perangkat IoT -> Tambah -> salin ptd_...
// Satu key hanya untuk satu alat.
#define DEVICE_KEY      "ptd_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"

// ======================= IDENTITAS ALAT =======================

// Dipakai sebagai awalan client_event_id antrean offline (maks 24 karakter).
#define DEVICE_ID       "ESP32-RPL-01"

// Naikkan versi ini setiap kali firmware di-upload agar dashboard
// dan OTA bisa membedakan versi lama vs baru.
#define FIRMWARE_VERSION "1.0.0"

// Teks cadangan bila server belum pernah terhubung.
#define SCHOOL_NAME     "SMK TELESANDI"

// ======================= PIN (samakan dengan WIRING.md) =======================

// MFRC522 (SPI). SCK/MISO/MOSI memakai pin SPI bawaan ESP32.
#define PIN_RFID_SS     5
#define PIN_RFID_RST    4
#define PIN_RFID_SCK    18
#define PIN_RFID_MISO   19
#define PIN_RFID_MOSI   23

// LCD I2C 20x4 (alamat umum 0x27 atau 0x3F).
#define LCD_I2C_ADDR    0x27
#define LCD_COLS        20
#define LCD_ROWS        4

// Umpan balik suara dan lampu status.
#define PIN_BUZZER      27
#define PIN_STATUS_LED  2      // LED bawaan board DevKit
#define PIN_BOOT_BUTTON 0      // tombol BOOT bawaan board

// 1 = buzzer aktif (cukup HIGH/LOW), 0 = buzzer pasif (pakai tone()).
#define BUZZER_ACTIVE   1

// ======================= PERILAKU =======================

// Heartbeat 15 detik: server membatasi 60 request/menit per device key.
#define HEARTBEAT_MS        15000UL
#define BATCH_INTERVAL_MS   30000UL

// Jumlah tap yang bisa disimpan saat WiFi/server mati.
#define QUEUE_MAX           30

// Portal setup WiFi (dibuka dengan menahan tombol BOOT 3 detik).
#define AP_SSID         "PresenTech-Setup"
#define AP_PASSWORD     "presen123"

#endif // PRESENTECH_CONFIG_H
