# QR de la factura

La factura presenta un QR con este texto:

```text
FerreSync
Factura: FAC-000123
Referencia: 12345678-1234-4234-8234-123456789012
```

Al escanearlo se leen el número y la referencia del comprobante. No es un enlace público a la factura ni una prueba de pago. La consulta completa continúa protegida por JWT. No se comparten con el proveedor usuarios, correos, productos, importes ni tokens.

## Archivos de este bloque

| Archivo o carpeta | Función |
| --- | --- |
| `services/ventas-service/src/integrations/QrCodeClient.php` | Llama por HTTPS a la API externa y conserva el PNG válido. |
| `services/ventas-service/public/index.php` | Ruta protegida `GET /{id}/factura/qr`. |
| `frontend/js/invoice-qr.js` | Carga la imagen mediante el gateway, cancela consultas anteriores y controla los reintentos. |
| `uploads/facturas-qr/` | Imágenes generadas, persistentes y excluidas de Git. |
| `tests/integrations/qr-client.php` | API real, formato PNG, caché y fallo de comunicación. |
| `tests/integrations/qr-http.php` | Permisos de la ruta e integridad de la factura. |
| `scripts/test-qr.ps1` | Ejecuta las pruebas con cuentas temporales y las retira. |

## Flujo

1. La interfaz consulta la factura como antes y la muestra.
2. `invoice-qr.js` solicita `GET /api/ventas/{id}/factura/qr` con Bearer JWT.
3. Ventas valida la firma y el usuario actual mediante Auth. Comprueba que el cajero sea dueño de la venta o que sea administrador.
4. Si existe un PNG válido en su carpeta privada, lo devuelve. Si no existe, llama por cURL a [goQR](https://goqr.me/api/doc/create-qr-code/) con POST, formato PNG, tamaño 240 × 240, corrección M y zona blanca de 4 módulos.
5. Se valida el estado HTTP, el tipo, la firma PNG, sus dimensiones y el final del archivo. La descarga se limita a 256 KiB y a 7 segundos. HTTPS conserva la verificación de certificados y no sigue redirecciones.
6. El archivo se guarda con un hash del texto. Un temporal y `rename` evitan publicar una escritura incompleta.
7. El navegador recibe la imagen autenticada, la muestra y permite imprimir la factura. Nunca hace la petición al proveedor directamente.

La ruta admite solo GET: 200 PNG, 401 sesión inválida, 404 factura inexistente o ajena, 405 método no permitido, 422 id cero/fuera de rango y 503 QR no disponible. Otras rutas no reconocidas devuelven 404. Una imagen en caché sigue requiriendo autenticación; Nginx no publica esta carpeta como archivos estáticos.

## Fallos e impresión

Un fallo del proveedor no revierte una venta ni modifica su stock o importes. La factura permanece visible y ofrece **Reintentar QR**. Mientras se prepara la imagen se espera antes de imprimir; si falla, la impresión queda habilitada y muestra el estado sin QR. Al cambiar de factura o cerrar sesión se cancelan las consultas pendientes y se libera la imagen anterior.

Los QR se conservan en `uploads/facturas-qr` con un montaje exclusivo de Ventas. Deben copiarse junto con las fotos y la base al VPS si se quiere conservar la caché. Si faltan, se regeneran con Internet disponible. No hay migraciones ni credenciales nuevas para este bloque.

## Pruebas

Desde la raíz: `./scripts/test-qr.ps1`. Requiere Docker e Internet para generar los QR de demostración. Para comprobar la lectura también puede escanearse la imagen de una factura con un teléfono; el texto debe coincidir con su número y referencia. La aplicación solo utiliza la API de generación, sin depender del lector remoto del proveedor.

Las pruebas crean y retiran cuentas y una venta de demostración. Las pruebas de caché usan `/tmp/qr-test-*`, separada de los datos permanentes. También puede ejecutarse `./scripts/test-ventas.ps1` para repetir las comprobaciones anteriores de carrito, stock e IVA.

## Resultado de esta entrega

Pasaron las pruebas de cliente y de la ruta QR, además de las 59 comprobaciones anteriores de Ventas y factura. Se verificaron caché sin proveedor, regeneración de archivo truncado, JWT, dueño de la venta, administrador, métodos HTTP, carpeta no pública y conservación de los importes.

Se comprobó la imagen de una factura temporal con un lector local independiente (ZXing-C++): el texto coincidió exactamente en 240 × 240 y al reducirlo a los 160 × 160 que se muestran en la interfaz. Ese lector se utilizó solo como herramienta de verificación y no se añadió a las dependencias del proyecto. La API remota de lectura del proveedor rechazó las pruebas de subida/descarga; no forma parte de la aplicación.

En navegador se verificó el QR cargado, el botón de impresión habilitado después de decodificar la imagen y el ancho móvil sin desbordamiento. No se envió un trabajo a una impresora durante estas pruebas. Las facturas, cuentas e imágenes de demostración se retiraron.
