<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><style>
/* Diseño de impresión separado de Bootstrap para que el PDF sea estable. */
@page { margin: 48px 38px 50px; }
body { font-family:'DejaVu Sans',sans-serif; font-size:9px; color:#243b35; }
h1 { color:#155c50; font-size:22px; margin:0 0 8px; }
h2 { color:#155c50; font-size:13px; margin:22px 0 10px; }
p { margin:5px 0; }
.header { border-bottom:3px solid #197665; padding-bottom:12px; }
.muted { color:#64776e; font-size:8px; }
table { width:100%; border-collapse:collapse; }
th { background:#e9f1ed; text-align:left; }
td,th { padding:7px 5px; border-bottom:1px solid #dce5e0; vertical-align:top; word-wrap:break-word; }
thead { display:table-header-group; }
tr { page-break-inside:avoid; }
.right { text-align:right; white-space:nowrap; }
.metrics { margin:16px 0; table-layout:fixed; }
.metrics td { background:#edf4ef; border:3px solid white; padding:10px; }
.metrics strong { display:block; margin-top:5px; font-size:13px; }
.chart { width:100%; height:auto; }
.section { page-break-before:always; }
.keep { page-break-inside:avoid; }
.totals td { background:#edf4ef; font-weight:bold; }
</style></head><body>
<div class="header"><h1>FerreSync</h1><p><strong>Reporte de ventas</strong></p>
<p>Del <?= $escape($report['periodo']['desde']) ?> al <?= $escape($report['periodo']['hasta']) ?> · Hora de Guatemala</p>
<p class="muted">Generado: <?= $escape($generated) ?> · Importes con IVA incluido · Moneda: GTQ</p></div>
<table class="metrics"><tr>
<td>Total vendido<strong><?= $money($report['resumen']['total']) ?></strong></td>
<td>Ventas<strong><?= (int)$report['resumen']['ventas'] ?></strong></td>
<td>Promedio por venta<strong><?= $money($report['resumen']['promedio']) ?></strong></td>
<td>Unidades<strong><?= (int)$report['resumen']['unidades'] ?></strong></td>
</tr></table>
<div class="keep"><h2>Ventas por <?= $escape($group) ?></h2><img class="chart" src="<?= $escape($chart) ?>" alt="Gráfica del total vendido"></div>
<?php if(!$report['resumen']['ventas']): ?><p>No hay ventas registradas en este período.</p><?php endif ?>
<h2>Categorías más vendidas</h2>
<table><thead><tr><th>Categoría</th><th class="right">Unidades</th><th class="right">Importe</th></tr></thead><tbody>
<?php foreach($report['categorias'] as $row): ?><tr><td><?= $escape($row['nombre']) ?></td><td class="right"><?= (int)$row['unidades'] ?></td><td class="right"><?= $money($row['total']) ?></td></tr><?php endforeach ?>
<?php if(!$report['categorias']): ?><tr><td colspan="3">Sin ventas.</td></tr><?php endif ?></tbody></table>
<p class="muted">Primeras 8 por unidades. Las ventas antiguas pueden figurar sin categoría registrada.</p>
<h2>Productos más vendidos</h2>
<table style="table-layout:fixed"><thead><tr><th style="width:18%">Código</th><th style="width:42%">Producto</th><th class="right" style="width:15%">Unidades</th><th class="right" style="width:25%">Importe</th></tr></thead><tbody>
<?php foreach($report['productos'] as $row): ?><tr><td><?= $escape($row['codigo']) ?></td><td><?= $escape($row['nombre']) ?></td><td class="right"><?= (int)$row['unidades'] ?></td><td class="right"><?= $money($row['total']) ?></td></tr><?php endforeach ?>
<?php if(!$report['productos']): ?><tr><td colspan="4">Sin ventas.</td></tr><?php endif ?></tbody></table>
<p class="muted">Primeros 8 por unidades. El total vendido no representa ganancia.</p>
<h2 class="section">Detalle de ventas por <?= $escape($group) ?></h2>
<p class="muted">Se incluyen solamente los días del filtro. Las semanas comienzan el lunes.</p>
<table><thead><tr><th>Período</th><th class="right">Ventas</th><th class="right">Total</th></tr></thead><tbody>
<?php foreach($report['serie'] as $row): ?><tr><td><?= $escape($row['etiqueta']) ?></td><td class="right"><?= (int)$row['ventas'] ?></td><td class="right"><?= $money($row['total']) ?></td></tr><?php endforeach ?>
</tbody></table>
<h2 class="section">Ventas registradas</h2>
<p><?= count($report['ventas']) ?> ventas del período completo. Orden: más recientes primero.</p>
<table style="table-layout:fixed"><thead><tr><th style="width:19%">Factura</th><th style="width:25%">Fecha y hora</th><th style="width:31%">Comprador</th><th class="right" style="width:25%">Total</th></tr></thead><tbody>
<?php foreach($report['ventas'] as $row): ?><tr><td>FAC-<?= str_pad((string)$row['id'],6,'0',STR_PAD_LEFT) ?></td><td><?= $escape($row['fecha']) ?></td><td><?= $escape($row['comprador_nombre']) ?></td><td class="right"><?= $money($row['total']) ?></td></tr><?php endforeach ?>
<?php if(!$report['ventas']): ?><tr><td colspan="4">Sin ventas.</td></tr><?php endif ?>
<tr class="totals"><td colspan="3">Total del período</td><td class="right"><?= $money($report['resumen']['total']) ?></td></tr>
</tbody></table>
</body></html>
