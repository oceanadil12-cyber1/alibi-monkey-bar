@echo off
set "ROOT=D:\Adil\Claude Directory - Git\alibi-monkey-bar"
"C:\Users\20607\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" -S 0.0.0.0:3001 -t "%ROOT%" "%ROOT%\.router.php"
