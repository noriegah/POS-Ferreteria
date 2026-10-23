<?php
declare(strict_types=1);
use Dompdf\Dompdf;
use Dompdf\Options;

// Produce un PDF descargable desde una plantilla separada del resto de la lógica de Ventas.
final class InvoicePdf
{
    public static function generate(array $invoice): string
    {
        $issuer = require __DIR__.'/../config/invoice.php';
        $qr = null;
        try { $qr = 'data:image/png;base64,'.base64_encode((new QrCodeClient())->image($invoice)); }
        catch (HttpError $e) { /* La factura sigue disponible si la API del QR falla. */ }
        // Escapa todos los textos del comprador y los productos antes de formar el HTML.
        $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn($value) => 'Q '.number_format((float)$value,2,'.',',');
        $date = (new DateTimeImmutable($invoice['fecha']))->setTimezone(new DateTimeZone('America/Guatemala'))->format('d/m/Y H:i:s');
        ob_start();
        try { require __DIR__.'/../templates/invoice.php'; $html=ob_get_contents(); }
        finally { ob_end_clean(); }
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', __DIR__.'/../templates');
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4'); $pdf->loadHtml($html,'UTF-8'); $pdf->render();
        $font=$pdf->getFontMetrics()->getFont('DejaVu Sans','normal');
        $pdf->getCanvas()->page_text(450,810,'Página {PAGE_NUM} de {PAGE_COUNT}',$font,8,[0.4,0.4,0.4]);
        return $pdf->output();
    }
}
