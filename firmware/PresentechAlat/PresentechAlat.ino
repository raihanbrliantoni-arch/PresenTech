/*
 * ============================================================================
 *  PresenTech — Firmware Alat Absensi RFID
 *  ESP32 + MFRC522 + LCD I2C 20x4 + buzzer
 * ============================================================================
 *
 *  Alur kerja
 *    1. Menyambung WiFi (kredensial dari config.h / NVS / portal setup).
 *    2. Mengirim heartbeat tiap 15 detik ke POST /api/device/heartbeat.
 *       Balasannya dipakai untuk: jam & jam masuk/pulang di LCD, nama alat,
 *       perintah restart, mode baca UID, ganti WiFi, dan OTA.
 *    3. Tap kartu -> POST /api/attendance -> tampilkan nama/kelas/status.
 *    4. Bila WiFi/server mati, tap disimpan di NVS lalu dikirim lewat
 *       POST /api/device/attendance/batch saat kembali online.
 *
 *  Wiring lengkap: ../WIRING.md
 *  Cara upload & konfigurasi: ../README.md
 *
 *  Library (Library Manager Arduino IDE):
 *    - MFRC522              (GithubCommunity)
 *    - ArduinoJson          (Benoit Blanchon)   v6 atau v7
 *    - LiquidCrystal I2C    (Frank de Brabander)
 *
 *  Board: "ESP32 Dev Module" — Upload Speed 921600 — Flash 4MB.
 * ============================================================================
 */

#include <WiFi.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <SPI.h>
#include <Wire.h>
#include <MFRC522.h>
#include <LiquidCrystal_I2C.h>
#include <ArduinoJson.h>
#include <Update.h>
#include <mbedtls/sha256.h>
#include <esp_system.h>
#include <time.h>

#if __has_include("config.h")
#include "config.h"
#else
#error "config.h belum ada. Salin config.example.h menjadi config.h lalu isi WiFi, API_BASE_URL, dan DEVICE_KEY."
#endif

// ---------------------------------------------------------------------------
// Kompatibilitas ArduinoJson v6 dan v7
// ---------------------------------------------------------------------------
#if ARDUINOJSON_VERSION_MAJOR >= 7
using JsonDoc = JsonDocument;
#else
using JsonDoc = StaticJsonDocument<8192>;
#endif

// ---------------------------------------------------------------------------
// Objek global
// ---------------------------------------------------------------------------
LiquidCrystal_I2C lcd(LCD_I2C_ADDR, LCD_COLS, LCD_ROWS);
MFRC522 rfid(PIN_RFID_SS, PIN_RFID_RST);
WebServer portalServer(80);
Preferences prefs;       // namespace "presentech": ssid, pass, api, key, token
Preferences queuePrefs;  // namespace "ptqueue": antrean tap offline

// Konfigurasi aktif (bisa berubah tanpa flash ulang)
String cfgSsid = WIFI_SSID;
String cfgPass = WIFI_PASSWORD;
String cfgApi = API_BASE_URL;
String cfgKey = DEVICE_KEY;

char bootId[9] = {0};

// Data runtime dari server
struct RuntimeData {
  String schoolLabel = SCHOOL_NAME;
  String deviceName = DEVICE_ID;
  String deviceShort = DEVICE_ID;
  String entryTime = "";
  String homeTime = "";
  String clock = "";
  String state = "";
} runtime;

// Isi LCD terakhir (agar tidak menulis ulang baris yang sama)
String lcdCache[LCD_ROWS];

// Layar pesan sementara
bool alertActive = false;
unsigned long alertUntil = 0;

// Mode baca UID dari dashboard
bool scanMode = false;
unsigned long scanDeadline = 0;

// Antrean tap offline
struct PendingTap {
  String eventId;
  String uid;
  String tappedAt;
  bool verified;
};
PendingTap pending[QUEUE_MAX];
int pendingCount = 0;
uint32_t eventCounter = 0;

// Status jaringan & timing
bool portalActive = false;
bool portalTried = false;
unsigned long portalStartedAt = 0;
bool serverReachable = false;
int lastHeartbeatCode = 0;
unsigned long lastHeartbeatAt = 0;
unsigned long lastBatchAt = 0;
unsigned long lastWifiAttemptAt = 0;
unsigned long bootPressedAt = 0;
bool ntpStarted = false;

// Anti-tap ganda
String lastUid = "";
unsigned long lastUidAt = 0;

// ===========================================================================
//  SETUP
// ===========================================================================
void setup() {
  Serial.begin(115200);
  delay(150);

  pinMode(PIN_BUZZER, OUTPUT);
  digitalWrite(PIN_BUZZER, LOW);
  pinMode(PIN_STATUS_LED, OUTPUT);
  digitalWrite(PIN_STATUS_LED, LOW);
  pinMode(PIN_BOOT_BUTTON, INPUT_PULLUP);

  Serial.println();
  Serial.println("==============================================");
  Serial.println(" PresenTech alat absensi - firmware " FIRMWARE_VERSION);
  Serial.println("==============================================");

  lcd.init();
  lcd.backlight();
  for (int i = 0; i < LCD_ROWS; i++) {
    lcdCache[i] = "~";
  }
  lcdShow("PRESENTECH", "Firmware " FIRMWARE_VERSION, "Menyiapkan alat...", "");

  SPI.begin(PIN_RFID_SCK, PIN_RFID_MISO, PIN_RFID_MOSI, PIN_RFID_SS);
  rfid.PCD_Init();
  delay(50);

  prefs.begin("presentech", false);
  cfgSsid = prefs.getString("ssid", WIFI_SSID);
  cfgPass = prefs.getString("pass", WIFI_PASSWORD);
  cfgApi = normalizeApi(prefs.getString("api", API_BASE_URL));
  cfgKey = prefs.getString("key", DEVICE_KEY);

  queuePrefs.begin("ptqueue", false);
  loadQueue();

  snprintf(bootId, sizeof(bootId), "%08x", (unsigned int)esp_random());
  Serial.println("Boot ID : " + String(bootId));
  Serial.println("Device  : " + String(DEVICE_ID));
  Serial.println("Server  : " + cfgApi);
  Serial.println("Antrean : " + String(pendingCount) + " tap");

  beep(1, 60, 0);

  if (cfgSsid.length() == 0) {
    startPortal();
    return;
  }

  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.begin(cfgSsid.c_str(), cfgPass.c_str());
  lastWifiAttemptAt = millis();

  lcdShow("MENYAMBUNG WIFI", cfgSsid, "Mohon tunggu...", "");
  printHelp();
}

// ===========================================================================
//  LOOP UTAMA
// ===========================================================================
void loop() {
  if (portalActive) {
    portalServer.handleClient();
    checkBootButton();
    handleSerial();
    if (millis() - portalStartedAt > 600000UL) {
      Serial.println("Portal timeout, restart ke mode normal.");
      ESP.restart();
    }
    return;
  }

  checkBootButton();
  handleSerial();
  ensureWifi();
  updateStatusLed();

  handleRfid();

  if (scanMode && millis() > scanDeadline) {
    scanMode = false;
    beep(1, 350, 0);
    showScreen("SCAN TIMEOUT", "TIDAK ADA KARTU", "ULANGI DARI WEB", "", 4500);
  }

  if (millis() - lastHeartbeatAt >= HEARTBEAT_MS) {
    lastHeartbeatAt = millis();
    sendHeartbeat();
  }

  if (millis() - lastBatchAt >= BATCH_INTERVAL_MS) {
    lastBatchAt = millis();
    sendBatch();
  }

  if (alertActive && millis() > alertUntil) {
    alertActive = false;
  }

  if (!alertActive) {
    drawIdle();
  }

  delay(10);
}

// ===========================================================================
//  WIFI
// ===========================================================================
void ensureWifi() {
  if (WiFi.status() == WL_CONNECTED) {
    if (!ntpStarted) {
      ntpStarted = true;
      configTime(0, 0, "pool.ntp.org", "time.google.com");
      Serial.println("NTP dimulai (UTC).");
    }
    return;
  }

  serverReachable = false;

  if (millis() - lastWifiAttemptAt >= 15000UL) {
    lastWifiAttemptAt = millis();
    Serial.println("Menyambung WiFi: " + cfgSsid);
    WiFi.disconnect();
    WiFi.begin(cfgSsid.c_str(), cfgPass.c_str());
  }

  if (!portalTried && millis() > 30000UL) {
    portalTried = true;
    startPortal();
  }
}

void updateStatusLed() {
  unsigned long period = (WiFi.status() == WL_CONNECTED) ? 2000UL : 300UL;
  bool on = (millis() % period) < (period / 2UL);
  digitalWrite(PIN_STATUS_LED, on ? HIGH : LOW);
}

// ===========================================================================
//  PORTAL SETUP WIFI (tanpa flash ulang)
// ===========================================================================
void startPortal() {
  if (portalActive) {
    return;
  }

  portalActive = true;
  portalStartedAt = millis();

  WiFi.disconnect(true);
  WiFi.mode(WIFI_AP);
  WiFi.softAP(AP_SSID, AP_PASSWORD);

  portalServer.on("/", HTTP_GET, handlePortalRoot);
  portalServer.on("/save", HTTP_POST, handlePortalSave);
  portalServer.on("/restart", HTTP_GET, handlePortalRestart);
  portalServer.onNotFound(handlePortalRoot);
  portalServer.begin();

  Serial.println();
  Serial.println("=== PORTAL SETUP WIFI AKTIF ===");
  Serial.println("SSID   : " AP_SSID);
  Serial.println("Sandi  : " AP_PASSWORD);
  Serial.println("Buka   : http://192.168.4.1");
  Serial.println("===============================");

  lcdShow("SETUP WIFI", AP_SSID, "SANDI: " AP_PASSWORD, "BUKA 192.168.4.1");
  beep(2, 80, 60);
}

void handlePortalRoot() {
  portalServer.send(200, "text/html", portalPage());
}

void handlePortalRestart() {
  portalServer.send(200, "text/html",
                    "<meta charset='utf-8'><p>Mencoba menyambung ulang...</p>");
  delay(500);
  ESP.restart();
}

void handlePortalSave() {
  String ssid = portalServer.arg("ssid");
  String pass = portalServer.arg("password");
  String api = portalServer.arg("server");

  ssid.trim();
  api.trim();

  if (ssid.length() == 0) {
    portalServer.send(400, "text/html",
                      "<meta charset='utf-8'><p>Nama WiFi (SSID) wajib diisi.</p>");
    return;
  }

  if (api.length() == 0) {
    api = cfgApi;
  }
  api = normalizeApi(api);

  prefs.putString("ssid", ssid);
  prefs.putString("pass", pass);
  prefs.putString("api", api);

  portalServer.send(200, "text/html",
                    "<meta charset='utf-8'><h3>Tersimpan. Alat restart...</h3>");

  Serial.println("Konfigurasi portal disimpan. SSID: " + ssid + " | Server: " + api);
  delay(1000);
  ESP.restart();
}

String portalPage() {
  String html = F(
      "<!doctype html><html lang='id'><head><meta charset='utf-8'>"
      "<meta name='viewport' content='width=device-width,initial-scale=1'>"
      "<title>PresenTech Setup</title><style>"
      "body{font-family:system-ui,sans-serif;background:#0d1117;color:#e6edf3;"
      "margin:0;padding:24px}main{max-width:420px;margin:0 auto}"
      "h1{font-size:20px;margin:0 0 4px}p.sub{color:#9aa7b4;margin:0 0 20px;font-size:13px}"
      "label{display:block;font-size:13px;margin:14px 0 6px;color:#9aa7b4}"
      "input{width:100%;box-sizing:border-box;padding:11px;border-radius:8px;"
      "border:1px solid #30363d;background:#161b22;color:#e6edf3;font-size:15px}"
      "button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:8px;"
      "background:#1f6feb;color:#fff;font-size:15px;font-weight:600}"
      "a{display:block;text-align:center;margin-top:16px;color:#9aa7b4;font-size:13px}"
      ".note{margin-top:18px;padding:12px;border-radius:8px;background:#161b22;"
      "border:1px solid #30363d;font-size:12px;color:#9aa7b4;line-height:1.5}"
      "</style></head><body><main>"
      "<h1>PresenTech Setup</h1>"
      "<p class='sub'>Isi WiFi sekolah dan alamat server, lalu simpan.</p>"
      "<form method='post' action='/save'>"
      "<label>Nama WiFi (SSID)</label>"
      "<input name='ssid' value='");
  html += cfgSsid;
  html += F("' required>"
            "<label>Password WiFi</label>"
            "<input name='password' type='password' value=''>"
            "<label>URL Server</label>"
            "<input name='server' value='");
  html += cfgApi;
  html += F("'>"
            "<button type='submit'>SIMPAN &amp; RESTART</button>"
            "</form>"
            "<div class='note'>Contoh URL server: <b>http://192.168.137.1:8000/api</b>"
            " (mode hotspot Windows). ESP32 hanya bisa WiFi 2.4 GHz.</div>"
            "<a href='/restart'>Batal, coba WiFi lama</a>"
            "</main></body></html>");
  return html;
}

// ===========================================================================
//  TOMBOL BOOT + SERIAL
// ===========================================================================
void checkBootButton() {
  if (digitalRead(PIN_BOOT_BUTTON) == LOW) {
    if (bootPressedAt == 0) {
      bootPressedAt = millis();
    } else if (!portalActive && millis() - bootPressedAt > 3000UL) {
      startPortal();
    }
  } else {
    bootPressedAt = 0;
  }
}

void handleSerial() {
  static String buffer = "";

  while (Serial.available() > 0) {
    char c = (char)Serial.read();
    if (c == '\n' || c == '\r') {
      if (buffer.length() > 0) {
        processCommand(buffer);
        buffer = "";
      }
    } else if (buffer.length() < 120) {
      buffer += c;
    }
  }
}

void processCommand(String line) {
  line.trim();
  if (line.length() == 0) {
    return;
  }

  int space = line.indexOf(' ');
  String cmd = (space < 0) ? line : line.substring(0, space);
  String arg = (space < 0) ? "" : line.substring(space + 1);
  cmd.toLowerCase();
  arg.trim();

  if (cmd == "help") {
    printHelp();
  } else if (cmd == "portal") {
    startPortal();
  } else if (cmd == "status") {
    printStatus();
  } else if (cmd == "beep") {
    beep(2, 80, 60);
  } else if (cmd == "srv") {
    if (arg.length() == 0) {
      Serial.println("Pakai: srv http://192.168.1.6:8000/api");
      return;
    }
    cfgApi = normalizeApi(arg);
    prefs.putString("api", cfgApi);
    serverReachable = false;
    lastHeartbeatAt = 0;
    Serial.println("Server disimpan: " + cfgApi);
  } else if (cmd == "key") {
    if (arg.length() == 0) {
      Serial.println("Pakai: key ptd_...");
      return;
    }
    cfgKey = arg;
    prefs.putString("key", cfgKey);
    Serial.println("Device key disimpan. Heartbeat berikutnya memakai key baru.");
  } else if (cmd == "otareset") {
    prefs.remove("otaFailedVer");
    Serial.println("Penanda OTA gagal dihapus.");
  } else if (cmd == "factory") {
    prefs.clear();
    queuePrefs.clear();
    Serial.println("Semua konfigurasi & antrean dihapus. Restart...");
    delay(600);
    ESP.restart();
  } else {
    Serial.println("Perintah tidak dikenal. Ketik: help");
  }
}

void printHelp() {
  Serial.println();
  Serial.println("Perintah Serial Monitor (115200):");
  Serial.println("  status  - info WiFi, server, antrean, heap");
  Serial.println("  portal  - buka portal setup WiFi (192.168.4.1)");
  Serial.println("  srv <url> - ganti URL server, mis. srv http://192.168.1.6:8000/api");
  Serial.println("  key <ptd_...> - ganti device key tanpa flash ulang");
  Serial.println("  beep    - tes buzzer");
  Serial.println("  otareset - izinkan ulang OTA versi yang pernah gagal");
  Serial.println("  factory - hapus semua konfigurasi & antrean, restart");
  Serial.println("  help    - tampilkan bantuan ini");
  Serial.println();
}

void printStatus() {
  Serial.println();
  Serial.println("--- STATUS ALAT ---");
  Serial.println("Firmware   : " FIRMWARE_VERSION " (" + String(bootId) + ")");
  Serial.println("Device ID  : " + String(DEVICE_ID));
  Serial.println("WiFi       : " + cfgSsid + " | " +
                 (WiFi.status() == WL_CONNECTED ? "TERHUBUNG" : "TERPUTUS"));
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("IP alat    : " + WiFi.localIP().toString() + " (" +
                   String(WiFi.RSSI()) + " dBm)");
  }
  Serial.println("Server     : " + cfgApi + (serverReachable ? " [OK]" : " [BELUM]"));
  Serial.println("Heartbeat  : HTTP " + String(lastHeartbeatCode));
  Serial.println("Device key : " + maskKey(cfgKey));
  Serial.println("Antrean    : " + String(pendingCount) + "/" + String(QUEUE_MAX));
  Serial.println("Mode scan  : " + String(scanMode ? "AKTIF" : "nonaktif"));
  Serial.println("Heap bebas : " + String(ESP.getFreeHeap()) + " byte");
  Serial.println("-------------------");
  Serial.println();
}

String maskKey(const String& key) {
  if (key.length() <= 12) {
    return key;
  }

  return key.substring(0, 8) + "..." + key.substring(key.length() - 4);
}

String normalizeApi(String url) {
  url.trim();

  while (url.endsWith("/")) {
    url.remove(url.length() - 1);
  }

  return url;
}

// ===========================================================================
//  HEARTBEAT
// ===========================================================================
void sendHeartbeat() {
  if (WiFi.status() != WL_CONNECTED) {
    serverReachable = false;
    return;
  }

  JsonDoc req;
  req["ip_address"] = WiFi.localIP().toString();
  req["firmware_version"] = FIRMWARE_VERSION;
  req["boot_id"] = bootId;
  req["firmware_hash"] = ESP.getSketchMD5();

  const char* capabilities[] = {
      "display_text", "display_theme", "boot_id", "firmware_hash",
      "attendance_window", "ota", "scan", "offline_queue", "wifi_config"};
  for (size_t i = 0; i < sizeof(capabilities) / sizeof(capabilities[0]); i++) {
    req["capabilities"][i] = capabilities[i];
  }

  String body;
  serializeJson(req, body);

  String response;
  int code = postJson("/device/heartbeat", body, response, 9000);
  lastHeartbeatCode = code;

  if (code != 200) {
    serverReachable = false;

    if (code == 401) {
      alertMessage("DEVICE KEY SALAH", "PERIKSA CONFIG.H", "ATAU ROTATE KEY", 15000);
    } else if (code == 429) {
      Serial.println("Heartbeat: rate limit (429). Perpanjang interval.");
    } else {
      Serial.printf("Heartbeat gagal: HTTP %d\n", code);
    }

    return;
  }

  serverReachable = true;

  JsonDoc doc;
  if (deserializeJson(doc, response) != DeserializationError::Ok) {
    Serial.println("Heartbeat: JSON tidak valid.");
    return;
  }

  applyWindow(doc.as<JsonVariant>());
  applyDisplay(doc.as<JsonVariant>());

  // --- Ganti WiFi dikirim dari dashboard -------------------------------
  String setupSsid = doc["wifi_setup_ssid"] | "";
  String setupPass = doc["wifi_setup_password"] | "";
  String setupToken = doc["wifi_setup_token"] | "";

  if (setupToken.length() > 0 && setupSsid.length() > 0) {
    bool alreadyApplied = prefs.getString("wifiToken", "") == setupToken;

    prefs.putString("ssid", setupSsid);
    prefs.putString("pass", setupPass);
    prefs.putString("wifiToken", setupToken);

    JsonDoc ackReq;
    ackReq["token"] = setupToken;
    String ackBody;
    serializeJson(ackReq, ackBody);
    String ackResponse;
    int ackCode = postJson("/device/wifi-ack", ackBody, ackResponse, 8000);

    if (ackCode == 200) {
      prefs.putString("wifiToken", "");
      Serial.println("WiFi baru dikonfirmasi ke server.");
    }

    if (!alreadyApplied) {
      lcdShow("GANTI WIFI", setupSsid, "RESTART ALAT...", "");
      beep(2, 80, 60);
      delay(1200);
      ESP.restart();
    }
  }

  // --- Restart dari dashboard ------------------------------------------
  if (doc["restart_requested"] | false) {
    String ackResponse;
    postJson("/device/restart-ack", "{}", ackResponse, 8000);
    lcdShow("RESTART DIMINTA", "DARI DASHBOARD", "", "");
    delay(500);
    ESP.restart();
  }

  // --- Mode baca UID ----------------------------------------------------
  bool scanRequested = doc["scan_requested"] | false;
  int expiresIn = doc["scan_expires_in"] | 0;

  if (scanRequested && !scanMode) {
    if (expiresIn <= 0 || expiresIn > 120) {
      expiresIn = 60;
    }
    scanMode = true;
    scanDeadline = millis() + (unsigned long)expiresIn * 1000UL;
    beep(1, 90, 0);
    Serial.println("Mode baca UID aktif selama " + String(expiresIn) + " detik.");
  } else if (!scanRequested && scanMode) {
    scanMode = false;
    showScreen("SCAN DIBATALKAN", "KEMBALI KE MODE", "ABSENSI", "", 3500);
  }

  // --- OTA --------------------------------------------------------------
  JsonVariant ota = doc["ota"];
  if (!ota.isNull()) {
    handleOta(ota);
  }
}

void applyWindow(JsonVariant doc) {
  String entry = doc["entry_time"] | "";
  String home = doc["home_time"] | "";
  String clock = doc["display"]["clock"] | "";
  String state = doc["display"]["state"] | "";

  if (entry.length() > 0) {
    runtime.entryTime = entry;
  }
  if (home.length() > 0) {
    runtime.homeTime = home;
  }
  if (clock.length() > 0) {
    runtime.clock = clock;
  }
  if (state.length() > 0) {
    runtime.state = state;
  }
}

void applyDisplay(JsonVariant doc) {
  String school = doc["display"]["school_label"] | "";
  String name = doc["display"]["device_name"] | "";
  String shortName = doc["display"]["device_name_short"] | "";

  if (school.length() > 0) {
    runtime.schoolLabel = school;
  }

  if (name.length() == 0) {
    name = shortName;
  }
  if (shortName.length() == 0) {
    shortName = name;
  }

  if (name.length() > 0) {
    runtime.deviceName = name;
    runtime.deviceShort = (name.length() <= (int)LCD_COLS) ? name : shortName;
  }
}

// ===========================================================================
//  RFID
// ===========================================================================
void handleRfid() {
  bool cardPresent = rfid.PICC_IsNewCardPresent();

  // Kartu yang masih tertinggal di reader setelah tap berada di state HALT.
  // Saat mode baca UID, bangunkan dengan WUPA agar bisa dibaca lagi.
  if (!cardPresent && scanMode) {
    byte atqa[2];
    byte atqaSize = sizeof(atqa);
    if (rfid.PICC_WakeupA(atqa, &atqaSize) == MFRC522::STATUS_OK) {
      cardPresent = true;
    }
  }

  if (!cardPresent) {
    return;
  }

  if (!rfid.PICC_ReadCardSerial()) {
    return;
  }

  String uid = readUidHex();
  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();

  if (uid == lastUid && millis() - lastUidAt < 3000UL) {
    return;
  }
  lastUid = uid;
  lastUidAt = millis();

  if (scanMode) {
    sendScanResult(uid);
  } else {
    sendTap(uid);
  }
}

String readUidHex() {
  String uid = "";

  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10) {
      uid += '0';
    }
    uid += String(rfid.uid.uidByte[i], HEX);
  }

  uid.toUpperCase();
  return uid;
}

void sendScanResult(const String& uid) {
  scanMode = false;

  JsonDoc req;
  req["uid"] = uid;
  String body;
  serializeJson(req, body);

  String response;
  int code = postJson("/device/scan-result", body, response, 8000);
  Serial.printf("Scan UID %s -> HTTP %d\n", uid.c_str(), code);

  if (code == 200) {
    showScreen("UID TERBACA", uid, "TERKIRIM KE WEB", "ABSENSI AKTIF", 6000);
    beep(2, 70, 60);
  } else {
    showScreen("UID TERBACA", uid, "GAGAL DIKIRIM", "COBA LAGI DARI WEB", 6000);
    beep(1, 350, 0);
  }
}

void sendTap(const String& uid) {
  Serial.println("Tap kartu: " + uid);
  lcdShow("MEMBACA KARTU", uid, "MOHON TUNGGU...", "");

  if (WiFi.status() != WL_CONNECTED) {
    enqueueTap(uid);
    alertMessage("WIFI TERPUTUS", "TAP DISIMPAN", "KIRIM OTOMATIS NANTI", 4000);
    beep(2, 90, 80);
    return;
  }

  JsonDoc req;
  req["uid"] = uid;
  String body;
  serializeJson(req, body);

  String response;
  int code = postJson("/attendance", body, response, 9000);

  if (code <= 0) {
    enqueueTap(uid);
    alertMessage("SERVER OFFLINE", "TAP DISIMPAN", "KIRIM OTOMATIS NANTI", 4000);
    beep(2, 90, 80);
    return;
  }

  JsonDoc doc;
  DeserializationError err = deserializeJson(doc, response);
  String message = err ? "Respons server tidak dikenali" : String((const char*)(doc["message"] | ""));

  if (code == 201 || code == 200) {
    String student = doc["display"]["student_name"] | "";
    String className = doc["display"]["class_name"] | "";
    String status = doc["display"]["status"] | "";
    String time = doc["display"]["time"] | "";

    if (student.length() == 0) {
      student = message;
    }

    applyWindow(doc.as<JsonVariant>());

    showScreen(code == 200 ? "ABSEN PULANG" : "ABSENSI BERHASIL",
               student, className, status + "  " + time, 6000);
    beep(2, 70, 60);
    Serial.println("Absensi OK: " + student + " | " + status + " " + time);
    return;
  }

  if (code == 409) {
    alertMessage("SUDAH ABSEN", message, "TIDAK DICATAT ULANG", 6000);
    beep(1, 400, 0);
  } else if (code == 404) {
    alertMessage("KARTU TIDAK TERDAFTAR", uid, "DAFTARKAN DI MENU RFID", 6000);
    beep(1, 400, 0);
  } else if (code == 403) {
    alertMessage("ALAT MAINTENANCE", "ABSEN DITOLAK", "HUBUNGI ADMIN", 6000);
    beep(1, 400, 0);
  } else if (code == 401) {
    alertMessage("DEVICE KEY SALAH", "PERIKSA CONFIG.H", "ATAU ROTATE KEY", 8000);
    beep(1, 400, 0);
  } else if (code == 429) {
    alertMessage("SERVER SIBUK", "COBA LAGI SEBENTAR", "", 5000);
    beep(1, 300, 0);
  } else {
    alertMessage("GAGAL MENCATAT", message, "COBA LAGI", 6000);
    beep(1, 400, 0);
  }

  Serial.printf("Tap %s -> HTTP %d | %s\n", uid.c_str(), code, message.c_str());
}

// ===========================================================================
//  ANTREAN OFFLINE
// ===========================================================================
void loadQueue() {
  pendingCount = queuePrefs.getInt("n", 0);

  if (pendingCount < 0) {
    pendingCount = 0;
  }
  if (pendingCount > QUEUE_MAX) {
    pendingCount = QUEUE_MAX;
  }

  for (int i = 0; i < pendingCount; i++) {
    String key = "e" + String(i);
    String raw = queuePrefs.getString(key.c_str(), "");

    pending[i].eventId = "";
    pending[i].uid = "";
    pending[i].tappedAt = "";
    pending[i].verified = false;

    if (raw.length() == 0) {
      continue;
    }

    JsonDoc doc;
    if (deserializeJson(doc, raw) != DeserializationError::Ok) {
      continue;
    }

    pending[i].eventId = String((const char*)(doc["id"] | ""));
    pending[i].uid = String((const char*)(doc["uid"] | ""));
    pending[i].tappedAt = String((const char*)(doc["at"] | ""));
    pending[i].verified = doc["v"] | false;
  }
}

void saveQueue() {
  queuePrefs.putInt("n", pendingCount);

  for (int i = 0; i < QUEUE_MAX; i++) {
    String key = "e" + String(i);

    if (i < pendingCount) {
      JsonDoc doc;
      doc["id"] = pending[i].eventId;
      doc["uid"] = pending[i].uid;
      doc["at"] = pending[i].tappedAt;
      doc["v"] = pending[i].verified;
      String raw;
      serializeJson(doc, raw);
      queuePrefs.putString(key.c_str(), raw);
    } else {
      queuePrefs.remove(key.c_str());
    }
  }
}

void enqueueTap(const String& uid) {
  if (pendingCount >= QUEUE_MAX) {
    for (int i = 1; i < pendingCount; i++) {
      pending[i - 1] = pending[i];
    }
    pendingCount--;
  }

  bool verified = false;
  String tappedAt = isoNowUtc(verified);

  PendingTap tap;
  tap.eventId = String(DEVICE_ID) + "-" + String(bootId) + "-" + String(++eventCounter);
  tap.uid = uid;
  tap.tappedAt = tappedAt;
  tap.verified = verified;

  pending[pendingCount] = tap;
  pendingCount++;
  saveQueue();

  Serial.println("Tap disimpan ke antrean: " + tap.eventId);
}

void sendBatch() {
  if (pendingCount == 0 || WiFi.status() != WL_CONNECTED) {
    return;
  }

  int count = (pendingCount > 20) ? 20 : pendingCount;

  JsonDoc req;
  for (int i = 0; i < count; i++) {
    req["events"][i]["client_event_id"] = pending[i].eventId;
    req["events"][i]["uid"] = pending[i].uid;
    if (pending[i].tappedAt.length() > 0) {
      req["events"][i]["tapped_at"] = pending[i].tappedAt;
    }
    req["events"][i]["time_verified"] = pending[i].verified;
  }

  String body;
  serializeJson(req, body);

  String response;
  int code = postJson("/device/attendance/batch", body, response, 12000);

  if (code != 200) {
    Serial.printf("Batch gagal: HTTP %d (antrean tetap %d)\n", code, pendingCount);
    return;
  }

  JsonDoc doc;
  if (deserializeJson(doc, response) != DeserializationError::Ok) {
    Serial.println("Batch: JSON tidak valid.");
    return;
  }

  bool handled[QUEUE_MAX];
  for (int i = 0; i < QUEUE_MAX; i++) {
    handled[i] = false;
  }

  JsonArray results = doc["results"];
  int handledCount = 0;

  for (JsonVariant item : results) {
    String id = item["client_event_id"] | "";
    for (int i = 0; i < count; i++) {
      if (!handled[i] && pending[i].eventId == id) {
        handled[i] = true;
        handledCount++;
      }
    }
  }

  int writeIndex = 0;
  for (int i = 0; i < pendingCount; i++) {
    if (i < count && handled[i]) {
      continue;
    }
    if (writeIndex != i) {
      pending[writeIndex] = pending[i];
    }
    writeIndex++;
  }
  pendingCount = writeIndex;
  saveQueue();

  Serial.printf("Batch terkirim: %d event diproses, sisa antrean %d\n",
                handledCount, pendingCount);
}

String isoNowUtc(bool& verified) {
  time_t nowSeconds = time(nullptr);
  verified = nowSeconds > 1700000000;

  if (!verified) {
    return "";
  }

  struct tm timeUtc;
  gmtime_r(&nowSeconds, &timeUtc);

  char buffer[25];
  strftime(buffer, sizeof(buffer), "%Y-%m-%dT%H:%M:%SZ", &timeUtc);

  return String(buffer);
}

// ===========================================================================
//  OTA FIRMWARE
// ===========================================================================
void handleOta(JsonVariant ota) {
  String url = ota["url"] | "";
  String version = ota["version"] | "";
  String expectedSha = ota["sha256"] | "";
  size_t size = ota["size"] | 0;

  expectedSha.toLowerCase();

  if (url.length() == 0) {
    return;
  }

  if (version.length() > 0 && prefs.getString("otaFailedVer", "") == version) {
    return;
  }

  Serial.println("OTA diminta: versi " + version + " (" + String(size) + " byte)");
  lcdShow("UPDATE FIRMWARE", version, "MOHON TUNGGU...", "JANGAN MATIKAN");
  reportOta("downloading", version, "0%");

  String error = downloadFirmware(url, size, expectedSha, version);

  if (error.length() > 0) {
    otaFail(version, error);
    return;
  }

  reportOta("success", version, "restart");
  prefs.putString("otaFailedVer", "");

  lcdShow("UPDATE SELESAI", version, "ALAT RESTART...", "");
  beep(2, 80, 60);
  delay(1500);
  ESP.restart();
}

String downloadFirmware(const String& url, size_t expectedSize,
                        const String& expectedSha, const String& version) {
  HTTPClient http;
  http.setConnectTimeout(8000);
  http.setTimeout(20000);

  if (!http.begin(url)) {
    return "URL firmware tidak valid";
  }

  int code = http.GET();
  if (code != 200) {
    http.end();
    return "HTTP " + String(code);
  }

  int contentLength = http.getSize();
  size_t total = expectedSize;
  if (total == 0 && contentLength > 0) {
    total = (size_t)contentLength;
  }

  if (!Update.begin(total)) {
    String message = String(Update.errorString());
    http.end();
    return message;
  }

  mbedtls_sha256_context shaContext;
  mbedtls_sha256_init(&shaContext);
  mbedtls_sha256_starts(&shaContext, 0);

  WiFiClient* stream = http.getStreamPtr();
  uint8_t buffer[1024];
  size_t written = 0;
  int lastReportedPercent = -25;
  unsigned long lastYield = millis();

  while (http.connected() && (total == 0 || written < total)) {
    size_t available = stream->available();

    if (available == 0) {
      if (millis() - lastYield > 15000UL) {
        break;
      }
      delay(1);
      continue;
    }

    lastYield = millis();

    size_t chunk = available > sizeof(buffer) ? sizeof(buffer) : available;
    int readBytes = stream->readBytes(buffer, chunk);

    if (readBytes <= 0) {
      break;
    }

    if (Update.write(buffer, (size_t)readBytes) != (size_t)readBytes) {
      String message = String(Update.errorString());
      Update.abort();
      mbedtls_sha256_free(&shaContext);
      http.end();
      return message;
    }

    mbedtls_sha256_update(&shaContext, buffer, (size_t)readBytes);
    written += (size_t)readBytes;

    int percent = total > 0 ? (int)((written * 100UL) / total) : 0;
    if (percent >= lastReportedPercent + 25) {
      lastReportedPercent = percent;
      lcdShow("UPDATE FIRMWARE", version, String(percent) + "% terunduh",
              "JANGAN MATIKAN");
      reportOta("downloading", version, String(percent) + "%");
    }
  }

  http.end();

  uint8_t digest[32];
  mbedtls_sha256_finish(&shaContext, digest);
  mbedtls_sha256_free(&shaContext);

  if (total > 0 && written != total) {
    Update.abort();
    return "Unduhan tidak lengkap";
  }

  String actualSha = "";
  char hexByte[3];
  for (int i = 0; i < 32; i++) {
    snprintf(hexByte, sizeof(hexByte), "%02x", digest[i]);
    actualSha += hexByte;
  }

  if (expectedSha.length() > 0 && actualSha != expectedSha) {
    Update.abort();
    return "Checksum tidak cocok";
  }

  if (!Update.end(true)) {
    return String(Update.errorString());
  }

  return "";
}

void reportOta(const String& status, const String& version, const String& message) {
  JsonDoc req;
  req["status"] = status;
  req["version"] = version;
  req["message"] = message;

  String body;
  serializeJson(req, body);

  String response;
  postJson("/device/ota-ack", body, response, 12000);
}

void otaFail(const String& version, const String& reason) {
  Serial.println("OTA gagal: " + reason);
  Update.abort();

  if (version.length() > 0) {
    prefs.putString("otaFailedVer", version);
  }

  reportOta("failed", version, reason);
  alertMessage("UPDATE GAGAL", reason, "COBA LEWAT USB", 8000);
  beep(2, 250, 120);
}

// ===========================================================================
//  HTTP
// ===========================================================================
int postJson(const String& path, const String& body, String& response, uint32_t timeoutMs) {
  if (WiFi.status() != WL_CONNECTED) {
    return -1;
  }

  HTTPClient http;
  http.setConnectTimeout(5000);
  http.setTimeout(timeoutMs);
  http.setReuse(false);

  if (!http.begin(cfgApi + path)) {
    return -2;
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Key", cfgKey);

  int code = http.POST(body);

  if (code > 0) {
    response = http.getString();
  } else {
    response = "";
  }

  http.end();
  return code;
}

// ===========================================================================
//  LCD
// ===========================================================================
void lcdShow(const String& line1, const String& line2, const String& line3, const String& line4) {
  String lines[LCD_ROWS] = {line1, line2, line3, line4};

  for (int row = 0; row < LCD_ROWS; row++) {
    String text = fitLine(lines[row]);

    if (text == lcdCache[row]) {
      continue;
    }

    lcdCache[row] = text;
    lcd.setCursor(0, row);
    lcd.print(text);
  }
}

String fitLine(String text) {
  text.replace("\n", " ");
  text.replace("\r", " ");

  if (text.length() > (int)LCD_COLS) {
    text = text.substring(0, LCD_COLS);
  }

  while (text.length() < (int)LCD_COLS) {
    text += ' ';
  }

  return text;
}

void drawIdle() {
  if (scanMode) {
    unsigned long remaining = 0;
    if (scanDeadline > millis()) {
      remaining = (scanDeadline - millis()) / 1000UL;
    }
    lcdShow("MODE BACA UID", "TEMPELKAN KARTU", "DARI DASHBOARD",
            "SISA " + String(remaining) + " DETIK");
    return;
  }

  if (WiFi.status() != WL_CONNECTED) {
    lcdShow("WIFI TERPUTUS", cfgSsid, "BOOT 3 DETIK: SETUP", "MENUNGGU KONEKSI");
    return;
  }

  if (!serverReachable) {
    lcdShow(runtime.deviceShort, "SERVER OFFLINE", "TAP MASUK ANTREAN", queueLine());
    return;
  }

  String schedule = "MSK " + entryLabel() + "  PLG " + homeLabel();
  lcdShow(runtime.schoolLabel, runtime.deviceShort, schedule, queueLine());
}

String entryLabel() {
  return runtime.entryTime.length() > 0 ? runtime.entryTime : String("05:40");
}

String homeLabel() {
  return runtime.homeTime.length() > 0 ? runtime.homeTime : String("15:00");
}

String queueLine() {
  String left = "TAP KARTU";
  String right = (runtime.clock.length() >= 5) ? runtime.clock.substring(0, 5) : String("--:--");

  if (pendingCount > 0) {
    right = String(pendingCount) + "Q " + right;
  }

  int spaces = (int)LCD_COLS - (int)left.length() - (int)right.length();

  if (spaces < 1) {
    int keep = (int)LCD_COLS - (int)left.length() - 1;

    if (keep < 0) {
      keep = 0;
    }
    if ((int)right.length() > keep) {
      right = right.substring(right.length() - keep);
    }
    spaces = 1;
  }

  String line = left;
  for (int i = 0; i < spaces; i++) {
    line += ' ';
  }

  return line + right;
}

void showScreen(const String& line1, const String& line2, const String& line3,
                const String& line4, unsigned long durationMs) {
  lcdShow(line1, line2, line3, line4);
  alertActive = true;
  alertUntil = millis() + durationMs;
}

void alertMessage(const String& title, const String& body,
                  const String& footer, unsigned long durationMs) {
  String first = "";
  String second = "";
  wrapText(body, first, second);
  showScreen(title, first, second, footer, durationMs);
}

void wrapText(const String& text, String& first, String& second) {
  first = "";
  second = "";
  String rest = text;
  rest.trim();

  while (rest.length() > 0) {
    if (first.length() > 0 && second.length() > 0) {
      break;
    }

    int cut;

    if (rest.length() <= (int)LCD_COLS) {
      cut = rest.length();
    } else {
      cut = rest.lastIndexOf(' ', LCD_COLS);
      if (cut <= 0) {
        cut = LCD_COLS;
      }
    }

    String piece = rest.substring(0, cut);
    piece.trim();

    if (first.length() == 0) {
      first = piece;
    } else if (second.length() == 0) {
      second = piece;
    }

    rest = rest.substring(cut);
    rest.trim();
  }
}

// ===========================================================================
//  BUZZER
// ===========================================================================
void beep(int times, int onMs, int offMs) {
  for (int i = 0; i < times; i++) {
#if BUZZER_ACTIVE
    digitalWrite(PIN_BUZZER, HIGH);
    delay(onMs);
    digitalWrite(PIN_BUZZER, LOW);
#else
    tone(PIN_BUZZER, 2200, onMs);
    delay(onMs);
#endif

    if (i < times - 1) {
      delay(offMs);
    }
  }
}
