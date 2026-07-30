@echo off
REM Este paso ahora se ejecuta automaticamente con "composer install" / "composer update".
REM Se conserva este .bat solo por compatibilidad. En PowerShell ejecutar: .\urn_on.bat
php "%~dp0urn_on.php"
