<?php
declare(strict_types=1);
// Prueba la API real con una referencia de demostración y limpia su propia carpeta temporal.
require '/app/vendor/autoload.php';
require '/app/src/TokenService.php';
require '/app/src/integrations/QrCodeClient.php';
$folder = '/tmp/qr-test-' . bin2hex(random_bytes(8));
$invoice = ['numero'=>'FAC-DEMO', 'referencia'=>'00000000-0000-4000-8000-000000000000'];
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
try {
    $text = QrCodeClient::content($invoice);
    check(str_contains($text, '/validar-factura.html?') && str_contains($text, 'firma=') && !str_contains($text, 'Bearer') && !str_contains($text, 'comprador'), 'QR contains signed validation URL without personal data');
    $png = (new QrCodeClient($folder))->image($invoice);
    $size = getimagesizefromstring($png);
    check($size !== false && $size[0] === 240 && $size[1] === 240 && $size[2] === IMAGETYPE_PNG, 'Real provider returns 240px PNG');
    $file = $folder . '/' . hash('sha256', $text) . '.png';
    check(is_file($file) && file_get_contents($file) === $png, 'PNG is saved in private cache');
    $offline = new QrCodeClient($folder, 'https://127.0.0.1:1/unavailable');
    check($offline->image($invoice) === $png, 'Valid cache works when provider is unavailable');
    file_put_contents($file, substr($png, 0, 40));
    try { $offline->image($invoice); throw new RuntimeException('Truncated PNG accepted'); }
    catch (HttpError $e) { check($e->status === 503, 'Corrupt cache is rejected and provider failure returns 503'); }
    check((new QrCodeClient($folder))->image($invoice) === $png, 'Corrupt cache can be regenerated');
} finally {
    if (is_dir($folder)) {
        foreach (glob($folder . '/*') as $file) if (is_file($file)) unlink($file);
        foreach (glob($folder . '/.qr-*') as $file) if (is_file($file)) unlink($file);
        rmdir($folder);
    }
    echo "Temporary QR test folder removed.\n";
}
