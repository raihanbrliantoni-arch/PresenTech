CreateObject("WScript.Shell").Run "cmd /c cd /d ""C:\laragon\www\pkk\PresenTech-Backend"" && ""C:\laragon\bin\php\php-8.3.33-nts-Win32-vs16-x64\php.exe"" artisan schedule:run >> storage\logs\scheduler.log 2>&1", 0, False

