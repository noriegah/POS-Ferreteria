param([string]$ProjectPath = (Split-Path $PSScriptRoot -Parent))
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $ProjectPath
# Lee las credenciales desde .env sin mostrarlas en pantalla.
$settings = @{}
foreach ($line in Get-Content -LiteralPath '.env') {
    if ($line -match '^\s*([A-Z_]+)=(.*)$') { $settings[$matches[1]] = $matches[2].Trim() }
}
# Comprueba los nombres y contraseñas antes de usarlos para preparar las cuentas SQL.
$accounts = @(@('AUTH', 'auth_db'), @('INVENTARIO', 'inventario_db'), @('VENTAS', 'ventas_db'))
foreach ($account in $accounts) {
    $prefix = $account[0]
    if ($settings["${prefix}_DB_USER"] -notmatch '^[a-z][a-z0-9_]{0,31}$') { throw "Invalid ${prefix}_DB_USER" }
    if ($settings["${prefix}_DB_PASSWORD"] -notmatch '^[a-zA-Z0-9_-]{32,128}$') { throw "Use 32-128 letters, digits, underscores or hyphens for ${prefix}_DB_PASSWORD" }
}
if (($accounts | ForEach-Object { $settings["$($_[0])_DB_USER"] } | Select-Object -Unique).Count -ne $accounts.Count) { throw 'Service users must be different' }
# Hace una copia completa antes de modificar las tablas; después la copia a .backups en el equipo.
$backupCommand = 'umask 077; mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --all-databases --single-transaction --routines --events > /tmp/pos-before-migrations.sql && test -s /tmp/pos-before-migrations.sql'
& docker compose exec -T db-primary sh -c $backupCommand
if ($LASTEXITCODE -ne 0) { throw 'Backup failed; migration cancelled' }
$backupFolder = Join-Path $ProjectPath '.backups'
[IO.Directory]::CreateDirectory($backupFolder) | Out-Null
$container = & docker compose ps -q db-primary
if ($LASTEXITCODE -ne 0 -or -not $container) { throw 'Cannot locate database container' }
$backupFile = Join-Path $backupFolder ('before-migrations-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff') + '.sql')
& docker cp "${container}:/tmp/pos-before-migrations.sql" $backupFile
if ($LASTEXITCODE -ne 0 -or (Get-Item -LiteralPath $backupFile).Length -eq 0) { throw 'Backup copy failed; migration cancelled' }
# Carga las tablas y concede a cada servicio permisos solo sobre su propia base.
# El contexto de ventas_db permite registrar también su DDL con binlog_do_db.
$sql = "USE ventas_db;`n"
# Las migraciones son aditivas y se leen en orden: primero tablas, después campos nuevos.
foreach ($migration in Get-ChildItem -LiteralPath (Join-Path $ProjectPath 'db-master/migrations') -Filter '*.sql' | Sort-Object Name) {
    $sql += [IO.File]::ReadAllText($migration.FullName) + "`n"
}
# Las cuentas técnicas se administran localmente; no deben replicarse.
$sql += "`nSET SESSION sql_log_bin=0;"
foreach ($account in $accounts) {
    $prefix = $account[0]; $database = $account[1]
    $user = $settings["${prefix}_DB_USER"]; $password = $settings["${prefix}_DB_PASSWORD"]
    $sql += "`nCREATE USER IF NOT EXISTS '$user'@'%' IDENTIFIED BY '$password';"
    $sql += "`nALTER USER '$user'@'%' IDENTIFIED BY '$password';"
    $sql += "`nREVOKE ALL PRIVILEGES, GRANT OPTION FROM '$user'@'%';"
    $sql += "`nGRANT SELECT, INSERT, UPDATE, DELETE ON ${database}.* TO '$user'@'%';"
}
# Ejecuta el SQL con la cuenta administrativa. Si falla, se detiene; el DDL no se revierte automáticamente.
$sql | & docker compose exec -T db-primary sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" --default-character-set=utf8mb4'
if ($LASTEXITCODE -ne 0) { throw 'Migration failed; inspect the database before retrying (DDL is not transactional)' }
Write-Output 'Migrations applied. Backup saved in the ignored .backups folder on the host.'
