@echo off
rem Opens the Alibi Monkey Bar website on this computer.
rem Uses PHP (XAMPP) when available so the menu, ordering and forms work;
rem otherwise falls back to a static preview with Node.js (npx serve).
title Alibi Monkey Bar - local website
cd /d "%~dp0"

set "PORT=3456"
set "PHP="
if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP (
  where php >nul 2>nul && set "PHP=php"
)

echo.
echo  Alibi Monkey Bar website: http://localhost:%PORT%
echo  Keep this window open while you use the site. Close it to stop.
echo.

if defined PHP (
  echo  Mode: PHP server - live menu, ordering and forms enabled.
  echo.
  start "" http://localhost:%PORT%
  "%PHP%" -S localhost:%PORT% -t .
) else (
  echo  Mode: static preview - PHP not found, so the menu uses its saved
  echo  snapshot and the forms will not send. Install XAMPP for full mode.
  echo.
  start "" http://localhost:%PORT%
  npx --yes serve . -l %PORT% --no-clipboard
)
pause
