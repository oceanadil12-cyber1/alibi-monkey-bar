@echo off
rem Builds "Alibi Website - For Developer" next to this folder: the files to upload
rem to the web host (folder + ZIP) and the go-live guide. Give that folder to the developer.
title Alibi Monkey Bar - make upload package
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0make-upload-package.ps1"
pause
