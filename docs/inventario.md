# CRUD básico de Inventario

Todas las rutas parten de `http://localhost:8080/api/inventario` y requieren la cabecera `Authorization: Bearer <token>`.

| Método | Ruta | Acción | Rol |
|---|---|---|---|
| GET | `/categorias` | Listar categorías activas | Administrador o cajero |
| POST | `/categorias` | Crear categoría | Administrador |
| GET | `/productos` | Listar productos activos | Administrador o cajero |
| GET | `/productos/{id}` | Consultar producto activo | Administrador o cajero |
| POST | `/productos` | Crear producto | Administrador |
| PUT | `/productos/{id}` | Editar producto activo | Administrador |
| DELETE | `/productos/{id}` | Desactivar producto | Administrador |

Se agregaron únicamente crear/listar categorías porque el producto requiere una categoría existente. La interfaz posterior permite probar estas operaciones en http://localhost:8080; ver interfaz.md. También se conservan los ejemplos de API de este documento.

## Prueba manual en PowerShell

Primero iniciar sesión como en el bloque JWT y guardar el token en `$sesion`. Después:

```powershell
$cabeceras = @{ Authorization = "Bearer $($sesion.access_token)" }
$base = "http://localhost:8080/api/inventario"

# 1. Crear una categoría (usar un nombre que todavía no exista).
$datos = @{ nombre = "Herramientas" } | ConvertTo-Json
$categoria = Invoke-RestMethod -Uri "$base/categorias" -Method Post -Headers $cabeceras -ContentType "application/json" -Body ([System.Text.Encoding]::UTF8.GetBytes($datos))
$categoria.categoria

# Si la categoría ya existe, consultar GET /categorias y usar su id.
# 2. Crear un producto.
$datosProducto = @{
    categoria_id = $categoria.categoria.id
    codigo = "MART-001"
    nombre = "Martillo"
    descripcion = "Martillo de uso general"
    precio = "45.50"
    stock = "10.000"
    stock_minimo = "2.000"
}
$json = $datosProducto | ConvertTo-Json
$respuesta = Invoke-RestMethod -Uri "$base/productos" -Method Post -Headers $cabeceras -ContentType "application/json" -Body ([System.Text.Encoding]::UTF8.GetBytes($json))
$idProducto = $respuesta.producto.id
$respuesta.producto

# 3. Listar y consultar.
(Invoke-RestMethod -Uri "$base/productos" -Headers $cabeceras).productos
(Invoke-RestMethod -Uri "$base/productos/$idProducto" -Headers $cabeceras).producto

# 4. Editar. PUT recibe todos los campos del producto, salvo descripcion que es opcional.
$datosProducto.precio = "48.00"
$json = $datosProducto | ConvertTo-Json
(Invoke-RestMethod -Uri "$base/productos/$idProducto" -Method Put -Headers $cabeceras -ContentType "application/json" -Body ([System.Text.Encoding]::UTF8.GetBytes($json))).producto

# 5. Desactivar. El producto se conserva en MariaDB con activo=0.
Invoke-RestMethod -Uri "$base/productos/$idProducto" -Method Delete -Headers $cabeceras
```

Si el token vence, repetir el login y reconstruir `$cabeceras`. No compartir el token ni la contraseña.

## Reglas básicas

- Código de producto y nombre de categoría únicos: un duplicado devuelve 409.
- `categoria_id`, `nombre`, `precio`, `stock` y `stock_minimo` son obligatorios al crear y editar. `codigo` es opcional: al crear vacío/omitido se genera PR-id; al editar vacío se conserva el actual. `descripcion` es opcional; al omitirse se guarda como NULL.
- Precio: no negativo, hasta dos decimales. Stock y mínimo: unidades enteras no negativas. Se conservan las columnas DECIMAL anteriores; la API rechaza fracciones distintas de cero. Los precios se muestran con Q en la interfaz.
- El stock inicial y los cambios de stock se registran en `movimientos_stock`. El producto y el movimiento se guardan en la misma transacción. No hay una pantalla ni rutas adicionales de movimientos en este bloque.
- Editar con PUT reemplaza los campos indicados; si se modifica stock, se establece el valor absoluto enviado. No es una operación de venta ni un descuento incremental.
- DELETE desactiva; no borra el registro ni sus movimientos. Un producto inactivo ya no aparece en consultas y devuelve 404 al consultarlo o intentar editarlo/desactivarlo nuevamente. No se implementó reactivación.
- Sin token: 401. Cajero intentando cambiar datos: 403. Producto inexistente: 404. Datos inválidos: 422. Métodos no admitidos: 405.
- Los límites de los textos se comprueban en bytes UTF-8: código 64, nombre 150 y descripción 4000 (categoría: 100).
- El bloque posterior agregó búsqueda, filtros y paginación; consultar inventario-consultas.md.

## Archivos y validación

El enrutamiento está en `services/inventario-service/public/index.php`. Las consultas preparadas y validaciones están en `services/inventario-service/src/Products.php`. No se modificaron las tablas, Compose ni la configuración de Nginx para este CRUD. Se reconstruyó solo Inventario y se recargó Nginx para resolver su contenedor.

Para repetir las pruebas desde la raíz del proyecto:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-inventario.ps1
```

Las 38 comprobaciones pasaron el 20 de octubre de 2026. Verifican CRUD, roles, datos inválidos, duplicados, movimientos decimales y rollback. El script ahora crea dos usuarios temporales existentes en Autenticación, emite sus tokens y los elimina al finalizar, porque Inventario también verifica la identidad mediante REST. Los registros temporales de inventario se eliminan al finalizar; pueden quedar saltos en los identificadores.

## Consultas e imágenes agregadas

El listado incorpora buscar, categoria_id, stock_bajo, pagina y limite. Se agregaron GET /api/inventario/alertas y POST /api/inventario/productos/{id}/imagen, y controles correspondientes en la interfaz. Guía, migración y verificaciones en [inventario-consultas.md](inventario-consultas.md).
