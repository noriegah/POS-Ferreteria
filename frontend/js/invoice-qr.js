"use strict";

// Mantiene la carga del QR separada de formularios, inventario y navegación.
const InvoiceQr = (() => {
    let controller = null;
    let objectUrl = null;
    let version = 0;
    const image = () => document.getElementById("invoice-qr-image");
    const status = () => document.getElementById("invoice-qr-status");
    const retry = () => document.getElementById("retry-invoice-qr");
    const print = () => document.getElementById("print-invoice");

    function clear() {
        version++;
        if (controller) controller.abort();
        controller = null;
        image().hidden = true; image().removeAttribute("src");
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        status().textContent = ""; retry().hidden = true; print().disabled = true;
    }

    async function load(id, token) {
        clear();
        const current = version;
        controller = new AbortController();
        status().textContent = "Preparando código QR…";
        try {
            // Usa el mismo gateway y envía el JWT únicamente a nuestro servicio de Ventas.
            const response = await fetch(`/api/ventas/${encodeURIComponent(id)}/factura/qr`, {
                headers: {Authorization: `Bearer ${token}`, Accept: "image/png"},
                cache: "no-store", signal: controller.signal
            });
            if (!response.ok) throw new Error(response.status === 401 ? "Inicia sesión de nuevo para consultar el QR." : "No se pudo cargar el QR. Puedes reintentarlo.");
            if (response.headers.get("Content-Type")?.split(";")[0] !== "image/png") throw new Error("No se pudo cargar el QR.");
            const blob = await response.blob();
            if (current !== version) return;
            if (blob.size > 262144) throw new Error("No se pudo cargar el QR.");
            objectUrl = URL.createObjectURL(blob);
            image().src = objectUrl;
            await image().decode();
            if (current !== version) return;
            image().hidden = false;
            status().textContent = "Escanea para consultar la validez y el estado de esta factura.";
        } catch (error) {
            if (current !== version || error.name === "AbortError") return;
            image().hidden = true;
            status().textContent = ["TypeError", "EncodingError"].includes(error.name)
                ? "No se pudo cargar el QR. Puedes reintentarlo."
                : (error.message || "El QR no está disponible.");
            retry().hidden = false;
        } finally {
            // La factura puede imprimirse sin QR si el proveedor no está disponible.
            if (current === version) print().disabled = false;
        }
    }
    return {clear, load};
})();
