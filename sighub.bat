@echo off

if "%1" == "install" (
    composer install
) else (
    PHP artisan sighub %1 %2 %3
)
