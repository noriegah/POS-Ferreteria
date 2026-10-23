# Interfaz local — inicio de sesión e inventario

Abrir http://localhost:8080 e ingresar con el correo y contraseña del administrador creado anteriormente. Se usan HTML, JavaScript y la plantilla AdminHMD con Bootstrap 5.3.8. Los estilos e iconos se sirven localmente, sin depender de un CDN.

## Uso

1. Iniciar sesión. El administrador ve los formularios y la tabla; el cajero solo consulta productos.
2. Crear una categoría si todavía no hay ninguna. Queda seleccionada automáticamente.
3. Completar código, nombre, categoría, precio, existencias y stock mínimo; descripción es opcional. Pulsar Guardar producto.
4. En la tabla, pulsar Editar para cargar los datos y Guardar cambios para actualizarlos. Cancelar edición vuelve al formulario de creación.
5. Desactivar pide confirmación y retira el producto de la lista, conservando su registro en MariaDB.
6. Actualizar lista vuelve a consultar los productos y categorías. Cerrar sesión descarta el token.

La contraseña no se guarda. El JWT se conserva solamente en memoria: al recargar la página hay que iniciar sesión de nuevo. Si una petición devuelve 401, se regresa al formulario de acceso. La duración actual del token es de 15 minutos. Los permisos también se comprueban en PHP, no solo ocultando botones en pantalla.

## Archivos

- `frontend/index.html`: formularios, tabla y enlaces a los cuatro health de la Fase 1.
- `frontend/styles.css`: ajustes propios sobre los estilos originales de AdminHMD, espacios y adaptación a pantallas pequeñas.
- `frontend/app.js`: login, peticiones a la API, presentación de datos y acciones de productos/categorías.

Los tres archivos tienen comentarios sencillos en español. Se usa textContent para presentar textos recibidos, evitando interpretarlos como HTML. Nginx ya monta frontend en modo de lectura; no hace falta reconstruir contenedores al cambiar estos archivos. Recargar el navegador permite ver los cambios.

## Validación local

Probado en el navegador el 20 de octubre de 2026: login de administrador, categoría, creación de producto, consulta en tabla, carga de edición, cambio de precio y existencias, confirmación y desactivación, cierre de sesión, contraseña incorrecta, acceso de cajero sin formularios de escritura y regreso al login después de recargar. Los archivos HTML, CSS y JavaScript responden HTTP 200, al igual que los cuatro health.

Se eliminaron las dos cuentas temporales, la categoría, el producto y sus movimientos usados durante la prueba. La cuenta del administrador del proyecto se conservó. Este bloque agrega únicamente la interfaz; utiliza la API, las tablas y la infraestructura existentes.
## Adaptación a AdminHMD

Se integró el paquete original adminHMD v1.0.0 descargado de ThemeWagon. Los archivos utilizados están en `frontend/vendor/adminhmd/`: Bootstrap, los estilos de la plantilla y Bootstrap Icons con sus fuentes. El README original y CREDITOS.md conservan la autoría y la declaración de licencia del paquete. Los archivos de terceros se mantienen sin modificar; los comentarios en español están en los archivos propios del POS.

El menú contiene Productos, Registrar producto y Categorías. El cajero solo ve Productos. No se agregaron pantallas de módulos aún pendientes ni datos ficticios del dashboard original. Un pequeño bloque comentado al final de app.js controla el menú: reducido en escritorio, desplegable en móvil y cierre con Escape. Al salir se ocultan el menú y la identidad de la sesión.

Pruebas del cambio: acceso de administrador/cajero, categoría temporal, crear/editar/desactivar producto temporal, cierre de sesión, navegación lateral a los formularios, menú móvil a 390 px y menú de escritorio a 1280 px. Sin desbordamiento horizontal de la página en los tamaños comprobados; la tabla permite desplazamiento interno. Sintaxis JavaScript y carga HTTP de estilos y fuentes correctas. Los cuatro health respondieron 200. Se eliminaron las dos cuentas y todos los datos temporales de esta comprobación; se conservaron los productos y cuentas existentes.

La interfaz anterior se guardó en `.backups/frontend-before-adminhmd-*`. No se cambiaron PHP, tablas, JWT, Compose ni la configuración de Nginx. Si el navegador conserva estilos antiguos, usar Ctrl+F5. Los enlaces propios CSS/JS llevan una versión para ayudar a cargar esta actualización.

## Sección Usuarios

El menú del administrador incorpora Usuarios: formulario de registro (nombre, correo, contraseña y rol) y listado de cuentas. El cajero no ve esta sección y PHP rechaza las operaciones sin permiso. Se conserva AdminHMD y no se aplican todavía ajustes visuales adicionales. Ver usuarios.md.


## Filtros alertas e imágenes

La tabla agrega búsqueda por nombre/código, categoría, stock menor que 5 y paginación de 10 registros. El administrador puede elegir imágenes opcionales al crear o editar. Los cajeros consultan imágenes y alertas. Los archivos se guardan localmente y Nginx los sirve desde un montaje separado. Ver inventario-consultas.md.


## Caja y formato de cantidades

El menú Caja / Ventas incorpora búsqueda, carrito y registro de venta. Precios y totales usan Q con dos decimales; existencias, mínimo y cantidades se muestran como enteros. Los formularios y la API rechazan fracciones de unidad. Ver [ventas.md](ventas.md) para las rutas y el manejo de reintentos.


## Navegación por módulos e identidad visual

El administrador inicia en Dashboard; el cajero en Caja. Cada módulo se presenta por separado. Categorías tiene pantalla propia y los productos pueden recibir un código automático al guardar. Cambios y validaciones en [frontend-modulos.md](frontend-modulos.md).
