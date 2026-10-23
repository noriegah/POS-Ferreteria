# Endpoints principales

Todas las rutas se publican detrás de Nginx. Para las rutas protegidas se envía Authorization: Bearer JWT. Los cuerpos de alta y modificación usan Content-Type: application/json. Los permisos se comprueban en el servidor con el rol vigente.

| Método | Ruta | Acceso y propósito |
| --- | --- | --- |
| POST | /api/auth/login | Público; email y password; devuelve JWT |
| GET | /api/auth/me | Usuario activo; perfil actual |
| POST | /api/auth/verify | Usuario activo; valida JWT e identidad |
| POST | /api/auth/register | Admin; crea cuenta con nombre, email, password y rol |
| GET | /api/auth/users | Admin; lista cuentas sin contraseñas |
| PATCH | /api/auth/users/{id} | Admin; cambia rol y activo de otra cuenta |
| GET | /api/inventario/productos | Ambos; filtros y paginación |
| POST | /api/inventario/productos | Admin; crea producto |
| GET | /api/inventario/productos/{id} | Ambos; consulta producto |
| PUT | /api/inventario/productos/{id} | Admin; actualiza producto |
| DELETE | /api/inventario/productos/{id} | Admin; desactiva producto |
| POST | /api/inventario/productos/{id}/imagen | Admin; imagen multipart |
| GET | /api/inventario/categorias | Ambos; lista categorías |
| POST | /api/inventario/categorias | Admin; crea categoría |
| GET | /api/inventario/alertas | Ambos; stock menor que 5 |
| POST | /api/ventas/ | Ambos; registra compra con referencia única y productos |
| GET | /api/ventas/ | Admin: todas; cajero: propias; filtros de fecha y vendedor |
| GET | /api/ventas/{id} | Admin o propietario; consulta venta |
| PATCH | /api/ventas/{id} | Admin; corrige comprador con motivo |
| POST | /api/ventas/{id}/anular | Admin; anula y devuelve stock |
| GET | /api/ventas/{id}/eventos | Admin; historial administrativo |
| GET | /api/ventas/{id}/factura | Admin o propietario; datos de factura e IVA |
| GET | /api/ventas/{id}/factura/pdf | Admin o propietario; PDF |
| GET | /api/ventas/{id}/factura/qr | Admin o propietario; PNG |
| GET | /api/reportes/resumen | Admin; estadísticas completas |
| GET | /api/reportes/ventas | Admin; período, serie y listado |
| GET | /api/reportes/top-productos | Admin; ranking |
| GET | /api/reportes/pdf | Admin; exportación PDF |
| GET | /api/reportes/clima | Admin; clima o estado de configuración pendiente |

Reportes acepta desde y hasta como AAAA-MM-DD, agrupacion como dia/semana/mes y pagina. El historial acepta desde, hasta, vendedor_id y pagina. Las fechas se interpretan en America/Guatemala.

## Ejemplos administrativos

PATCH /api/ventas/90001 (reemplazar el número por una venta de demostración):

```json
{"comprador":{"nombre":"Cliente de demostración","nit":"12345678","direccion":"Ciudad"},"motivo":"Corregir datos del comprador"}
```

POST /api/ventas/90001/anular:

```json
{"motivo":"Venta duplicada durante la demostración"}
```

PATCH /api/auth/users/12:

```json
{"rol":"cajero","activo":1}
```

No ejecutar estos ejemplos sobre ventas reales para practicar. Usar fixtures o una instalación de demostración.

## Respuestas

200 consulta/modificación, 201 creación, 400 JSON inválido, 401 autenticación requerida o cuenta inactiva, 403 permiso insuficiente, 404 recurso no encontrado o ajeno al cajero, 405 método incorrecto, 409 conflicto/estado incompatible, 415 formato incorrecto, 422 validación y 503 dependencia no disponible. Los errores devuelven un objeto JSON con error. PDF y QR devuelven sus tipos binarios correspondientes.

## Rutas internas

Inventario ofrece POST /ventas/descontar y POST /ventas/devolver para Ventas. Requieren JWT y X-Ventas-Key; devolver exige además administrador. El cliente no decide las cantidades a devolver: Inventario las obtiene del descuento guardado. Ventas ofrece /reportes/resumen y /reportes/exportar para Reportes, con permiso de administrador. No se incluyen claves internas en el frontend ni en esta guía.
