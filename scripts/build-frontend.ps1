# Build frontend React lalu salin hasilnya ke public/app Laravel.
# Pemakaian:
#   powershell -ExecutionPolicy Bypass -File scripts\build-frontend.ps1
#   powershell -ExecutionPolicy Bypass -File scripts\build-frontend.ps1 -Install

param(
    [switch]$Install
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$frontend = Join-Path $root "PresenTech-frontend"
$target = Join-Path $root "PresenTech-Backend\public\app"

if (-not (Test-Path $frontend)) {
    throw "Folder frontend tidak ditemukan: $frontend"
}

if ($Install) {
    Write-Host "npm install..." -ForegroundColor Cyan
    Push-Location $frontend
    try { npm install } finally { Pop-Location }

    if ($LASTEXITCODE -ne 0) {
        throw "npm install gagal (exit code $LASTEXITCODE)."
    }
}

Write-Host "npm run build..." -ForegroundColor Cyan
Push-Location $frontend
# npm menulis peringatan ke stderr; jangan diperlakukan sebagai kegagalan.
$ErrorActionPreference = "Continue"
try { npm run build } finally { Pop-Location }
$buildExitCode = $LASTEXITCODE
$ErrorActionPreference = "Stop"

if ($buildExitCode -ne 0) {
    throw "Build frontend gagal (exit code $buildExitCode). Hasil lama tidak disalin."
}

$dist = Join-Path $frontend "dist"

if (-not (Test-Path (Join-Path $dist "index.html"))) {
    throw "Build gagal: dist/index.html tidak ditemukan."
}

New-Item -ItemType Directory -Force -Path $target | Out-Null

Remove-Item (Join-Path $target "assets") -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $target "index.html") -Force -ErrorAction SilentlyContinue

Copy-Item (Join-Path $dist "*") $target -Recurse -Force

Write-Host "Selesai. SPA disalin ke PresenTech-Backend\public\app" -ForegroundColor Green
Write-Host "Buka http://127.0.0.1:8000/app" -ForegroundColor Green
