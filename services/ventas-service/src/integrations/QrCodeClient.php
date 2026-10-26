<?php
declare(strict_types=1);
require_once __DIR__.'/../InvoiceValidation.php';

// Integración externa de Ventas. Guarda los PNG para no pedir el mismo QR cada vez.
final class QrCodeClient
{
    public function __construct(
        private string $directory = '/app/storage/facturas-qr',
        private string $apiUrl = 'https://api.qrserver.com/v1/create-qr-code/'
    ) {}

    // Solo se comparte la referencia del comprobante; nunca se envía el JWT ni datos personales.
    public static function content(array $invoice): string
    {
        return InvoiceValidation::url($invoice['referencia']);
    }

    public function image(array $invoice): string
    {
        $text = self::content($invoice);
        $file = $this->directory . '/' . hash('sha256', $text) . '.png';
        if (is_file($file)) {
            $cached = file_get_contents($file);
            if (is_string($cached) && self::validPng($cached)) return $cached;
        }
        // La carpeta pertenece a Ventas y no se publica como una carpeta estática de Nginx.
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new HttpError(503, 'No se pudo preparar el QR de la factura');
        }
        $png = $this->request($text);
        // Escribe primero un temporal: una interrupción no debe dejar un PNG incompleto en caché.
        $temporary = tempnam($this->directory, '.qr-');
        if ($temporary === false) throw new HttpError(503, 'No se pudo guardar el QR');
        try {
            if (file_put_contents($temporary, $png, LOCK_EX) !== strlen($png) || !rename($temporary, $file)) {
                throw new HttpError(503, 'No se pudo guardar el QR');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
        return $png;
    }

    // Solo acepta el PNG del tamaño solicitado, con un límite pequeño de descarga.
    private static function validPng(string $data): bool
    {
        if (strlen($data) > 262144 || !str_starts_with($data, "\x89PNG\r\n\x1a\n")
            || !str_ends_with($data, "\x00\x00\x00\x00IEND\xAE\x42\x60\x82")) return false;
        $size = @getimagesizefromstring($data);
        return $size !== false && $size[0] === 240 && $size[1] === 240 && $size[2] === IMAGETYPE_PNG;
    }

    private function request(string $text): string
    {
        $curl = curl_init($this->apiUrl); $image = '';
        curl_setopt_array($curl, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query(['data'=>$text, 'size'=>'240x240', 'format'=>'png', 'ecc'=>'M', 'qzone'=>4], '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded', 'Accept: image/png'],
            CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>7, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            // Rechaza una respuesta excesiva antes de cargarla completa en memoria.
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$image): int {
                if (strlen($image) + strlen($chunk) > 262144) return 0;
                $image .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $type = strtolower(trim(explode(';', (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]));
        curl_close($curl);
        if ($ok === false || $status !== 200 || $type !== 'image/png' || !self::validPng($image)) {
            throw new HttpError(503, 'El QR no está disponible. Puedes reintentar sin registrar otra venta');
        }
        return $image;
    }
}
