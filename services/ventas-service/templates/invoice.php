<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><style>
@page { margin: 32px 34px 46px; }
body { font-family: 'DejaVu Sans',sans-serif; font-size: 9px; color: #202c39; }
table { width:100%; border-collapse:collapse; table-layout:fixed; }
td,th { vertical-align:top; overflow-wrap:break-word; }
.header { border-top:4px solid #11628c; border-bottom:1px solid #9ca9b5; margin-bottom:16px; }
.header td { padding:14px 8px; }
.brand {font-size:17px; font-weight:bold; color:#11628c; margin-bottom:7px;}
.right {text-align:right;}
.muted {color:#617080;font-size:8px;}
.title {font-size:21px;font-weight:bold;color:#11628c;margin-bottom:9px;}
.box {border:1px solid #bfc8d1; padding:11px 12px; margin-bottom:17px; background:#f7f9fb;}
.box p {margin:5px 0;}
.label {font-size:8px; color:#11628c;font-weight:bold;margin-bottom:8px;}
.items th {background:#eaf0f5;color:#24516f;padding:9px 7px;border:1px solid #bdc8d2;font-size:8px;}
.items td {padding:10px 7px;border:1px solid #d4dce3;}
.items thead {display:table-header-group;}
.items tr {page-break-inside:avoid;}
.summary {margin-top:20px;page-break-inside:avoid;}
.totals td {padding:9px 8px;border-bottom:1px solid #d4dce3;}
.total {background:#eaf0f5;color:#125c85;font-size:13px;font-weight:bold;}
.reference {font-size:7px;color:#657383;word-wrap:break-word;}
.note {border-top:1px solid #bdc8d2;margin-top:20px;padding-top:9px;font-size:8px;color:#657383;}
a {color:#11628c;text-decoration:none;}
</style></head><body>
<table class="header"><tr><td style="width:58%">
    <div class="brand"><?= $escape($issuer['nombre']) ?></div>
    <?php if($issuer['direccion']): ?><div><?= $escape($issuer['direccion']) ?></div><?php endif ?>
    <?php if($issuer['nit']): ?><div>NIT: <?= $escape($issuer['nit']) ?></div><?php endif ?>

</td><td class="right"><div class="title">FACTURA</div>
    <strong><?= $escape($invoice['numero']) ?></strong><br>
    Fecha: <?= $escape($date) ?><br>Moneda: GTQ - Quetzales<br>Pago: <?= ($invoice['metodo_pago'] ?? 'efectivo')==='tarjeta' ? 'PayPal Sandbox' : 'Efectivo' ?>
</td></tr></table>
<?php if(($invoice['estado'] ?? 'confirmada')!=='confirmada'): ?>
<div class="box" style="border:2px solid #ad3030;color:#ad3030;font-size:16px"><strong><?= $invoice['estado']==='anulada' ? 'COMPROBANTE ANULADO' : 'ANULACIÓN EN PROCESO' ?></strong><p style="font-size:10px"><?= $escape($invoice['anulacion_motivo']) ?></p></div>
<?php endif ?>
<div class="box"><div class="label">DATOS DEL COMPRADOR</div>
    <p><strong>Nombre:</strong> <?= $escape($invoice['comprador']['nombre']) ?></p>
    <?php if($invoice['comprador']['nit']): ?><p><strong>NIT:</strong> <?= $escape($invoice['comprador']['nit']) ?></p><?php endif ?>
    <?php if($invoice['comprador']['direccion']): ?><p><strong>Dirección:</strong> <?= $escape($invoice['comprador']['direccion']) ?></p><?php endif ?>
</div>
<table class="items"><thead><tr>
    <th style="width:14%">Código</th><th style="width:42%">Descripción</th><th style="width:10%" class="right">Cantidad</th><th style="width:16%" class="right">Precio</th><th style="width:18%" class="right">Importe</th>
</tr></thead><tbody>
<?php foreach($invoice['productos'] as $item): ?><tr>
    <td><?= $escape($item['codigo']) ?></td><td><?= $escape($item['nombre']) ?></td><td class="right"><?= (int)$item['cantidad'] ?></td>
    <td class="right"><?= $money($item['precio']) ?></td><td class="right"><?= $money($item['subtotal']) ?></td>
</tr><?php endforeach ?>
</tbody></table>
<table class="summary"><tr><td style="width:47%; padding-right:22px">
    <?php if($qr): ?><img src="<?= $qr ?>" width="122" height="122" alt="QR para validar la factura"><br><?php else: ?><p>QR no disponible</p><?php endif ?>
    <span class="reference">Escanea para consultar la validez y el estado de esta factura.<br><a href="<?= $escape(InvoiceValidation::url($invoice['referencia'])) ?>">Validar factura en línea</a><br>Referencia: <?= $escape($invoice['referencia']) ?></span>
</td><td><table class="totals">
    <tr><td>Subtotal sin IVA</td><td class="right"><?= $money($invoice['subtotal']) ?></td></tr>
    <tr><td>IVA incluido (12 %)</td><td class="right"><?= $money($invoice['iva']) ?></td></tr>
    <tr class="total"><td>TOTAL</td><td class="right"><?= $money($invoice['total']) ?></td></tr>
</table><p class="muted">Los precios incluyen IVA del 12 %.</p></td></tr></table>
<div class="note">Gracias por su compra. · Documento sin certificación FEL.</div>
</body></html>
