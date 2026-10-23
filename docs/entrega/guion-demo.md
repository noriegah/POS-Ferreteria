# Guion de demostración de diez minutos

| Minuto | Qué mostrar | Explicación sencilla |
| --- | --- | --- |
| 0–1 | Login y Dashboard | FerreSync resuelve la caja y el inventario de una ferretería. |
| 1–2 | Compose y diagrama | Cuatro servicios independientes; Ventas tiene dos instancias. Nginx enruta y balancea. |
| 2–3 | Usuarios | Admin y cajero tienen permisos distintos; bcrypt y JWT protegen el acceso. |
| 3–4 | Productos | Categoría, imagen, precio en Q y cantidades enteras. |
| 4–6 | Caja y factura | Registrar una venta de prueba: Ventas llama a Inventario por REST y genera PDF con IVA y QR. |
| 6–7 | Historial | El admin corrige comprador o anula; se conserva el comprobante y vuelve el stock. |
| 7–8 | Reportes | Filtros, agrupación diaria/semanal/mensual, rankings y PDF. |
| 8–9 | Adminer y réplica | Escritura en primaria y lectura de reportes desde réplica. Mostrar clientes, ventas, detalles y pagos. |
| 9–10 | Pruebas y cierre | Mostrar las comprobaciones, el código comentado y reconocer los pendientes externos. |

Usar datos de demostración, nunca enseñar .env ni claves privadas. Antes de la presentación final se debe completar el pago Sandbox, validar el clima con una clave propia y comprobar la URL pública. Crear cuentas de demostración desde Usuarios; no publicar contraseñas personales en GitHub.
