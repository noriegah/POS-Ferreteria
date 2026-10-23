"use strict";

// El navegador abre PayPal, pero el servidor verifica y confirma el cobro.
const PayPalCheckout = (() => {
    const el=id=>document.getElementById(id);
    let api,getToken,onPaid,onCancelled,active=null,request=0;

    function clear() {
        request++; active=null; el('paypal-dialog').close();
        el('paypal-approve').hidden=true; el('paypal-approve').removeAttribute('href');
        el('paypal-status').textContent=''; el('paypal-total').textContent='';
    }

    async function configure() {
        const session=getToken();
        try {
            const config=await api('ventas/pagos/config'); if(getToken()!==session) return;
            el('payment-paypal-option').disabled=!config.paypal_disponible;
            el('payment-configuration').textContent=config.paypal_disponible
                ? `PayPal Sandbox usa USD de prueba. Tasa de demostración: Q ${config.tasa_demo} por USD 1.00.`
                : 'PayPal Sandbox estará disponible al configurar tus credenciales de prueba. Efectivo está disponible.';
        } catch(error) {
            if(getToken()===session) {el('payment-paypal-option').disabled=true;el('payment-configuration').textContent='No se pudo consultar la configuración de PayPal. Puedes usar efectivo.';}
        }
    }

    async function prepare() {
        if(active===null) return;
        const id=active,ticket=++request,session=getToken();
        el('paypal-status').textContent='Preparando la orden de prueba…'; el('paypal-approve').hidden=true;
        el('paypal-confirm').disabled=true;el('paypal-retry').disabled=true;
        try {
            const data=await api(`ventas/${id}/pago/orden`,'POST');
            if(ticket!==request || session!==getToken()) return;
            const payment=data.pago;
            el('paypal-total').textContent=`Total: Q ${payment.total_quetzales} · Pago de prueba: USD ${payment.importe_usd} · Tasa fijada: Q ${payment.tasa_demo} por USD 1.00`;
            if(payment.url) {
                const link=new URL(payment.url);
                if(link.protocol!=='https:' || link.hostname!=='www.sandbox.paypal.com') throw new Error('Enlace de PayPal Sandbox inválido');
                el('paypal-approve').href=link.href;el('paypal-approve').hidden=false;
            }
            el('paypal-status').textContent=payment.estado==='aprobado' ? 'El pago ya fue confirmado. Pulsa Verificar pago para recuperar la factura.'
                : payment.autorizado ? 'Pago autorizado en PayPal. Pulsa Verificar pago para confirmarlo.'
                : 'Abre PayPal Sandbox, autoriza con la cuenta compradora de prueba y regresa para verificar el pago.';
            el('paypal-confirm').disabled=false;
        } catch(error) {if(ticket===request && session===getToken()) el('paypal-status').textContent=error.message;}
        finally {if(ticket===request) el('paypal-retry').disabled=false;}
    }

    async function open(id) {
        clear();active=String(id);el('paypal-dialog').showModal();await prepare();
    }

    async function confirm() {
        if(active===null) return;
        const id=active,ticket=++request,session=getToken();
        el('paypal-confirm').disabled=true;el('paypal-retry').disabled=true;
        el('paypal-status').textContent='Verificando el cobro en el servidor…';
        try {
            const data=await api(`ventas/${id}/pago/confirmar`,'POST');
            if(ticket!==request || session!==getToken()) return;
            clear();await onPaid(data);
        } catch(error) {
            if(ticket!==request || session!==getToken()) return;
            el('paypal-status').textContent=error.message;
            if(error.status===409) {
                const data=await api(`ventas/${id}`).catch(()=>null);
                if(ticket===request && data?.venta.estado==='anulada') {clear();onCancelled(data.venta);}
            }
        } finally {if(ticket===request) {el('paypal-confirm').disabled=false;el('paypal-retry').disabled=false;}}
    }

    function init(options) {
        ({api,getToken,onPaid,onCancelled}=options);
        el('paypal-close').addEventListener('click',()=>el('paypal-dialog').close());
        el('paypal-retry').addEventListener('click',prepare);el('paypal-confirm').addEventListener('click',confirm);
    }
    return {init,configure,open,clear};
})();
