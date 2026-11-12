@echo off
setlocal EnableExtensions
title PresenTech - Start Lokal

rem ============================================================
rem  PresenTech - nyalakan web lokal dengan sekali klik.
rem  Yang dijalankan:
rem    1. MySQL Laragon          (port 3306)
rem    2. Apache Laragon         (port 80 + vhost 8000)
rem    3. Scheduler Laravel      (Alpa otomatis, status offline)
rem    4. UDP discovery          (alat ESP32 menemukan server)
rem  Tidak menjalankan tunnel publik (cloudflared).
rem ============================================================

set "ROOT=C:\laragon"
set "APP=%~dp0PresenTech-Backend"
set "PHP=%ROOT%\bin\php\php-8.3.33-nts-Win32-vs16-x64\php.exe"
set "MYSQLD=%ROOT%\bin\mysql\mysql-8.0.30-winx64\bin\mysqld.exe"
set "MYSQL_INI=%ROOT%\bin\mysql\mysql-8.0.30-winx64\my.ini"
set "HTTPD=%ROOT%\bin\apache\httpd-2.4.54-win64-VS16\bin\httpd.exe"

rem Cadangan bila versi PHP Laragon berubah.
if not exist "%PHP%" set "PHP=php"

echo.
echo  ==============================================
echo   PresenTech - Start Lokal (tanpa tunnel)
echo  ==============================================
echo.

if not exist "%APP%\artisan" (
  echo  [GAGAL] Backend tidak ditemukan di:
  echo          %APP%
  echo.
  pause
  exit /b 1
)
cd /d "%APP%"

rem ---------- 1. MySQL ----------
call :port_open 3306
if not errorlevel 1 (
  echo  [OK]  MySQL sudah berjalan.
) else (
  echo  [..]  Menyalakan MySQL...
  start "PresenTech MySQL" /min "%MYSQLD%" --defaults-file="%MYSQL_INI%" --console
  call :wait_port 3306 30
  if errorlevel 1 (
    echo  [GAGAL] MySQL tidak menyala. Buka Laragon lalu klik Start All.
    pause
    exit /b 1
  )
  echo  [OK]  MySQL siap di port 3306.
)

rem ---------- 2. Apache ----------
call :port_open 8000
if not errorlevel 1 (
  echo  [OK]  Web/Apache sudah berjalan.
) else (
  echo  [..]  Menyalakan Apache...
  start "PresenTech Apache" /min "%HTTPD%"
  call :wait_port 8000 30
  if errorlevel 1 (
    echo  [GAGAL] Apache tidak menyala di port 8000.
    echo          Kemungkinan port 80 dipakai program lain.
    echo          Buka Laragon lalu klik Start All.
    pause
    exit /b 1
  )
  echo  [OK]  Web siap di port 8000.
)

rem ---------- 3. Bersihkan cache konfigurasi ----------
"%PHP%" artisan config:clear >nul 2>&1

rem ---------- 4. Scheduler ----------
call :php_task_running "schedule:work"
if errorlevel 1 (
  echo  [..]  Menyalakan scheduler...
  start "PresenTech Scheduler" /min "%PHP%" artisan schedule:work
  echo  [OK]  Scheduler berjalan.
) else (
  echo  [OK]  Scheduler sudah berjalan.
)

rem ---------- 5. Discovery ----------
call :php_task_running "devices:discovery"
if errorlevel 1 (
  echo  [..]  Menyalakan UDP discovery...
  start "PresenTech Discovery UDP 8888" /min "%PHP%" artisan devices:discovery
  echo  [OK]  Discovery berjalan.
) else (
  echo  [OK]  Discovery sudah berjalan.
)

rem ---------- Alamat akses ----------
set "LANIP="
for /f "usebackq delims=" %%i in (`powershell -NoProfile -Command "([System.Net.Dns]::GetHostAddresses([System.Net.Dns]::GetHostName())).Where({ $_.AddressFamily -eq 'InterNetwork' -and $_.IPAddressToString -notlike '127.*' })[0].IPAddressToString"`) do set "LANIP=%%i"

echo.
echo  ----------------------------------------------
echo   Web lokal : http://127.0.0.1:8000/app
if defined LANIP echo   Web LAN   : http://%LANIP%:8000/app
echo   API health: http://127.0.0.1:8000/api/health
echo.
echo   Login admin: admin / admin123
echo  ----------------------------------------------
echo.
echo  Jendela ini boleh ditutup. Layanan tetap berjalan.
echo.

start "" "http://127.0.0.1:8000/app"
rem Jeda singkat agar output tetap terbaca (timeout gagal bila stdin dialihkan).
ping -n 4 127.0.0.1 >nul
exit /b 0

rem =================== FUNGSI ===================

:port_open
netstat -an | findstr /C:":%~1 " | findstr /I /C:"LISTENING" >nul
exit /b %errorlevel%

:wait_port
set /a "wait_tries=0"
:wait_port_loop
call :port_open %~1
if not errorlevel 1 exit /b 0
set /a "wait_tries+=1"
if %wait_tries% GEQ %~2 exit /b 1
ping -n 2 127.0.0.1 >nul
goto wait_port_loop

:php_task_running
powershell -NoProfile -ExecutionPolicy Bypass -Command "$p = Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { $_.CommandLine -like '*%~1*' }; if ($p) { exit 0 } else { exit 1 }"
exit /b %errorlevel%
