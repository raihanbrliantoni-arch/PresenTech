# Tangkap paket dari/ke alat ESP32 (192.168.1.8) untuk diagnosis koneksi.
# Harus dijalankan sebagai Administrator (pktmon butuh akses driver).
# Hasil ringkas ditulis ke scripts\capture-alat-output.txt

$ErrorActionPreference = 'Continue'
$out = Join-Path $PSScriptRoot 'capture-alat-output.txt'
$tmp = 'C:\Users\raiha\AppData\Local\Temp\opencode'
$target = '192.168.1.8'
$duration = 45

function Write-Out([string]$text) { $text | Add-Content -Path $out -Encoding UTF8 }

"=== capture mulai $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') ===" | Set-Content -Path $out -Encoding UTF8
Write-Out "target: $target | durasi: ${duration}s"

Write-Out "`n--- snapshot IP mesin ---"
Get-NetIPAddress -AddressFamily IPv4 |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
    ForEach-Object { Write-Out ("  {0,-16} {1,-12} {2}" -f $_.IPAddress, $_.InterfaceAlias, $_.PrefixOrigin) }

Write-Out "`n--- ping alat ---"
$p = Test-Connection -ComputerName $target -Count 3 -ErrorAction SilentlyContinue
Write-Out ("  balasan: " + ($p | Measure-Object).Count + " | rata-rata: " + (($p | Measure-Object -Property ResponseTime -Average).Average) + " ms")

Write-Out "`n--- pasang pktmon ---"
pktmon stop 2>&1 | Out-Null
pktmon filter remove 2>&1 | Out-Null
(pktmon filter add -i $target 2>&1) | ForEach-Object { Write-Out ("  " + $_) }
(pktmon start --capture --pkt-size 256 --file-name "$tmp\dev.etl" 2>&1) | ForEach-Object { Write-Out ("  " + $_) }

Start-Sleep -Seconds $duration

Write-Out "`n--- stop ---"
(pktmon stop 2>&1) | ForEach-Object { Write-Out ("  " + $_) }
(pktmon etl2txt "$tmp\dev.etl" -o "$tmp\dev.txt" 2>&1) | ForEach-Object { Write-Out ("  " + $_) }

$txt = "$tmp\dev.txt"
if (Test-Path $txt) {
    $lines = Get-Content $txt
    Write-Out "`n--- total baris capture: $($lines.Count) ---"

    Write-Out "`n--- ringkasan per pasangan IP:port ---"
    $lines | Where-Object { $_ -match '\d+\.\d+\.\d+\.\d+' } |
        ForEach-Object {
            if ($_ -match '(\d+\.\d+\.\d+\.\d+)[:.](\d+)\s*[-><]{1,2}\s*(\d+\.\d+\.\d+\.\d+)[:.](\d+)') {
                "$($Matches[1]):$($Matches[2]) -> $($Matches[3]):$($Matches[4])"
            } elseif ($_ -match '(\d+\.\d+\.\d+\.\d+)') {
                "(no-port) $($Matches[1])"
            }
        } | Group-Object | Sort-Object Count -Descending | Select-Object -First 25 |
        ForEach-Object { Write-Out ("  {0,6} x {1}" -f $_.Count, $_.Name) }

    Write-Out "`n--- 60 baris pertama berisi IP alat / IP mesin ---"
    $lines | Where-Object { $_ -match '192\.168\.1\.(8|5|6)' } | Select-Object -First 60 | ForEach-Object { Write-Out ("  " + $_) }
} else {
    Write-Out "`n(!!) file $txt tidak terbentuk - capture gagal"
}

Write-Out "`n=== selesai $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') ==="
