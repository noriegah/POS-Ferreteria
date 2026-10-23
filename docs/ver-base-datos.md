# Ver tablas y datos con Adminer

## Abrir el visor

Desde la raíz, con Docker iniciado:

```powershell
docker compose --profile tools up -d adminer
```

Abre http://localhost:8085 y completa:

| Campo | Valor |
| --- | --- |
| Motor | MySQL / MariaDB |
| Servidor | db-primary |
| Usuario | ferresync_consulta (DB_VIEWER_USER en .env) |
| Contraseña | El valor de DB_VIEWER_PASSWORD en .env |
| Base de datos | Puedes dejarla vacía |

Estas credenciales son de MariaDB, diferentes del usuario con el que entras a FerreSync. No compartas `.env` ni guardes su contraseña en capturas de la entrega.

## Consultar un producto

1. Pulsa Login.
2. Selecciona `inventario_db`.
3. Pulsa `productos` para ver sus columnas y tipos (Mostrar estructura).
4. Pulsa Visualizar contenido para ver los registros. También puedes usar el enlace registros del menú lateral.
5. Usa Condición para filtrar y Ordenar para ordenar los datos.

La cuenta es de consulta: aunque Adminer muestre opciones para modificar o eliminar, MariaDB rechazará esas operaciones. Puede aparecer un aviso de permisos al listar Eventos: no afecta la consulta de tablas, porque esta cuenta no administra eventos.

## Qué contiene cada base

| Base | Contenido |
| --- | --- |
| auth_db | Usuarios y datos de autenticación |
| inventario_db | Productos, categorías y movimientos de existencias |
| ventas_db | Ventas y sus detalles |
| reportes_db | Reservada; los reportes actuales se obtienen por REST desde Ventas |

Para observar la copia, cierra sesión e inicia con los mismos datos cambiando el servidor a `db-replica`. Allí se consulta `ventas_db`, que es la única base replicada.

Los precios se guardan como números: la Q se agrega en la interfaz. Algunas columnas de cantidades conservan el tipo decimal del esquema inicial, aunque la caja valida unidades enteras.

El visor solo se publica en esta computadora (`127.0.0.1:8085`). Para detenerlo: `docker compose --profile tools stop adminer`.
