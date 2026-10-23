<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><style>
/* Plantilla de impresión: tablas y medidas sencillas, independientes de Bootstrap. */
@page { margin: 35px 38px 48px; }
body { font-family: 'DejaVu Sans',sans-serif; font-size: 10px; color: #172e36; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
td,th { vertical-align: top; overflow-wrap: break-word; }
.header { border-bottom: 3px solid #17695f; padding-bottom: 16px; margin-bottom: 20px; }
.brand { font-size: 23px; font-weight: bold; color: #15564e; margin: 0 0 8px; }
.right { text-align: right; }
.muted { color: #687b7f; font-size: 9px; }
.title { font-size: 18px; font-weight: bold; margin-bottom: 8px; }
.box { border: 1px solid #ccd9d5; padding: 12px; margin-bottom: 18px; }
.box p { margin: 4px 0; }
.label { font-size: 9px; color: #58706c; font-weight: bold; margin-bottom: 8px; }
.items th { background: #eaf1ee; padding: 10px 7px; border-bottom: 1px solid #acbeb8; font-size: 9px; }
.items td { padding: 10px 7px; border-bottom: 1px solid #dbe3df; }
.items thead { display: table-header-group; }
.items tr { page-break-inside: avoid; }
.summary { margin-top: 22px; page-break-inside: avoid; }
.totals td { padding: 7px; }
.total { background: #eaf1ee; font-size: 14px; font-weight: bold; }
.reference { font-size: 8px; color: #566d68; word-wrap: break-word; }
.note { border-top: 1px solid #d4dfda; margin-top: 24px; padding-top: 10px; font-size: 9px; color: #647771; }
</style></head><body>
<table class="header"><tr><td style="width:58%">
    <div class="brand"><?= $escape($issuer['nombre']) ?></div>
    <?php if($issuer['direccion']): ?><div><?= $escape($issuer['direccion']) ?></div><?php endif ?>
    <?php if($issuer['nit']): ?><div>NIT: <?= $escape($issuer['nit']) ?></div><?php endif ?>
    <div class="muted">Sistema de punto de venta · Proyecto universitario</div>
</td><td class="right"><div class="title">FACTURA</div>
    <strong><?= $escape($invoice['numero']) ?></strong><br>
    Fecha: <?= $escape($date) ?><br>Moneda: GTQ - Quetzales
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
    <?php if($qr): ?><img src="<?= $qr ?>" width="122" height="122" alt="QR de referencia"><br><?php else: ?><p>QR no disponible</p><?php endif ?>
    <span class="reference">Referencia de la factura:<br><?= $escape($invoice['referencia']) ?></span>
</td><td><table class="totals">
    <tr><td>Subtotal sin IVA</td><td class="right"><?= $money($invoice['subtotal']) ?></td></tr>
    <tr><td>IVA incluido (12 %)</td><td class="right"><?= $money($invoice['iva']) ?></td></tr>
    <tr class="total"><td>TOTAL</td><td class="right"><?= $money($invoice['total']) ?></td></tr>
</table><p class="muted">Los precios incluyen IVA del 12 %.</p></td></tr></table>
<div class="note">Comprobante académico del proyecto. Documento sin certificación FEL.</div>
</body></html>
