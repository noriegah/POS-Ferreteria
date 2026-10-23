"use strict";
// Descarga el PDF del servicio de Ventas. El token nunca forma parte del enlace.
const InvoicePdfDownload = {
    async download(id, token, canDownload = () => true) {
        const response = await fetch(`/api/ventas/${encodeURIComponent(id)}/factura/pdf`, {
            headers: {Authorization: `Bearer ${token}`, Accept: "application/pdf"}, cache: "no-store"
        });
        if (!response.ok) {
            const data=await response.json().catch(()=>({}));
            const error = new Error(data.error || "No se pudo descargar el PDF. Intenta de nuevo.");
            error.status = response.status; throw error;
        }
        if (!response.headers.get("Content-Type")?.startsWith("application/pdf")) throw new Error("No se recibió un PDF válido.");
        const blob = await response.blob();
        // Si se cerró la sesión o se cambió de factura, descarta la respuesta anterior.
        if (!canDownload()) return false;
        const url=URL.createObjectURL(blob);
        const link=document.createElement("a");
        link.href=url; link.download=`FAC-${String(id).padStart(6,"0")}.pdf`;
        document.body.append(link); link.click(); link.remove();
        setTimeout(()=>URL.revokeObjectURL(url),30000);
        return true;
    }
};
