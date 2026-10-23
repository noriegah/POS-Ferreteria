$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
function Check-Exit([string]$message) {if($LASTEXITCODE -ne 0){throw $message}}
function Sql([string]$service,[string]$statement) {
    $statement | docker compose exec -T $service sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" --default-character-set=utf8mb4 --batch --skip-column-names'
    Check-Exit "SQL failed in $service"
}
function Wait-Database([string]$service) {
    for($i=0;$i -lt 60;$i++) {
        $container=docker compose ps -q $service
        if($container) {
            $state=docker inspect --format '{{.State.Health.Status}}' $container
            if($state -eq 'healthy'){return}
        }
        Start-Sleep -Seconds 1
    }
    throw "$service did not become healthy"
}
function Replica-Status {
    $raw=docker compose exec -T db-replica sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" --vertical -e "SHOW SLAVE STATUS"'
    Check-Exit 'Cannot read replica status'
    $result=@{}
    foreach($line in $raw) {
        if($line -match '^\s*(Slave_IO_Running|Slave_SQL_Running|Seconds_Behind_Master):\s*(.*)$') {$result[$matches[1]]=$matches[2].Trim()}
    }
    return $result
}
try {
    $settings=@{}
    foreach($line in Get-Content -LiteralPath .env) {
        if($line -match '^\s*([A-Z_]+)=(.*)$'){$settings[$matches[1]]=$matches[2].Trim()}
    }
    foreach($key in @('REPLICATION_USER','REPORTS_DB_USER','DB_VIEWER_USER')) {
        if($settings[$key] -notmatch '^[a-z][a-z0-9_]{0,31}$'){throw "Invalid $key"}
    }
    foreach($key in @('REPLICATION_PASSWORD','REPORTS_DB_PASSWORD','DB_VIEWER_PASSWORD','REPLICA_ROOT_PASSWORD')) {
        if($settings[$key] -notmatch '^[a-zA-Z0-9_-]{32,128}$'){throw "Invalid $key; use 32-128 letters, digits, underscores or hyphens"}
    }
    # Respaldo completo ANTES de recrear la primaria. Nunca elimina el volumen existente.
    [IO.Directory]::CreateDirectory((Join-Path $PWD '.backups')) | Out-Null
    docker compose exec -T db-primary sh -c 'umask 077; mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --all-databases --single-transaction --routines --events > /tmp/before-replication.sql && test -s /tmp/before-replication.sql'
    Check-Exit 'Backup failed; no database was recreated'
    $stamp=Get-Date -Format 'yyyyMMdd-HHmmss-fff'
    $backupPath=Join-Path $PWD ".backups/before-replication-$stamp.sql"
    docker compose cp db-primary:/tmp/before-replication.sql $backupPath
    Check-Exit 'Backup copy failed'
    if((Get-Item -LiteralPath $backupPath).Length -eq 0){throw 'Backup is empty'}
    Write-Output 'Respaldo completo guardado en .backups.'
    docker compose up -d db-primary db-replica
    Check-Exit 'Cannot start databases'
    Wait-Database db-primary
    Wait-Database db-replica
    $binlog=Sql db-primary 'SELECT @@log_bin;'
    if(($binlog -join '').Trim() -ne '1'){throw 'Primary binary log not enabled'}
    $replUser=$settings['REPLICATION_USER']; $replPassword=$settings['REPLICATION_PASSWORD']
    $reportUser=$settings['REPORTS_DB_USER']; $reportPassword=$settings['REPORTS_DB_PASSWORD']
    $viewer=$settings['DB_VIEWER_USER']; $viewerPassword=$settings['DB_VIEWER_PASSWORD']
    # Las cuentas se crean localmente; las contraseñas nunca se imprimen ni se replican.
    $accounts=@"
SET SESSION sql_log_bin=0;
CREATE USER IF NOT EXISTS '$replUser'@'%' IDENTIFIED BY '$replPassword';
ALTER USER '$replUser'@'%' IDENTIFIED BY '$replPassword';
GRANT REPLICATION SLAVE ON *.* TO '$replUser'@'%';
CREATE USER IF NOT EXISTS '$viewer'@'%' IDENTIFIED BY '$viewerPassword';
ALTER USER '$viewer'@'%' IDENTIFIED BY '$viewerPassword';
GRANT SELECT ON auth_db.* TO '$viewer'@'%';
GRANT SELECT ON inventario_db.* TO '$viewer'@'%';
GRANT SELECT ON ventas_db.* TO '$viewer'@'%';
GRANT SELECT ON reportes_db.* TO '$viewer'@'%';
"@
    Sql db-primary $accounts | Out-Null
    $configured=Sql db-replica 'SHOW SLAVE STATUS;'
    if(!$configured) {
        $tableCount=Sql db-replica "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='ventas_db';"
        if(($tableCount -join '').Trim() -ne '0'){throw 'Replica has tables but no replication configuration. Inspect before importing; nothing was deleted.'}
        # master-data conserva el punto exacto del snapshot; single-transaction mantiene consistencia.
        docker compose exec -T db-primary sh -c 'umask 077; mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --master-data=2 --databases ventas_db --routines --events --triggers > /tmp/replication-seed.sql && test -s /tmp/replication-seed.sql'
        Check-Exit 'Cannot create replication snapshot'
        $seedPath=Join-Path $PWD ".backups/replication-seed-$stamp.sql"
        docker compose cp db-primary:/tmp/replication-seed.sql $seedPath
        Check-Exit 'Cannot save replication snapshot'
        $coordinates=Select-String -LiteralPath $seedPath -Pattern "^-- CHANGE MASTER TO MASTER_LOG_FILE='([^']+)', MASTER_LOG_POS=([0-9]+);" | Select-Object -First 1
        if(!$coordinates){throw 'Snapshot has no binlog coordinates'}
        $file=$coordinates.Matches[0].Groups[1].Value; $position=$coordinates.Matches[0].Groups[2].Value
        if($file -notmatch '^[a-zA-Z0-9_.-]+$'){throw 'Invalid binlog filename'}
        docker compose cp $seedPath db-replica:/tmp/replication-seed.sql
        Check-Exit 'Cannot copy seed to replica'
        docker compose exec -T db-replica sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" --default-character-set=utf8mb4 < /tmp/replication-seed.sql'
        Check-Exit 'Replica import failed; inspect before retrying'
        $configure="CHANGE MASTER TO MASTER_HOST='db-primary', MASTER_PORT=3306, MASTER_USER='$replUser', MASTER_PASSWORD='$replPassword', MASTER_LOG_FILE='$file', MASTER_LOG_POS=$position, MASTER_CONNECT_RETRY=5;"
        Sql db-replica $configure | Out-Null
    }
    $readers=@"
CREATE USER IF NOT EXISTS '$reportUser'@'%' IDENTIFIED BY '$reportPassword';
ALTER USER '$reportUser'@'%' IDENTIFIED BY '$reportPassword';
GRANT SELECT ON ventas_db.* TO '$reportUser'@'%';
GRANT REPLICA MONITOR ON *.* TO '$reportUser'@'%';
CREATE USER IF NOT EXISTS '$viewer'@'%' IDENTIFIED BY '$viewerPassword';
ALTER USER '$viewer'@'%' IDENTIFIED BY '$viewerPassword';
GRANT SELECT ON ventas_db.* TO '$viewer'@'%';
GRANT REPLICA MONITOR ON *.* TO '$viewer'@'%';
START SLAVE;
"@
    Sql db-replica $readers | Out-Null
    $ready=$false
    for($i=0;$i -lt 40;$i++) {
        $status=Replica-Status
        if($status['Slave_IO_Running'] -eq 'Yes' -and $status['Slave_SQL_Running'] -eq 'Yes' -and $status['Seconds_Behind_Master'] -eq '0'){$ready=$true;break}
        Start-Sleep -Milliseconds 500
    }
    if(!$ready){throw 'Replica SQL thread not running; inspect SHOW SLAVE STATUS'}
    Write-Output 'Réplica configurada. Estado y sincronización se validan con test-replication.ps1.'
} finally {Pop-Location}
