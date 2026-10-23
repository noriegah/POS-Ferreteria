<?php
declare(strict_types=1);
// Casos de presentación con datos ficticios; no crea ventas ni modifica stock.
require '/app/vendor/autoload.php';
require '/app/src/TokenService.php';
require '/app/src/integrations/QrCodeClient.php';
require '/app/src/InvoicePdf.php';
$base=['numero'=>'FAC-DEMO-001','venta_id'=>'0','referencia'=>'00000000-0000-4000-8000-000000000001',
    'fecha'=>'2026-10-22T18:00:00Z','usuario_id'=>'0','moneda'=>'GTQ','subtotal'=>'100.00','iva'=>'12.00','total'=>'112.00',
    'comprador'=>['nombre'=>'CF','nit'=>null,'direccion'=>null],
    'productos'=>[['producto_id'=>'0','codigo'=>'DEMO-01','nombre'=>'Martillo de demostración','cantidad'=>2,'precio'=>'56.00','subtotal'=>'112.00']]];
$cases=['cf'=>$base];
$identified=$base; $identified['numero']='FAC-DEMO-002'; $identified['referencia']='00000000-0000-4000-8000-000000000002';
$identified['comprador']=['nombre'=>'Cliente de demostración','nit'=>'12345678','direccion'=>'Dirección de prueba, Guatemala'];
$identified['subtotal']='2232.14'; $identified['iva']='267.86'; $identified['total']='2500.00';
$identified['productos']=[['producto_id'=>'0','codigo'=>'DEMO-02','nombre'=>'Juego de herramientas de demostración','cantidad'=>1,'precio'=>'2500.00','subtotal'=>'2500.00']];
$cases['nit']=$identified;
$long=$identified; $long['numero']='FAC-DEMO-003'; $long['referencia']='00000000-0000-4000-8000-000000000003'; $long['productos']=[];
for($i=1;$i<=50;$i++) $long['productos'][]=['producto_id'=>(string)$i,'codigo'=>'DEMO-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'nombre'=>"Producto de prueba $i con descripción larga para revisar el salto de página, acentos y detalle de la ferretería",'cantidad'=>1,'precio'=>'50.00','subtotal'=>'50.00'];
$cases['long']=$long;
foreach($cases as $name=>$invoice) {
    try {
        $pdf=InvoicePdf::generate($invoice);
        if(!str_starts_with($pdf,'%PDF-')) throw new RuntimeException('Invalid PDF');
        file_put_contents('/tmp/invoice-'.$name.'.pdf',$pdf);
        echo "PASS: PDF generated: $name\n";
    } finally {
        $file='/app/storage/facturas-qr/'.hash('sha256',QrCodeClient::content($invoice)).'.png';
        if(is_file($file)) unlink($file);
    }
}
