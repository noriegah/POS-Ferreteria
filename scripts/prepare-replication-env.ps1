$ErrorActionPreference='Stop'
$project=Split-Path $PSScriptRoot -Parent
$path=Join-Path $project '.env'
$text=[IO.File]::ReadAllText($path)
# Conserva valores ya configurados y genera secretos nuevos sin imprimirlos.
$defaults=@{REPLICATION_USER='ferresync_repl';REPORTS_DB_USER='ventas_lectura';DB_VIEWER_USER='ferresync_consulta'}
foreach($key in @('REPLICATION_PASSWORD','REPLICA_ROOT_PASSWORD','REPORTS_DB_PASSWORD','DB_VIEWER_PASSWORD')) {
    $defaults[$key]=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).ToLowerInvariant()
}
foreach($key in $defaults.Keys) {
    if($text -notmatch "(?m)^$key="){$text+="`n$key=$($defaults[$key])"}
}
[IO.File]::WriteAllText($path,$text+"`n")
Write-Output 'Variables de replicación preparadas en .env; no se mostraron contraseñas.'
