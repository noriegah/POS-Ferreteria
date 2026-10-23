"use strict";

// El historial tiene sus propios filtros; la caja se dedica a registrar compras.
const SalesHistoryView = (() => {
    const el = id => document.getElementById(id);
    let api, getUser, money, openInvoice, closeInvoice, applied = null, request = 0, page = 1;
    let sellers = new Map(), changingSale = null, changeAction = null;
    function clear() {
        request++; applied = null; sellers.clear(); changingSale=null; el('sale-change-dialog').close();
        el('history-body').replaceChildren(); el('history-results').hidden = true;
        el('history-status').textContent = ''; el('history-filters').reset();
        el('history-prev').disabled = true; el('history-next').disabled = true;
        setDates();
    }
    function setDates() {
        const parts = new Intl.DateTimeFormat('en-US',{timeZone:'America/Guatemala',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
        const part = type => parts.find(value=>value.type===type).value;
        el('history-from').value = `${part('year')}-${part('month')}-01`;
        el('history-to').value = `${part('year')}-${part('month')}-${part('day')}`;
    }
    function cell(row,value) {
        const td = document.createElement('td'); td.textContent = value; row.append(td); return td;
    }
    async function open() {
        const ticket = ++request; const user = getUser();
        el('history-results').hidden = true; el('history-status').textContent = 'Cargando vendedores…';
        el('history-filter-button').disabled = true;
        try {
            const users = user.rol==='administrador' ? (await api('auth/users')).usuarios : [user];
            if (ticket!==request) return;
            sellers = new Map(users.map(row=>[String(row.id),row.nombre]));
            const selected = applied?.vendedor_id || '';
            const select = el('history-seller'); select.replaceChildren();
            const all = document.createElement('option'); all.value = ''; all.textContent = user.rol==='administrador' ? 'Todos los vendedores' : 'Mis ventas'; select.append(all);
            if(user.rol==='administrador') for(const seller of users) {
                const option = document.createElement('option'); option.value = seller.id;
                option.textContent = `${seller.nombre}${Number(seller.activo)===0 ? ' (inactivo)' : ''}`; select.append(option);
            }
            select.value = selected; select.disabled = user.rol!=='administrador';
            await load();
        } catch(error) {
            if(ticket===request) { el('history-status').textContent = error.message; el('history-filter-button').disabled = false; }
        }
    }
    async function load(newFilters = false, nextPage = 1) {
        closeInvoice();
        const ticket = ++request;
        if(newFilters || !applied) applied = {desde:el('history-from').value,hasta:el('history-to').value,vendedor_id:el('history-seller').value};
        el('history-results').hidden = true; el('history-status').textContent = 'Consultando ventas…';
        el('history-filter-button').disabled = true;
        el('history-prev').disabled = true; el('history-next').disabled = true;
        try {
            const data = await api(`ventas/?${new URLSearchParams({...applied,pagina:String(nextPage)})}`);
            if(ticket!==request) return;
            const body = el('history-body'); body.replaceChildren();
            for(const sale of data.ventas) {
                const row = document.createElement('tr');
                cell(row,`FAC-${String(sale.id).padStart(6,'0')}`); cell(row,sale.fecha);
                cell(row,sellers.get(String(sale.usuario_id)) || `Usuario #${sale.usuario_id}`);
                cell(row,sale.comprador_nombre); cell(row,money(sale.total)); cell(row,sale.estado || 'confirmada').style.whiteSpace='nowrap';
                const action = cell(row,''); const button = document.createElement('button');
                button.type = 'button'; button.className = 'btn btn-outline-primary btn-sm'; button.textContent = 'Ver factura';
                button.addEventListener('click',async()=>{
                    button.disabled = true;
                    try { await openInvoice(sale.id); }
                    catch(error) { el('history-status').textContent = error.message; }
                    finally { button.disabled = false; }
                });
                button.disabled=sale.estado==='pendiente_pago' || (sale.facturable!==undefined && Number(sale.facturable)!==1);
                action.append(button);
                if(sale.estado==='pendiente_pago') {
                    const pay=document.createElement('button');pay.type='button';pay.className='btn btn-primary btn-sm ms-2';pay.textContent='Continuar pago';
                    pay.addEventListener('click',()=>PayPalCheckout.open(sale.id));action.append(pay);
                }
                // La API vuelve a verificar el rol; ocultar el botón es solo una ayuda visual.
                if(getUser().rol==='administrador' && sale.estado!=='anulada') {
                    const cancel=document.createElement('button'); cancel.type='button';
                    cancel.className='btn btn-outline-danger btn-sm ms-2';
                    cancel.textContent=sale.estado==='anulando' ? 'Reintentar anulación' : 'Anular';
                    cancel.addEventListener('click',()=>showChange(sale.id,'anular'));
                    action.append(cancel);
                    if(sale.estado==='confirmada') {
                        const edit=document.createElement('button'); edit.type='button';
                        edit.className='btn btn-outline-secondary btn-sm ms-2'; edit.textContent='Editar comprador';
                        edit.addEventListener('click',()=>showChange(sale.id,'editar')); action.append(edit);
                    }
                }
                body.append(row);
            }
            if(!data.ventas.length) {const row = document.createElement('tr'); const td = cell(row,'No se encontraron ventas con estos filtros.'); td.colSpan = 7; body.append(row);}
            el('history-summary').textContent = `${data.periodo.desde} al ${data.periodo.hasta} · ${data.resumen.ventas} ${data.resumen.ventas===1 ? "venta" : "ventas"} · Total vigente ${money(data.resumen.total)} · Hora de Guatemala`;
            page = data.paginacion.pagina;
            el('history-page').textContent = `Página ${page} de ${data.paginacion.paginas}`;
            el('history-prev').disabled = page<=1; el('history-next').disabled = page>=data.paginacion.paginas;
            el('history-results').hidden = false; el('history-status').textContent = '';
        } catch(error) {
            if(ticket===request) { el('history-status').textContent = error.message; el('history-filter-button').disabled = false; }
        } finally {
            if(ticket===request) el('history-filter-button').disabled = false;
        }
    }
    async function showChange(id, action) {
        el('history-status').textContent='';
        try {
            const data=await api(`ventas/${id}`);
            changingSale=id; changeAction=action;
            el('sale-change-form').reset(); el('sale-change-error').textContent='';
            el('sale-change-title').textContent=action==='editar' ? 'Corregir comprador' : 'Anular venta y devolver existencias';
            el('sale-buyer-fields').hidden=action!=='editar';
            el('sale-edit-name').value=data.venta.comprador_nombre;
            el('sale-edit-nit').value=data.venta.comprador_nit || '';
            el('sale-edit-address').value=data.venta.comprador_direccion || '';
            el('sale-edit-reason').value=data.venta.anulacion_motivo || '';
            el('sale-change-save').textContent=action==='editar' ? 'Guardar corrección' : 'Confirmar anulación';
            el('sale-change-dialog').showModal();
        } catch(error) { el('history-status').textContent=error.message; }
    }
    async function saveChange(event) {
        event.preventDefault(); if(!changingSale) return;
        const payload={motivo:el('sale-edit-reason').value.trim()};
        if(changeAction==='editar') payload.comprador={nombre:el('sale-edit-name').value,nit:el('sale-edit-nit').value,direccion:el('sale-edit-address').value};
        el('sale-change-save').disabled=true; el('sale-change-close').disabled=true;
        try {
            await api(`ventas/${changingSale}${changeAction==='anular' ? '/anular' : ''}`,changeAction==='anular' ? 'POST' : 'PATCH',payload);
            el('sale-change-dialog').close(); changingSale=null; await load(false,page);
        } catch(error) { el('sale-change-error').textContent=error.message; }
        finally { el('sale-change-save').disabled=false; el('sale-change-close').disabled=false; }
    }
    function init(options) {
        ({api,getUser,money,openInvoice,closeInvoice} = options); setDates();
        el('sale-change-close').addEventListener('click',()=>el('sale-change-dialog').close());
        el('sale-change-form').addEventListener('submit',saveChange);
        el('history-filters').addEventListener('submit',event=>{event.preventDefault();load(true);});
        el('history-prev').addEventListener('click',()=>load(false,page-1));
        el('history-next').addEventListener('click',()=>load(false,page+1));
    }
    return {init,open,clear};
})();

