"use strict";

// El token vive solo en memoria: no guardamos contraseñas ni JWT en localStorage.
let token = "";
let sessionEpoch = 0;
let user = null;
let editingId = null;
let page = 1;
let pages = 1;
let invoiceRequest = 0;
let currentInvoiceId = null;
const $ = (id) => document.getElementById(id);
// Los precios llevan Q y dos decimales; las unidades se muestran como enteros.
const money = (value) => `Q ${Number(value).toLocaleString("es-GT", {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
const units = (value) => Number(value).toLocaleString("es-GT", {maximumFractionDigits: 0});

// Presenta mensajes usando texto, para no interpretar contenido recibido como HTML.
function message(text, error = false) {
    $("message").textContent = text;
    $("message").classList.toggle("alert-danger", error);
    $("message").classList.toggle("alert-success", !error);
    $("message").hidden = !text;
}

SessionActivity.init({
    renew:async()=>{
        const response=await fetch('/api/auth/refresh',{method:'POST',headers:{Authorization:`Bearer ${token}`,Accept:'application/json'},cache:'no-store'});
        const data=await response.json().catch(()=>({}));
        if(!response.ok){const error=new Error(data.error || 'No se pudo renovar la sesión');error.status=response.status;throw error;}
        return data;
    },
    apply:value=>{token=value;},
    onExpire:reason=>{logout();message(reason,true);}
});

// Todas las peticiones utilizan las rutas del mismo Nginx que sirve esta página.
async function api(path, method = "GET", data = null, authenticated = true) {
    const requestSession=sessionEpoch;
    if(authenticated) await SessionActivity.ensure();
    if(authenticated && requestSession!==sessionEpoch) throw new Error("La sesión cambió. Ingresa de nuevo.");
    const headers = { Accept: "application/json" };
    if (authenticated) headers.Authorization = `Bearer ${token}`;
    const multipart = data instanceof FormData;
    // El navegador agrega el límite multipart cuando se envía una foto.
    if (data !== null && !multipart) headers["Content-Type"] = "application/json";
    let response;
    try {
        response = await fetch(`/api/${path}`, {
            method, headers, body: data === null ? undefined : (multipart ? data : JSON.stringify(data)), cache: "no-store"
        });
    } catch (error) {
        throw new Error("No se pudo conectar con el servidor. Intenta de nuevo.");
    }
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && authenticated && requestSession===sessionEpoch) {
            logout();
            throw new Error("La sesión venció o no es válida. Ingresa de nuevo.");
        }
        const error = new Error(body.error || "No se pudo completar la operación.");
        error.status = response.status;
        throw error;
    }
    return body;
}

// Evita enviar dos veces el mismo formulario mientras se espera al servidor.
async function submit(form, task) {
    if (form.dataset.busy === "true") return;
    form.dataset.busy = "true";
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    message("");
    try { await task(); }
    catch (error) { message(error.message, true); }
    finally {
        button.disabled = false; form.dataset.busy = "false";
        if (form.id === "checkout-form") renderCart();
    }
}

// Regresa al acceso y descarta la identidad y los datos de la sesión anterior.
function logout() {
    SessionActivity.stop(); sessionEpoch++;
    closePasswordReset();
    token = "";
    user = null;
    cart = []; pendingSale = null; renderCart();
    $("sales-results").replaceChildren(); $("sales-search-form").reset();
    $("sale-result").hidden = true;
    clearInvoice();
    PayPalCheckout.clear();
    ReportView.clear();
    SalesHistoryView.clear();
    CashRegisterView.clear();
    $("invoice-form").reset();
    $("buyer-type").value="cf";
    for(const id of ["buyer-nit","buyer-name","buyer-address"]) $(id).value="";
    document.body.classList.add("pos-login");
    document.body.classList.remove("sidebar-open", "sidebar-mini");
    $("menu-toggle").hidden = true;
    updateMenu();
    $("inventory-section").hidden = true;
    $("session-panel").hidden = true;
    $("admin-section").hidden = true;
    $("login-section").hidden = false;
    $("password").value = "";
    $("products-body").replaceChildren();
    $("category-form").reset();
    $("user-form").reset();
    $("users-body").replaceChildren();
    $("filter-form").reset();
    $("alerts-panel").hidden = true;
    page = 1; pages = 1;
    resetProduct();
    $("email").focus();
}

// Al cancelar o guardar, el formulario vuelve al modo de crear producto.
function resetProduct() {
    editingId = null;
    $("product-form").reset();
    $("image-current").hidden = true;
    $("current-photo").removeAttribute("src");
    $("product-title").textContent = "Nuevo producto";
    $("save-product").textContent = "Guardar producto";
    $("code-help").textContent = "Puedes escribir o escanear un código existente. Si lo dejas vacío, asignamos uno al guardar.";
    $("cancel-edit").hidden = true;
}

// Carga las categorías y conserva la selección cuando aún existe.
async function loadCategories(selected = $("category").value) {
    const data = await api("inventario/categorias");
    $("category").replaceChildren(new Option("Selecciona una categoría", ""));
    for (const category of data.categorias) {
        $("category").add(new Option(category.nombre, String(category.id)));
    }
    $("category").value = String(selected);
    const filter = $("filter-category").value;
    $("filter-category").replaceChildren(new Option("Todas las categorías", ""));
    for (const category of data.categorias) $("filter-category").add(new Option(category.nombre, String(category.id)));
    $("filter-category").value = filter;
    const salesCategory=$("sales-category").value;
    $("sales-category").replaceChildren(new Option("Todas las categorías", ""));
    for(const category of data.categorias) $("sales-category").add(new Option(category.nombre,String(category.id)));
    $("sales-category").value=salesCategory;
    $("category-help").hidden = data.categorias.length > 0;
    $("product-fields").disabled = data.categorias.length === 0;
    $("category-list").replaceChildren();
    for (const category of data.categorias) {
        const item = document.createElement("li");
        item.textContent = category.nombre;
        $("category-list").append(item);
    }
    $("category-count").textContent = data.categorias.length;
    $("dashboard-categories").textContent = units(data.categorias.length);
}

// Cada celda usa textContent para mostrar incluso nombres que contengan símbolos HTML.
function cell(row, value) {
    const td = document.createElement("td");
    td.textContent = value;
    row.append(td);
    return td;
}

// Muestra únicamente productos activos. El servidor decide qué operaciones permite cada rol.
async function loadProducts() {
    const filters = new URLSearchParams({pagina: String(page), limite: "10", buscar: $("search-products").value.trim()});
    if ($("filter-category").value) filters.set("categoria_id", $("filter-category").value);
    if ($("filter-low").checked) filters.set("stock_bajo", "1");
    const data = await api(`inventario/productos?${filters}`);
    page = data.paginacion.pagina; pages = data.paginacion.paginas;
    $("products-body").replaceChildren();
    const admin = user.rol === "administrador";
    $("actions-heading").hidden = !admin;
    for (const product of data.productos) {
        const row = document.createElement("tr");
        const photo = document.createElement("td");
        if (product.imagen_url) photo.append(productImage(product));
        else photo.textContent = "Sin imagen";
        row.append(photo);
        cell(row, product.codigo);
        cell(row, product.nombre);
        cell(row, product.categoria);
        cell(row, money(product.precio));
        cell(row, units(product.stock));
        cell(row, units(product.stock_minimo));
        if (admin) {
            const td = document.createElement("td");
            const actions = document.createElement("div");
            actions.className = "row-actions";
            const edit = document.createElement("button");
            edit.type = "button";
            edit.className = "btn btn-outline-primary btn-sm";
            edit.textContent = "Editar";
            edit.setAttribute("aria-label", `Editar ${product.nombre}`);
            edit.addEventListener("click", () => editProduct(product.id));
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "btn btn-outline-danger btn-sm";
            remove.textContent = "Desactivar";
            remove.setAttribute("aria-label", `Desactivar ${product.nombre}`);
            remove.addEventListener("click", () => deactivate(product, remove));
            actions.append(edit, remove);
            td.append(actions);
            row.append(td);
        }
        $("products-body").append(row);
    }
    $("product-count").textContent = `${data.productos.length} de ${data.paginacion.total} producto(s)`;
    $("page-label").textContent = `Página ${page} de ${pages}`;
    $("previous-page").disabled = page <= 1;
    $("next-page").disabled = page >= pages;
    $("empty-list").hidden = data.productos.length !== 0;
}

// Consulta el producto por id antes de copiar sus datos al formulario de edición.
async function editProduct(id) {
    message("");
    try {
        const data = await api(`inventario/productos/${id}`);
        const product = data.producto;
        await openView("product-panel");
        editingId = String(product.id);
        $("code").value = product.codigo;
        $("product-name").value = product.nombre;
        $("category").value = String(product.categoria_id);
        $("description").value = product.descripcion || "";
        $("price").value = product.precio;
        $("stock").value = Number(product.stock);
        $("minimum").value = Number(product.stock_minimo);
        $("product-image").value = "";
        $("image-current").hidden = !product.imagen_url;
        if (product.imagen_url) $("current-photo").src = product.imagen_url;
        else $("current-photo").removeAttribute("src");
        $("product-title").textContent = "Editar producto";
        $("view-title").textContent = "Editar producto";
        $("code-help").textContent = "Puedes conservar o cambiar el código. Si lo dejas vacío, se conserva el actual.";
        $("save-product").textContent = "Guardar cambios";
        $("cancel-edit").hidden = false;
        $("product-form").scrollIntoView({ behavior: "smooth", block: "start" });
        $("code").focus({ preventScroll: true });
    } catch (error) { message(error.message, true); }
}

// Pide confirmación y usa DELETE, que en esta API desactiva sin borrar el registro.
async function deactivate(product, button) {
    if (!window.confirm(`¿Desactivar el producto "${product.nombre}"?`)) return;
    button.disabled = true;
    message("");
    try {
        await api(`inventario/productos/${product.id}`, "DELETE");
        if (editingId === String(product.id)) resetProduct();
        await loadProducts();
        await loadAlerts();
        message("Producto desactivado.");
    } catch (error) { message(error.message, true); }
    finally { button.disabled = false; }
}

// Inicia sesión, comprueba el perfil y muestra los formularios según el rol recibido.
$("login-form").addEventListener("submit", (event) => {
    event.preventDefault();
    submit(event.currentTarget, async () => {
        const email = $("email").value.trim();
        const password = $("password").value;
        $("password").value = "";
        const data = await api("auth/login", "POST", { email, password }, false);
        sessionEpoch++; token = data.access_token;
        SessionActivity.start(data.expires_in);
        const profile = await api("auth/me");
        user = profile.user;
        restoreCheckout();
        PayPalCheckout.configure();
        document.body.classList.remove("pos-login");
        $("menu-toggle").hidden = false;
        document.querySelectorAll("[data-admin-only]").forEach((link) => { link.hidden = user.rol !== "administrador"; });
        updateMenu();
        $("user-label").textContent = `${user.nombre} · ${user.rol}`;
        $("login-section").hidden = true;
        $("inventory-section").hidden = false;
        $("session-panel").hidden = false;
        // Al ingresar se abre un solo módulo. El administrador comienza en el resumen.
        await openView(user.rol === "administrador" ? "dashboard-panel" : "sales-panel", true);
    });
});

// POST crea un producto; PUT guarda los cambios cuando se seleccionó uno para editar.
$("product-form").addEventListener("submit", (event) => {
    event.preventDefault();
    submit(event.currentTarget, async () => {
        const product = {
            categoria_id: $("category").value, codigo: $("code").value.trim(),
            nombre: $("product-name").value.trim(), descripcion: $("description").value.trim(),
            precio: $("price").value, stock: $("stock").value, stock_minimo: $("minimum").value
        };
        const file = $("product-image").files[0];
        if (file && file.size > 2 * 1024 * 1024) throw new Error("La imagen no debe superar 2 MB.");
        const editing = editingId !== null;
        const saved = await api(editing ? `inventario/productos/${editingId}` : "inventario/productos", editing ? "PUT" : "POST", product);
        // Primero guarda el producto; después envía su foto, si se seleccionó una.
        let imageError = "";
        if (file) {
            const form = new FormData(); form.append("imagen", file);
            try { await api(`inventario/productos/${saved.producto.id}/imagen`, "POST", form); }
            catch (error) {
                if (!token) throw new Error("El producto se guardó; inicia sesión de nuevo para cargar su imagen.");
                imageError = error.message;
            }
        }
        resetProduct(); page = 1;
        await openView("list-panel"); await loadAlerts();
        if (imageError) message(`Producto guardado, pero no se cargó la imagen: ${imageError}`, true);
        else message(editing ? "Producto actualizado." : `Producto creado con el código ${saved.producto.codigo}.`);
    });
});

// Después de crear una categoría, queda seleccionada para registrar un producto.
$("category-form").addEventListener("submit", (event) => {
    event.preventDefault();
    submit(event.currentTarget, async () => {
        const data = await api("inventario/categorias", "POST", { nombre: $("category-name").value.trim() });
        $("category-form").reset();
        await loadCategories(data.categoria.id);
        message("Categoría creada.");
    });
});

$("cancel-edit").addEventListener("click", () => { resetProduct(); message(""); });
$("logout").addEventListener("click", () => { logout(); message("Sesión cerrada."); });

// Permite volver a consultar los datos sin recargar la página ni perder el token.
$("refresh").addEventListener("click", async () => {
    const button = $("refresh");
    button.disabled = true;
    message("");
    try { await loadCategories(); await loadProducts(); await loadAlerts(); message("Lista actualizada."); }
    catch (error) { message(error.message, true); }
    finally { button.disabled = false; }
});


// Control sencillo del menú de AdminHMD: reducido en computadora y desplegable en teléfono.
const desktopMenu = window.matchMedia("(min-width: 992px)");
function updateMenu() {
    const open = desktopMenu.matches || document.body.classList.contains("sidebar-open");
    $("adminSidebar").inert = !token || !open;
    $("menu-toggle").setAttribute("aria-expanded", String(Boolean(token) && (desktopMenu.matches ? !document.body.classList.contains("sidebar-mini") : open)));
}
function closeMenu() {
    document.body.classList.remove("sidebar-open");
    updateMenu();
}
$("menu-toggle").addEventListener("click", () => {
    document.body.classList.toggle(desktopMenu.matches ? "sidebar-mini" : "sidebar-open");
    updateMenu();
});
document.querySelectorAll("[data-sidebar-close]").forEach((button) => button.addEventListener("click", closeMenu));
document.querySelectorAll(".sidebar-nav a, .brand-mark").forEach((link) => {
    link.addEventListener("click", () => { if (!desktopMenu.matches) closeMenu(); });
});
document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && document.body.classList.contains("sidebar-open")) {
        closeMenu();
        $("menu-toggle").focus();
    }
});
desktopMenu.addEventListener("change", () => {
    document.body.classList.remove("sidebar-open", "sidebar-mini");
    updateMenu();
});


// Solo el administrador puede consultar la lista; PHP también comprueba este permiso.
async function loadUsers() {
    const data = await api("auth/users");
    $("users-body").replaceChildren();
    for (const account of data.usuarios) {
        const row = document.createElement("tr");
        cell(row, account.nombre);
        cell(row, account.email);
        const roleCell=cell(row,''); const role=document.createElement('select'); role.className='form-select form-select-sm'; role.setAttribute('aria-label',`Rol de ${account.nombre}`);
        for(const value of ['cajero','administrador']) { const option=document.createElement('option'); option.value=value; option.textContent=value; role.append(option); }
        role.value=account.rol; roleCell.append(role);
        const stateCell=cell(row,''); const state=document.createElement('select'); state.className='form-select form-select-sm'; state.setAttribute('aria-label',`Estado de ${account.nombre}`);
        for(const [value,label] of [['1','Activo'],['0','Inactivo']]) { const option=document.createElement('option'); option.value=value; option.textContent=label; state.append(option); }
        state.value=String(account.activo); stateCell.append(state);
        const action=cell(row,''); const save=document.createElement('button'); save.type='button'; save.className='btn btn-outline-primary btn-sm'; save.textContent='Guardar acceso'; action.append(save);
        const own=String(account.id)===String(user.id); role.disabled=own; state.disabled=own; save.disabled=own;
        if(own) save.title='Otro administrador debe cambiar tu acceso';
        save.addEventListener('click',async()=>{
            if(!window.confirm(`¿Guardar el rol y estado de ${account.nombre}?`)) return;
            save.disabled=true;
            try { await api(`auth/users/${account.id}`,'PATCH',{rol:role.value,activo:Number(state.value)}); await loadUsers(); }
            catch(error) { message(error.message,true); save.disabled=false; }
        });
        const reset=document.createElement('button'); reset.type='button'; reset.className='btn btn-outline-secondary btn-sm ms-2'; reset.textContent='Restablecer contraseña';
        reset.addEventListener('click',()=>openPasswordReset(account)); action.append(reset);
        $("users-body").append(row);
    }
}

let resetPasswordAccount=null;
function closePasswordReset() {
    $("reset-password-dialog").close(); $("reset-password-form").reset();
    $("reset-password-message").textContent=''; resetPasswordAccount=null;
}
function openPasswordReset(account) {
    if(user?.rol!=='administrador' || $("reset-password-form").dataset.busy==='true') return;
    closePasswordReset(); resetPasswordAccount={id:String(account.id),nombre:account.nombre};
    $("reset-password-account").textContent=`Cuenta: ${account.nombre} (${account.email})`;
    $("reset-password-dialog").showModal(); $("reset-admin-password").focus();
}
$("reset-password-cancel").addEventListener('click',closePasswordReset);
$("reset-password-dialog").addEventListener('cancel',event=>{event.preventDefault();closePasswordReset();});
$("reset-password-form").addEventListener('submit',async event=>{
    event.preventDefault(); const form=event.currentTarget;
    if(form.dataset.busy==='true' || !resetPasswordAccount || user?.rol!=='administrador') return;
    const password=$("reset-new-password").value, confirmacion=$("reset-confirm-password").value;
    if(password!==confirmacion || new TextEncoder().encode(password).length<12 || new TextEncoder().encode(password).length>72) {
        $("reset-password-message").textContent='Las contraseñas deben coincidir y tener entre 12 y 72 bytes.';return;
    }
    const target=resetPasswordAccount, session=sessionEpoch;
    form.dataset.busy='true'; const button=form.querySelector('button[type="submit"]'); button.disabled=true;
    $("reset-password-message").textContent='Guardando…';
    const payload={password,confirmacion,admin_password:$("reset-admin-password").value};
    try {
        await api(`auth/users/${target.id}/password`,'POST',payload);
        if(sessionEpoch!==session) return;
        const own=target.id===String(user.id); closePasswordReset();
        if(own) logout();
        message(own ? 'Contraseña restablecida. Ingresa con tu nueva contraseña.' : `Contraseña de ${target.nombre} restablecida. Sus sesiones anteriores quedaron invalidadas.`);
    } catch(error) {if(sessionEpoch===session) $("reset-password-message").textContent=error.message;}
    finally {
        payload.password='';payload.confirmacion='';payload.admin_password='';
        for(const id of ['reset-admin-password','reset-new-password','reset-confirm-password']) $(id).value='';
        button.disabled=false;form.dataset.busy='false';
    }
});

// Registra cuentas desde la API sin guardar contraseñas en el navegador.
$("user-form").addEventListener("submit", (event) => {
    event.preventDefault();
    submit(event.currentTarget, async () => {
        const data = {
            nombre: $("user-name").value.trim(), email: $("user-email").value.trim(),
            password: $("user-password").value, rol: $("user-role").value
        };
        try { await api("auth/register", "POST", data); }
        finally { $("user-password").value = ""; }
        $("user-form").reset();
        await loadUsers();
        message("Usuario registrado correctamente.");
    });
});

$("refresh-users").addEventListener("click", async () => {
    const button = $("refresh-users");
    button.disabled = true;
    message("");
    try { await loadUsers(); message("Usuarios actualizados."); }
    catch (error) { message(error.message, true); }
    finally { button.disabled = false; }
});


// Muestra las alertas reales del servidor para todos los usuarios autenticados.
async function loadAlerts() {
    const data = await api("inventario/alertas");
    $("alerts-list").replaceChildren();
    for (const product of data.productos) {
        const item = document.createElement("li");
        item.textContent = `${product.codigo} — ${product.nombre}: ${units(product.stock)} unidades`;
        $("alerts-list").append(item);
    }
    $("alerts-panel").hidden = data.productos.length === 0;
    $("dashboard-low").textContent = units(data.productos.length);
    $("dashboard-no-alerts").hidden = data.productos.length > 0;
}

// Presenta solo rutas locales generadas por el servidor al cargar una imagen.
function productImage(product) {
    const image = document.createElement("img");
    image.src = product.imagen_url;
    image.alt = `Imagen de ${product.nombre}`;
    image.className = "product-photo";
    image.loading = "lazy";
    return image;
}

// Recalcula la página al aplicar filtros para no quedarse en una página anterior vacía.
$("filter-form").addEventListener("submit", (event) => {
    event.preventDefault();
    submit(event.currentTarget, async () => { page = 1; await loadProducts(); });
});
$("clear-filters").addEventListener("click", async () => {
    $("filter-form").reset(); page = 1; message("");
    try { await loadProducts(); }
    catch (error) { message(error.message, true); }
});

// Cambia una página por vez y evita clics repetidos mientras carga la lista.
async function changePage(next) {
    const previous = page;
    $("previous-page").disabled = true; $("next-page").disabled = true;
    message("");
    try { page = next; await loadProducts(); }
    catch (error) { page = previous; message(error.message, true); }
    finally {
        $("previous-page").disabled = page <= 1;
        $("next-page").disabled = page >= pages;
    }
}
$("previous-page").addEventListener("click", () => changePage(page-1));
$("next-page").addEventListener("click", () => changePage(page+1));

// El carrito vive en memoria. Solo una operación pendiente se conserva para poder reintentarla.
let cart = [];
let pendingSale = null;
function checkoutKey(id = user.id) { return `pos-pending-sale-${id}`; }

// Al volver a iniciar sesión, recupera la misma referencia si se perdió la respuesta anterior.
function restoreCheckout() {
    cart = []; pendingSale = null;
    try {
        const raw = sessionStorage.getItem(checkoutKey());
        if (raw) {
            const saved = JSON.parse(raw);
            if (!saved.request || !Array.isArray(saved.cart)) throw new Error("Carrito inválido");
            pendingSale = saved.request; cart = saved.cart;
            $("payment-method").value=pendingSale.metodo_pago || "efectivo";
            const buyer=pendingSale.comprador;
            $("buyer-type").value=buyer?.nit ? "nit" : "cf";
            $("buyer-nit").value=buyer?.nit || "";
            $("buyer-name").value=buyer?.nombre === "CF" ? "" : (buyer?.nombre || "");
            $("buyer-address").value=buyer?.direccion || "";
        }
    } catch (error) { message("No se pudo recuperar el carrito pendiente.", true); }
    renderCart();
}

// Genera botones con texto, sin insertar HTML procedente de productos.
function cartButton(label, action, disabled = false) {
    const button = document.createElement("button");
    button.type = "button"; button.className = "btn btn-outline-primary btn-sm";
    button.textContent = label; button.disabled = disabled;
    button.addEventListener("click", action); return button;
}

function renderCart() {
    $("cart-body").replaceChildren(); let total = 0;
    for (const item of cart) {
        const row = document.createElement("tr");
        cell(row, `${item.codigo} — ${item.nombre}`); cell(row, money(item.precio));
        const td = document.createElement("td"); const input = document.createElement("input");
        input.type = "number"; input.min = "1"; input.max = String(Math.min(999999, Number(item.stock)));
        input.step = "1"; input.required = true; input.value = item.cantidad;
        input.className = "form-control cart-quantity"; input.disabled = Boolean(pendingSale);
        input.setAttribute("aria-label", `Cantidad de ${item.nombre}`);
        input.addEventListener("change", () => {
            const value = Number(input.value);
            if (!Number.isInteger(value) || value < 1 || value > Number(input.max)) {
                input.value = item.cantidad; message("La cantidad debe ser entera y no superar las existencias.", true); return;
            }
            item.cantidad = value; renderCart();
        });
        td.append(input); row.append(td);
        const subtotal = Math.round(Number(item.precio) * 100) * item.cantidad;
        total += subtotal; cell(row, money(subtotal / 100));
        const actions = document.createElement("td");
        actions.append(cartButton("Quitar", () => {
            if (pendingSale) return;
            cart = cart.filter((line) => line.id !== item.id); renderCart();
        }, Boolean(pendingSale)));
        row.append(actions); $("cart-body").append(row);
    }
    $("cart-total").textContent = money(total / 100);
    updateBuyer(total);
    // Vista previa con el mismo redondeo de la factura; el servidor confirma los importes.
    const base = Math.floor((total * 100 + 56) / 112);
    $("cart-base").textContent = money(base / 100);
    $("cart-tax").textContent = money((total - base) / 100);
    $("cart-empty").hidden = cart.length > 0;
    $("save-sale").disabled = !cart.length;
    $("save-sale").textContent = pendingSale ? "Reintentar confirmación" : "Registrar venta";
    $("clear-cart").disabled = !cart.length || Boolean(pendingSale);
    $("checkout-pending").hidden = !pendingSale;
    $("checkout-pending").textContent=pendingSale?.metodo_pago==="tarjeta" ? "Pago de PayPal pendiente. Puedes verificarlo o cancelar la venta." : "Hay una venta por confirmar. Reintenta sin cambiar el carrito para evitar duplicarla.";
    $("cancel-pending-sale").hidden = pendingSale?.metodo_pago!=="tarjeta";
    $("cancel-pending-sale").disabled = $("checkout-form").dataset.busy==="true";
    $("payment-method").disabled=Boolean(pendingSale);
    $("sales-results").querySelectorAll("button").forEach((button) => { button.disabled = Boolean(pendingSale) || button.dataset.available === "false"; });
}

// Solo se presenta la última búsqueda, incluso al cambiar rápidamente de categoría.
let salesSearchRequest=0;
async function loadSalesProducts() {
    const ticket=++salesSearchRequest,session=sessionEpoch;
    const filters = new URLSearchParams({buscar: $("sales-search").value.trim(), limite: "10"});
    if($("sales-category").value) filters.set("categoria_id",$("sales-category").value);
    let data;
    try {data=await api(`inventario/productos?${filters}`);}
    catch(error) {if(ticket!==salesSearchRequest || session!==sessionEpoch) return;throw error;}
    if(ticket!==salesSearchRequest || session!==sessionEpoch) return;
    $("sales-results").replaceChildren();
    $("sales-results-label").textContent = data.productos.length
        ? `Se muestran ${data.productos.length} de ${data.paginacion.total} resultados. Puedes precisar la búsqueda.`
        : "No se encontraron productos.";
    for (const product of data.productos) {
        const row = document.createElement("tr");
        const photo=cell(row,"");
        if(product.imagen_url) photo.append(productImage(product));
        else photo.textContent="Sin imagen";
        cell(row, product.codigo); cell(row, product.nombre);
        cell(row, money(product.precio)); cell(row, units(product.stock));
        const available = Number.isInteger(Number(product.stock)) && Number(product.stock) >= 1;
        const td = document.createElement("td");
        const add = cartButton(available ? "Agregar" : "Sin existencias", () => {
            if (pendingSale) return;
            const existing = cart.find((item) => String(item.id) === String(product.id));
            if (existing) {
                if (existing.cantidad >= Math.min(999999, Number(product.stock))) { message("No hay más unidades disponibles.", true); return; }
                existing.cantidad++; existing.stock = product.stock;
            } else {
                if (cart.length >= 50) { message("El carrito admite hasta 50 productos.", true); return; }
                cart.push({...product, cantidad: 1});
            }
            $("sale-result").hidden = true; renderCart(); message("Producto agregado al carrito.");
        }, !available || Boolean(pendingSale));
        add.dataset.available = String(available); td.append(add); row.append(td); $("sales-results").append(row);
    }
}
$("sales-search-form").addEventListener("submit",event=>{
    event.preventDefault();submit(event.currentTarget,loadSalesProducts);
});
$("sales-category").addEventListener("change",async()=>{
    $("sales-search").value="";
    message("");
    try {await loadSalesProducts();}
    catch(error) {message(error.message,true);}
});

$("clear-cart").addEventListener("click", () => { if (!pendingSale) { cart = []; renderCart(); } });

$("cancel-pending-sale").addEventListener("click",()=>{
    if(!pendingSale || pendingSale.metodo_pago!=="tarjeta") return;
    submit($("checkout-form"),async()=>{
        const session=sessionEpoch,reference=pendingSale.referencia;
        renderCart();
        // La referencia persistida recupera la misma venta si se perdió la respuesta inicial.
        const data=await api("ventas/","POST",pendingSale);
        if(session!==sessionEpoch || pendingSale?.referencia!==reference) return;
        if(data.venta.estado==='confirmada') {await completeCheckout(data);message('El pago ya estaba confirmado. La factura está disponible.');return;}
        if(data.venta.estado==='anulada') {onPendingSaleCancelled(data.venta);return;}
        await PayPalCheckout.cancel(data.venta.id);
    });
});

// Envía solo ids y cantidades; PHP obtiene los precios y calcula el total definitivo.
$("checkout-form").addEventListener("submit", (event) => {
    event.preventDefault();
    submit(event.currentTarget, async () => {
        if (!cart.length) return;
        const owner = user.id; const session = sessionEpoch;
        clearInvoice();
        if (!pendingSale) {
            const comprador=$("buyer-type").value === "cf" ? {nombre:"CF"} : {
                nombre:$("buyer-name").value.trim(),nit:$("buyer-nit").value.trim(),direccion:$("buyer-address").value.trim()
            };
            const request = {referencia: crypto.randomUUID(), metodo_pago:$("payment-method").value, productos: cart.map((item) => ({producto_id: String(item.id), cantidad: item.cantidad})), comprador};
            // Guarda antes de enviar. Si no se puede conservar la referencia, no inicia el descuento.
            sessionStorage.setItem(checkoutKey(owner), JSON.stringify({request, cart}));
            pendingSale = request;
        }
        renderCart(); $("save-sale").disabled = true;
        let data;
        try { data = await api("ventas/", "POST", pendingSale); }
        catch (error) {
            if ([400,403,404,409,413,415,422].includes(error.status)) {
                sessionStorage.removeItem(checkoutKey(owner));
                if (sessionEpoch === session) pendingSale = null;
            }
            if (sessionEpoch === session) renderCart();
            throw error;
        }
        if (sessionEpoch !== session) return;
        if(data.venta.estado==='pendiente_pago' || (data.venta.estado==='anulando' && data.venta.metodo_pago==='tarjeta')) { await PayPalCheckout.open(data.venta.id); return; }
        if(data.venta.estado!=='confirmada') { releaseCancelledCheckout(data.venta); throw new Error('La venta fue anulada. Registra una nueva compra si corresponde.'); }
        await completeCheckout(data);

    });
});


// Libera únicamente el carrito relacionado; no toca otra compra que esté en preparación.
function releaseCancelledCheckout(sale) {
    if(pendingSale?.referencia===sale.referencia) {
        sessionStorage.removeItem(checkoutKey());cart=[];pendingSale=null;
        $("buyer-type").value="cf";$("payment-method").value="efectivo";
        for(const id of ["buyer-nit","buyer-name","buyer-address"]) $(id).value="";
        renderCart();
    }
}
function onPendingSaleCancelled(sale) {
    PayPalCheckout.clear();
    releaseCancelledCheckout(sale);
    message('Venta cancelada. Las existencias se liberaron y puedes registrar otra venta.');
}
async function completeCheckout(data) {
    const related=pendingSale?.referencia===data.venta.referencia;
    if(related) {
        sessionStorage.removeItem(checkoutKey());cart=[];pendingSale=null;
        $("buyer-type").value="cf";$("payment-method").value="efectivo";
        for(const id of ["buyer-nit","buyer-name","buyer-address"]) $(id).value="";
        renderCart();
        $("buyer-type").value="cf";
        for(const id of ["buyer-nit","buyer-name","buyer-address"]) $(id).value="";
        $("payment-method").value="efectivo";updateBuyer(0);
        $("sales-results").replaceChildren();
        $("sales-results-label").textContent="Venta guardada. Busca productos para la siguiente venta.";
    }
    $("sale-result").textContent=`Venta #${data.venta.id} confirmada. Total: ${money(data.venta.total)}.`;
    $("sale-result").hidden=false; $("invoice-sale-id").value=data.venta.id;
    const origin=$("sales-history-panel").hidden ? "sales-panel" : "sales-history-panel";
    try {if(origin==="sales-history-panel") await SalesHistoryView.open();await loadInvoice(data.venta.id,origin);}
    catch(error) {message(`Venta confirmada. Consulta su factura con el número ${data.venta.id}: ${error.message}`,true);}
    try {await loadProducts();await loadAlerts();}
    catch(error) {message(`Venta confirmada. No se pudo actualizar el listado: ${error.message}`,true);}
}
PayPalCheckout.init({api,getToken:()=>token,getSession:()=>sessionEpoch,onPaid:completeCheckout,onCancelled:onPendingSaleCancelled});

// Quita datos de una factura anterior antes de consultar otra o cerrar sesión.
function clearInvoice() {
    invoiceRequest++;
    currentInvoiceId = null;
    InvoiceQr.clear();
    $("invoice-panel").hidden = true;
    $("invoice-body").replaceChildren();
    $("invoice-title").textContent = "Factura";
    $("invoice-info").textContent = "";
    $("invoice-buyer").textContent = "";
    for (const id of ["invoice-base", "invoice-tax", "invoice-total"]) $(id).textContent = "";
}

// La factura usa cantidades y precios históricos, no vuelve a pedir precios al inventario.
async function loadInvoice(id, origin = "sales-history-panel") {
    if ($(origin).hidden) return;
    const session = sessionEpoch;
    clearInvoice();
    const request = invoiceRequest;
    const data = await api(`ventas/${encodeURIComponent(id)}/factura`);
    if (!token || sessionEpoch !== session || request !== invoiceRequest || $(origin).hidden) return;
    const invoice = data.factura;
    $("invoice-title").textContent = `Factura ${invoice.numero}${invoice.estado && invoice.estado!=="confirmada" ? " · " + invoice.estado.toUpperCase() : ""}`;
    const date = new Date(invoice.fecha).toLocaleString("es-GT", {timeZone: "America/Guatemala", hour12: true});
    $("invoice-info").textContent = `Venta #${invoice.venta_id} · ${date} · Usuario #${invoice.usuario_id}`;
    const buyer=invoice.comprador;
    $("invoice-buyer").textContent=buyer.nit ? `Comprador: ${buyer.nombre} · NIT: ${buyer.nit} · Dirección: ${buyer.direccion}` : "Comprador: CF";
    for (const item of invoice.productos) {
        const row = document.createElement("tr");
        cell(row, item.codigo); cell(row, item.nombre); cell(row, units(item.cantidad));
        cell(row, money(item.precio)); cell(row, money(item.subtotal));
        $("invoice-body").append(row);
    }
    $("invoice-base").textContent = money(invoice.subtotal);
    $("invoice-tax").textContent = money(invoice.iva);
    $("invoice-total").textContent = money(invoice.total);
    $("invoice-panel").hidden = false;
    currentInvoiceId = invoice.venta_id;
    // La factura se muestra aunque falle la API externa del QR.
    InvoiceQr.load(invoice.venta_id, token);
    $("invoice-panel").scrollIntoView({behavior:"smooth", block:"start"});
}

$("invoice-form").addEventListener("submit", (event) => {
    event.preventDefault();
    submit(event.currentTarget, () => loadInvoice($("invoice-sale-id").value));
});

// Imprimir descarga un PDF generado en el servidor, sin abrir el diálogo de impresión.
$("print-invoice").addEventListener("click", async () => {
    if ($("invoice-panel").hidden || currentInvoiceId===null) return;
    const button=$("print-invoice"); button.disabled=true;
    const id=currentInvoiceId, session=sessionEpoch, request=invoiceRequest;
    try {
        await SessionActivity.ensure();
        const downloaded = await InvoicePdfDownload.download(id,token,()=>sessionEpoch===session && currentInvoiceId===id && invoiceRequest===request);
        if (downloaded && sessionEpoch===session && currentInvoiceId===id && invoiceRequest===request) {
            finishInvoice();
            message("Factura descargada. Puedes continuar.");
        }
    } catch(error) {
        if(sessionEpoch===session) {
            if(error.status===401) logout();
            message(error.message || "No se pudo descargar el PDF.",true);
        }
    } finally { button.disabled=false; }
});

// Oculta solo el comprobante anterior; nunca borra un carrito nuevo o pendiente.
function finishInvoice() {
    clearInvoice(); $("invoice-form").reset(); $("sale-result").hidden=true;
    if (!$("sales-panel").hidden && !cart.length && !pendingSale) {
        $("sales-search-form").reset(); $("sales-results").replaceChildren();
        $("sales-results-label").textContent="Busca un producto para agregarlo al carrito.";
        $("sales-search").focus();
    }
}
$("new-sale").addEventListener("click",finishInvoice);

// El formulario acompaña el total del carrito; la comprobación definitiva se hace en el servidor.
function updateBuyer(total) {
    const required=total>=250000;
    if(required && !pendingSale) $("buyer-type").value="nit";
    $("buyer-type").querySelector('option[value="cf"]').disabled=required;
    const identified=$("buyer-type").value==="nit";
    $("buyer-details").hidden=!identified;
    $("buyer-fields").disabled=Boolean(pendingSale);
    for(const id of ["buyer-nit","buyer-name","buyer-address"]) {
        $(id).required=identified; $(id).disabled=!identified;
    }
    $("buyer-help").textContent=required ? "Desde Q 2,500.00 debes ingresar NIT, nombre y dirección del comprador." : "Puedes usar CF en compras menores a Q 2,500.00.";
}
$("buyer-type").addEventListener("change",()=>renderCart());
$("retry-invoice-qr").addEventListener("click", () => {
    if (user && token && currentInvoiceId !== null && !$("invoice-panel").hidden) InvoiceQr.load(currentInvoiceId, token);
});

// Cambia de módulo sin mezclar los formularios. Los permisos reales siguen estando en PHP.
const views = {
    "dashboard-panel": ["Dashboard", "El resumen de tu ferretería."],
    "list-panel": ["Productos", "Consulta precios, existencias y detalles de tu inventario."],
    "product-panel": ["Registrar producto", "Agrega un producto con su precio, existencias e imagen."],
    "category-panel": ["Categorías", "Organiza los productos para encontrarlos con facilidad."],
    "sales-panel": ["Caja", "Busca productos, registra la venta y descarga su factura."],
    "cash-register-panel": ["Cuadre de caja", "Abre tu turno, cuenta el efectivo y consulta los cierres."],
    "sales-history-panel": ["Historial de ventas", "Consulta tus ventas por fecha o vendedor y recupera sus facturas."],
    "reports-panel": ["Reportes", "Conoce las ventas y los productos que más se mueven."],
    "users-panel": ["Usuarios", "Administra las cuentas de quienes trabajan en la ferretería."]
};
const adminViews = ["product-panel", "category-panel", "users-panel", "reports-panel"];
ReportView.init({api, money, units, getToken:()=>token, getSession:()=>sessionEpoch, ensureSession:()=>SessionActivity.ensure(), onUnauthorized:()=>{ logout(); message("La sesión venció. Ingresa de nuevo.",true); }});

CashRegisterView.init({api,getUser:()=>user,money});
SalesHistoryView.init({api, getUser:()=>user, money, closeInvoice:clearInvoice, openInvoice:id=>loadInvoice(id,"sales-history-panel")});

async function showView(id) {
    if (!user || !token) return;
    if (!views[id] || (adminViews.includes(id) && user.rol !== "administrador")) {
        id = user.rol === "administrador" ? "dashboard-panel" : "sales-panel";
        history.replaceState(null, "", `#${id}`);
    }
    // Comparte una sola vista de factura, pero descarta consultas anteriores al cambiar de módulo.
    clearInvoice();
    $(id==="sales-history-panel" ? "history-invoice-slot" : "cash-invoice-slot").append($("invoice-panel"));
    $("new-sale").hidden=id!=="sales-panel";
    for (const view of Object.keys(views)) $(view).hidden = view !== id;
    $("admin-section").hidden = id !== "product-panel";
    $("view-title").textContent = views[id][0];
    $("view-description").textContent = views[id][1];
    document.title = `FerreSync | ${views[id][0]}`;
    document.querySelectorAll(".sidebar-nav a").forEach((link) => {
        const active = link.hash === `#${id}`;
        link.classList.toggle("active", active);
        if (active) link.setAttribute("aria-current", "page");
        else link.removeAttribute("aria-current");
    });
    if (!desktopMenu.matches) closeMenu();
    if(id==="dashboard-panel") loadWeather();
    if (id === "dashboard-panel") {
        const [data] = await Promise.all([api("inventario/productos?limite=1"), loadCategories(), loadAlerts()]);
        $("dashboard-products").textContent = units(data.paginacion.total);
        $("category-stat").href = user.rol === "administrador" ? "#category-panel" : "#list-panel";
        $("category-stat-note").textContent = user.rol === "administrador" ? "Organizar productos →" : "Consultar productos →";
    }
    if (id === "list-panel") { await loadCategories(); await loadProducts(); }
    if (["product-panel","category-panel","sales-panel"].includes(id)) await loadCategories();
    if (id === "users-panel") await loadUsers();
    if (id === "reports-panel") await ReportView.load();
    if (id === "sales-history-panel") await SalesHistoryView.open();
    if (id === "cash-register-panel") await CashRegisterView.load();
}

// Reglas orientativas del negocio; no representan una predicción de ventas.
function weatherOutlook(code) {
    if (code === 800 || code === 801) return 'Afluencia esperada: alta en tienda. Se prevén más compras presenciales.';
    if (code >= 802 && code <= 804) return 'Afluencia esperada: baja en tienda. Se prevé mayor preferencia por pedidos en línea.';
    if (code >= 200 && code < 700) return 'Afluencia esperada: baja en tienda por precipitaciones. Se prevé mayor preferencia por pedidos en línea.';
    if (code >= 700 && code < 800) return 'Afluencia esperada: baja en tienda por condiciones adversas. Se prevé mayor preferencia por pedidos en línea.';
    return 'No hay información suficiente para estimar la afluencia.';
}

// El clima puede fallar sin impedir las consultas de inventario o reportes.
async function loadWeather() {
    const session=sessionEpoch;
    const render=(text,outlook)=>{
        document.querySelectorAll('[data-weather]').forEach(el=>el.textContent=text);
        document.querySelectorAll('[data-weather-outlook]').forEach(el=>el.textContent=outlook);
    };
    try {
        const data=await api('reportes/clima'); if(sessionEpoch!==session) return;
        const weather=data.clima;
        render(weather.disponible ? `${weather.ciudad} · ${weather.temperatura} °C · ${weather.descripcion} (ahora)` : weather.mensaje,
            weather.disponible ? weatherOutlook(weather.codigo) : 'Afluencia sin estimar hasta disponer del clima.');
    } catch(error) { if(sessionEpoch===session) render('Clima no disponible por el momento','Afluencia sin estimar hasta disponer del clima.'); }
}

async function openView(id, replace = false) {
    if (replace || location.hash !== `#${id}`) history[replace ? "replaceState" : "pushState"](null, "", `#${id}`);
    await showView(id);
}

// Los enlaces del menú, del dashboard y de los formularios usan la misma navegación.
document.querySelectorAll('a[href^="#"]').forEach((link) => {
    link.addEventListener("click", (event) => {
        const id = link.hash.slice(1);
        if (!views[id] || !user) return;
        event.preventDefault(); message("");
        if (["stock-stat", "products-stat"].includes(link.id)) {
            $("filter-form").reset(); page = 1;
            $("filter-low").checked = link.id === "stock-stat";
        }
        openView(id).catch((error) => message(error.message, true));
    });
});
window.addEventListener("popstate", () => {
    if (user) { message(""); showView(location.hash.slice(1)).catch((error) => message(error.message, true)); }
});

