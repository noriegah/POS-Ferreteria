<?php
declare(strict_types=1);
use Dompdf\Dompdf;
use Dompdf\Options;

// Produce el reporte desde datos confirmados por Ventas, no desde HTML del navegador.
final class ReportPdf
{
    public static function generate(array $report): string
    {
        $escape = static fn($value) => htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $money = static fn($value) => 'Q '.number_format((float)$value,2,'.',',');
        $group = ['dia'=>'día','semana'=>'semana','mes'=>'mes'][$report['agrupacion']];
        $generated = (new DateTimeImmutable('now',new DateTimeZone('America/Guatemala')))->format('d/m/Y H:i');
        $chart = self::chart($report['serie']);
        ob_start();
        try { require __DIR__.'/../templates/report.php'; $html = ob_get_contents(); }
        finally { ob_end_clean(); }
        $options = new Options();
        $options->set('isRemoteEnabled',false); $options->set('isPhpEnabled',false); $options->set('isJavascriptEnabled',false);
        $options->set('defaultFont','DejaVu Sans'); $options->set('chroot',__DIR__.'/../templates');
        $pdf = new Dompdf($options); $pdf->setPaper('A4'); $pdf->loadHtml($html,'UTF-8'); $pdf->render();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans','normal');
        $pdf->getCanvas()->page_text(40,810,'FerreSync - Reporte de ventas',$font,8,[0.4,0.4,0.4]);
        $pdf->getCanvas()->page_text(460,810,'Página {PAGE_NUM} de {PAGE_COUNT}',$font,8,[0.4,0.4,0.4]);
        return $pdf->output();
    }

    // Dibuja una versión vectorial de la serie para el PDF, sin servicios externos.
    private static function chart(array $rows): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="700" height="235" viewBox="0 0 700 235">';
        $svg .= '<rect width="700" height="235" fill="white"/>';
        $maximum = max(1,...array_map(static fn($row)=>(float)$row['total'],$rows));
        for($i=0;$i<=4;$i++) {
            $y = 180-$i*40; $label = 'Q '.number_format($maximum*$i/4,2,'.',',');
            $svg .= '<line x1="88" y1="'.$y.'" x2="688" y2="'.$y.'" stroke="#e2e9e6"/>';
            $svg .= '<text x="80" y="'.($y+4).'" text-anchor="end" font-family="sans-serif" font-size="10" fill="#536b65">'.$label.'</text>';
        }
        $width = 600/max(1,count($rows)); $every = max(1,(int)ceil(count($rows)/8));
        foreach($rows as $i=>$row) {
            $height = (float)$row['total']/$maximum*160; $x = 88+$i*$width+$width*.12;
            $svg .= '<rect x="'.$x.'" y="'.(180-$height).'" width="'.($width*.76).'" height="'.$height.'" fill="#197665"/>';
            if($i%$every===0) {
                $label = htmlspecialchars($row['etiqueta'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                if(str_contains($label,' - ')) $label = substr($label,0,10);
                $svg .= '<text x="'.(88+($i+.5)*$width).'" y="200" text-anchor="middle" font-family="sans-serif" font-size="8" fill="#536b65">'.$label.'</text>';
            }
        }
        return 'data:image/svg+xml;base64,'.base64_encode($svg.'</svg>');
    }
}
