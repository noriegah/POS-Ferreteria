# Factura PDF y datos del comprador

En Caja, **Imprimir factura** descarga un archivo `FAC-000001.pdf`. Después se puede abrir e imprimir. El servidor genera el PDF con Dompdf; el navegador envía el JWT en la cabecera, sin incluirlo en enlaces.

## Comprador

- Menos de Q 2,500.00: se permite nombre **CF**, sin NIT ni dirección. También se puede elegir comprador con NIT.
- Desde Q 2,500.00, incluyendo el importe exacto: NIT, nombre y dirección son obligatorios.
- El NIT se revisa por formato y se guarda sin guion. No se consulta su existencia en SAT.

El formulario muestra los campos cuando corresponden. Inventario comprueba el total usando los precios actuales antes de confirmar el descuento: si faltan los datos, la operación se revierte sin mover stock. Ventas guarda al comprador junto con la venta. Reintentar la misma referencia con otro comprador devuelve 409. Las ventas anteriores a la migración quedan como CF.

`POST /api/ventas/` conserva los campos de la venta y admite:

```json
"comprador": {
  "nombre": "Cliente de ejemplo",
  "nit": "1234567-8",
  "direccion": "Guatemala"
}
```

Para CF se puede omitir `comprador`. Se normaliza como `{"nombre":"CF","nit":null,"direccion":null}`. No se envían estos datos personales al proveedor del QR: solo recibe número y referencia de factura.

## PDF y consultas

`GET /api/ventas/{id}/factura` devuelve los datos e incluye `comprador`. `GET /api/ventas/{id}/factura/pdf` devuelve `application/pdf` como archivo adjunto. Ambos requieren `Authorization: Bearer <token>`. El administrador consulta cualquier venta y el cajero solo las propias. Una consulta no registra otra venta ni descuenta stock.

El PDF A4 muestra emisor, número FAC, fecha de Guatemala, comprador, productos históricos, cantidades enteras, importes en Q, desglose del IVA y QR. Los encabezados de la tabla se repiten cuando hay varias páginas. Si el proveedor del QR falla, se permite generar el PDF sin esa imagen.

Los precios incluyen IVA del 12 %. Por ejemplo, Q 112.00 se desglosan en base Q 100.00 e IVA Q 12.00; no se aumenta el precio al facturar. El cálculo usa centavos y redondeo sobre el total.

La apariencia toma como referencia una factura, pero este es un **comprobante académico sin certificación FEL**. No genera autorizaciones SAT ni firmas fiscales. Los datos reales del emisor se configuran en `services/ventas-service/config/invoice.php`; no se copiaron los de la factura de ejemplo.

## Archivos y actualización

- `db-master/migrations/004-comprador-factura.sql`: agrega los datos del comprador sin borrar ventas.
- `services/ventas-service/src/Buyer.php`: normalización y validaciones sencillas.
- `services/ventas-service/src/InvoicePdf.php`: genera el PDF.
- `services/ventas-service/templates/invoice.php`: diseño del documento.
- `services/ventas-service/config/invoice.php`: datos del emisor.
- `frontend/js/invoice-pdf.js`: descarga autenticada.
- `tests/ventas-integration.php`: ventas, comprador, permisos y PDF.
- `tests/integrations/invoice-pdf.php`: ejemplos CF, con NIT y con 50 líneas.

Ejecutar las migraciones con `./scripts/migrate.ps1`, reconstruir Inventario y Ventas con `docker compose up -d --build --no-deps inventario-service ventas-service` y recargar el navegador con Ctrl + F5. Dompdf y GD se instalan en la imagen de Ventas; no hace falta instalar PHP en Windows.

## Validación realizada

Pasaron las 77 comprobaciones de Ventas: CF en Q 2,499.99, rechazo sin datos desde Q 2,500.00 sin descontar stock, comprador completo, reintentos, permisos y respuesta PDF. Se revisaron visualmente ejemplos de una y cuatro páginas. En el navegador se comprobó el formulario obligatorio y la descarga `FAC-000095.pdf` al pulsar Imprimir factura. Los registros de prueba se retiran al finalizar.


## Consulta desde el historial

Tras descargar la factura se oculta el comprobante para dejar Caja lista. Para consultar ventas anteriores y recuperar sus PDF, utilizar Historial de ventas. Ver [historial-ventas.md](historial-ventas.md).
