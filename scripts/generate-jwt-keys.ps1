param([string]$Directory = (Join-Path (Split-Path $PSScriptRoot -Parent) 'secrets'))
# Requiere PowerShell 7 y .NET con exportación PEM para crear los archivos de claves.
$ErrorActionPreference = 'Stop'
$private = Join-Path $Directory 'jwt_private.pem'
$public = Join-Path $Directory 'jwt_public.pem'
# Evita sobrescribir las claves existentes, porque cambiar el par invalidaría los tokens actuales.
if ((Test-Path -LiteralPath $private) -or (Test-Path -LiteralPath $public)) {
    throw 'Key files already exist. They will not be overwritten; restore the pair or plan an explicit rotation.'
}
[IO.Directory]::CreateDirectory($Directory) | Out-Null
# Genera un par RSA de 3072 bits: la privada firma y la pública permite comprobar la firma.
$rsa = [Security.Cryptography.RSA]::Create(3072)
try {
    [IO.File]::WriteAllText($private, $rsa.ExportPkcs8PrivateKeyPem(), [Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText($public, $rsa.ExportSubjectPublicKeyInfoPem(), [Text.UTF8Encoding]::new($false))
} finally { $rsa.Dispose() }
Write-Output 'RSA keys created. Keep the private key outside Git and restrict access to its owner.'
