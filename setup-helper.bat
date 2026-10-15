@echo off
setlocal
set "PROJECT_ROOT=%~dp0"
set "PHP_EXE="
set "XAMPP_PHP=%PROJECT_ROOT%..\..\php\php.exe"
for %%I in ("%XAMPP_PHP%") do set "XAMPP_PHP=%%~fI"
if exist "%XAMPP_PHP%" set "PHP_EXE=%XAMPP_PHP%"
if not defined PHP_EXE (
  for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP_EXE set "PHP_EXE=%%P"
)
if not defined PHP_EXE (
  echo PHP was not found. Install XAMPP with PHP 8.1 or later and place this project under XAMPP\htdocs.
  echo No files or settings were changed.
  pause
  exit /b 1
)
:menu
echo.
echo AdoptAPet installation helper
echo It does not create a database, import SQL, change Apache, or alter XAMPP settings.
echo.
choice /c 123 /n /m "1 Create MFA key, 2 Run read-only check, 3 Exit: "
if errorlevel 3 exit /b 0
if errorlevel 2 goto check
if errorlevel 1 goto generate
goto menu
:generate
pushd "%PROJECT_ROOT%"
"%PHP_EXE%" tools\create-mfa-key.php
popd
pause
goto menu
:check
pushd "%PROJECT_ROOT%"
"%PHP_EXE%" tools\installation-check.php
popd
pause
goto menu
