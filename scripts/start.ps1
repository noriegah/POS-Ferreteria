#requires -Version 7.4
param([switch]$Tools)
# Ejecutar con PowerShell 7 desde una instalación nueva o existente.
$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    # Solo agrega valores faltantes. Nunca cambia contraseñas ni claves existentes.
    if(!(Test-Path -LiteralPath '.env')) { Copy-Item -LiteralPath '.env.example' -Destination '.env' }
    $settings=@{}
    foreach($line in Get-Content -LiteralPath '.env') {if($line -match '^([A-Z_]+)=(.*)$'){$settings[$matches[1]]=$matches[2]}}
    $defaults=@{AUTH_DB_USER='auth_app';INVENTARIO_DB_USER='inventario_app';VENTAS_DB_USER='ventas_app';REPLICATION_USER='ferresync_repl';REPORTS_DB_USER='ventas_lectura';DB_VIEWER_USER='ferresync_consulta'}
    foreach($name in $defaults.Keys){if(!$settings[$name]){Add-Content '.env' "$name=$($defaults[$name])"}}
    # En .env.example las contraseñas de ejemplo deben reemplazarse al instalar.
    $text=Get-Content -Raw '.env'
    foreach($name in @('DB_ROOT_PASSWORD','AUTH_DB_PASSWORD','INVENTARIO_DB_PASSWORD','VENTAS_DB_PASSWORD','VENTAS_INTERNAL_KEY','REPLICATION_PASSWORD','REPLICA_ROOT_PASSWORD','REPORTS_DB_PASSWORD','DB_VIEWER_PASSWORD')) {
        $value=$settings[$name]
        if(!$value -or $value -match '^(CAMBIAR|change|REPLACE|CHANGE|cambia|replace)') {
            $secret=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).ToLowerInvariant()
            if($text -match "(?m)^${name}=.*$"){$text=[regex]::Replace($text,"(?m)^${name}=.*$","$name=$secret")} else {$text+="`n$name=$secret`n"}
        }
    }
    [IO.File]::WriteAllText((Join-Path (Get-Location) '.env'),$text,[Text.UTF8Encoding]::new($false))
    $private=Test-Path 'secrets/jwt_private.pem'; $public=Test-Path 'secrets/jwt_public.pem'
    if($private -xor $public){throw 'Falta una clave JWT. Restaura el par antes de continuar.'}
    if(!$private){& ./scripts/generate-jwt-keys.ps1}
    docker compose config --quiet
    if($LASTEXITCODE -ne 0){throw 'Configuración Compose inválida'}
    docker compose up -d db-primary db-replica
    if($LASTEXITCODE -ne 0){throw 'No se pudieron iniciar las bases'}
    $ready=$false
    for($i=0;$i -lt 60;$i++) {
        $container=docker compose ps -q db-primary
        if($container -and (docker inspect --format '{{.State.Health.Status}}' $container) -eq 'healthy'){$ready=$true;break}
        Start-Sleep -Seconds 1
    }
    if(!$ready){throw 'La primaria no quedó disponible'}
    & ./scripts/migrate.ps1
    if($LASTEXITCODE -ne 0){throw 'Falló la migración'}
    & ./scripts/setup-replication.ps1
    if($LASTEXITCODE -ne 0){throw 'Falló la réplica'}
    docker compose up -d --build
    if($LASTEXITCODE -ne 0){throw 'Falló el arranque'}
    docker compose exec -T gateway nginx -s reload
    if($LASTEXITCODE -ne 0){throw 'No se pudo recargar Nginx'}
    if($Tools){docker compose --profile tools up -d adminer; if($LASTEXITCODE -ne 0){throw 'No se pudo abrir Adminer'}}
    Write-Output 'FerreSync iniciado. Crea el administrador inicial siguiendo docs/autenticacion.md si es una instalación nueva.'
} finally {Pop-Location}

