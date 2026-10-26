"use strict";

// El navegador abre PayPal, pero el servidor verifica y confirma el cobro.
const PayPalCheckout = (() => {
    const el=id=>document.getElementById(id);
    let api,getToken,getSession,onPaid,onCancelled,active=null,request=0,busy=false;

    function clear() {
        request++; active=null;busy=false; el('paypal-dialog').close();
        el('paypal-approve').hidden=true; el('paypal-approve').removeAttribute('href');
        el('paypal-status').textContent=''; el('paypal-total').textContent='';
    }

    async function configure() {
        const session=getSession();
        try {
            const config=await api('ventas/pagos/config'); if(getSession()!==session) return;
            el('payment-paypal-option').disabled=!config.paypal_disponible;
            el('payment-configuration').textContent=config.paypal_disponible
                ? `PayPal Sandbox · Tasa de cambio: Q ${config.tasa_demo} por USD 1.00.`
                : 'PayPal Sandbox no está disponible por el momento. Puedes usar efectivo.';
        } catch(error) {
            if(getSession()===session) {el('payment-paypal-option').disabled=true;el('payment-configuration').textContent='No se pudo consultar la configuración de PayPal. Puedes usar efectivo.';}
        }
    }

    async function prepare() {
        if(active===null || busy) return;
        const id=active,ticket=++request,session=getSession();
        busy=true;el('paypal-cancel-sale').disabled=true;
        el('paypal-status').textContent='Preparando el pago…'; el('paypal-approve').hidden=true;
        el('paypal-confirm').disabled=true;el('paypal-retry').disabled=true;
        try {
            const data=await api(`ventas/${id}/pago/orden`,'POST');
            if(ticket!==request || session!==getSession()) return;
            const payment=data.pago;
            el('paypal-total').textContent=`Total: Q ${payment.total_quetzales} · Pago por PayPal Sandbox: USD ${payment.importe_usd} · Tasa fijada: Q ${payment.tasa_demo} por USD 1.00`;
            if(payment.url) {
                const link=new URL(payment.url);
                if(link.protocol!=='https:' || link.hostname!=='www.sandbox.paypal.com') throw new Error('Enlace de PayPal Sandbox inválido');
                el('paypal-approve').href=link.href;el('paypal-approve').hidden=false;
            }
            el('paypal-status').textContent=payment.estado==='aprobado' ? 'El pago ya fue confirmado. Pulsa Verificar pago para recuperar la factura.'
                : payment.autorizado ? 'Pago autorizado en PayPal. Pulsa Verificar pago para confirmarlo.'
                : '';
            el('paypal-confirm').disabled=false;
        } catch(error) {if(ticket===request && session===getSession()) el('paypal-status').textContent=error.message;}
        finally {if(ticket===request) {busy=false;el('paypal-retry').disabled=false;el('paypal-cancel-sale').disabled=false;}}
    }

    async function open(id) {
        clear();active=String(id);el('paypal-dialog').showModal();await prepare();
    }

    async function confirm() {
        if(active===null || busy) return;
        const id=active,ticket=++request,session=getSession();
        busy=true;el('paypal-cancel-sale').disabled=true;
        el('paypal-confirm').disabled=true;el('paypal-retry').disabled=true;
        el('paypal-status').textContent='Verificando el cobro en el servidor…';
        try {
            const data=await api(`ventas/${id}/pago/confirmar`,'POST');
            if(ticket!==request || session!==getSession()) return;
            clear();await onPaid(data);
        } catch(error) {
            if(ticket!==request || session!==getSession()) return;
            el('paypal-status').textContent=error.message;
            if(error.status===409) {
                const data=await api(`ventas/${id}`).catch(()=>null);
                if(ticket===request && data?.venta.estado==='anulada') {clear();onCancelled(data.venta);}
            }
        } finally {if(ticket===request) {busy=false;el('paypal-confirm').disabled=false;el('paypal-retry').disabled=false;el('paypal-cancel-sale').disabled=false;}}
    }

    async function cancel(id=active) {
        if(id===null) return;
        if(busy) throw new Error('Hay una operación de pago en proceso. Espera a que termine.');
        if(!window.confirm('¿Cancelar esta venta pendiente y liberar los productos reservados?')) return;
        const ticket=++request,session=getSession();
        busy=true;el('paypal-confirm').disabled=true;el('paypal-retry').disabled=true;el('paypal-cancel-sale').disabled=true;
        el('paypal-status').textContent='Cancelando venta…';
        try {
            const data=await api(`ventas/${id}/pago/cancelar`,'POST');
            if(ticket!==request || session!==getSession()) return;
            if(data.venta?.estado!=='anulada') throw new Error('No se completó la cancelación. Reintenta antes de iniciar otra venta.');
            clear();await onCancelled(data.venta);
        } catch(error) {
            if(ticket!==request || session!==getSession()) return;
            el('paypal-status').textContent=error.message;throw error;
        } finally {if(ticket===request) {busy=false;el('paypal-confirm').disabled=false;el('paypal-retry').disabled=false;el('paypal-cancel-sale').disabled=false;}}
    }

    function init(options) {
        ({api,getToken,onPaid,onCancelled}=options);getSession=options.getSession || getToken;
        el('paypal-cancel-sale').addEventListener('click',()=>cancel().catch(()=>{}));
        el('paypal-close').addEventListener('click',()=>el('paypal-dialog').close());
        el('paypal-retry').addEventListener('click',prepare);el('paypal-confirm').addEventListener('click',confirm);
    }
    return {init,configure,open,clear,cancel};
})();
