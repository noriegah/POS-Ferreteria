"use strict";
// La pantalla consulta el servidor; los importes del cierre no se calculan desde el navegador.
const CashRegisterView=(()=>{
    const el=id=>document.getElementById(id);
    let api,getUser,money,current=null,version=0,names=new Map();
    const date=value=>value ? new Intl.DateTimeFormat('es-GT',{timeZone:'America/Guatemala',dateStyle:'short',timeStyle:'short'}).format(new Date(value.replace(' ','T')+'Z')) : '—';
    function cell(row,value){const td=document.createElement('td');td.textContent=value;row.append(td);return td;}
    function clear(){version++;current=null;names.clear();el('cash-detail-dialog').close();el('cash-turn-body').replaceChildren();el('cash-message').textContent='';el('cash-open-form').reset();el('cash-close-form').reset();}
    async function load(){
        const ticket=++version;
        el('cash-message').textContent='Consultando turnos…';
        try {
            const user=getUser();
            const [data,users]=await Promise.all([api('ventas/cajas'),user.rol==='administrador' ? api('auth/users') : Promise.resolve({usuarios:[user]})]);
            if(ticket!==version)return;
            names=new Map(users.usuarios.map(user=>[String(user.id),user.nombre]));
            current=data.actual;
            el('cash-open-form').hidden=!!current;el('cash-current').hidden=!current;
            if(current){
                el('cash-current-summary').textContent=`Turno #${current.id} · Apertura: ${date(current.abierta_en)} · Fondo: ${money(current.fondo)} · Ventas en efectivo: ${money(current.ventas_efectivo)} · Devoluciones: ${money(current.devoluciones)} · Efectivo esperado: ${money(current.esperado)}`;
            }
            const body=el('cash-turn-body');body.replaceChildren();
            for(const turn of data.turnos){
                const row=document.createElement('tr');cell(row,`#${turn.id}`);cell(row,names.get(String(turn.usuario_id)) || `Usuario #${turn.usuario_id}`);
                cell(row,date(turn.abierta_en));cell(row,date(turn.cerrada_en));cell(row,turn.estado);
                cell(row,turn.esperado_cierre===null ? '—' : money(turn.esperado_cierre));
                cell(row,turn.contado===null ? '—' : money(turn.contado));
                cell(row,turn.diferencia===null ? '—' : `${money(Math.abs(Number(turn.diferencia)))} ${Number(turn.diferencia)<0 ? 'faltante' : Number(turn.diferencia)>0 ? 'sobrante' : '(cuadrada)'}`);
                const button=document.createElement('button');button.type='button';button.className='btn btn-outline-secondary btn-sm';button.textContent='Ver detalle';
                button.addEventListener('click',()=>detail(turn.id));cell(row,'').append(button);body.append(row);
            }
            if(!data.turnos.length){const row=document.createElement('tr');cell(row,'Todavía no hay turnos registrados.').colSpan=9;body.append(row);}
            el('cash-message').textContent='';
        }catch(error){if(ticket===version)el('cash-message').textContent=error.message;}
    }
    async function detail(id){
        const ticket=version;
        try{
            const {caja}=await api(`ventas/cajas/${id}`);if(ticket!==version)return;
            el('cash-detail-title').textContent=`Turno #${caja.id} · ${names.get(String(caja.usuario_id)) || `Usuario #${caja.usuario_id}`}`;
            el('cash-detail-summary').textContent=`${caja.estado} · Fondo: ${money(caja.fondo)} · Ventas: ${money(caja.ventas_efectivo)} · Devoluciones: ${money(caja.devoluciones)} · Esperado: ${money(caja.esperado_cierre ?? caja.esperado)} · Contado: ${caja.contado===null ? 'Pendiente' : money(caja.contado)} · Diferencia (contado − esperado): ${caja.diferencia===null ? 'Pendiente' : (Number(caja.diferencia)<0 ? '−' : '')+money(Math.abs(Number(caja.diferencia)))}`;
            el('cash-detail-note').textContent=caja.observaciones || 'Sin observaciones.';
            const body=el('cash-movements');body.replaceChildren();
            for(const movement of caja.movimientos){const row=document.createElement('tr');cell(row,date(movement.creado_en));cell(row,`FAC-${String(movement.venta_id).padStart(6,'0')}`);cell(row,movement.tipo==='venta' ? 'Ingreso por venta' : 'Salida por devolución');cell(row,money(movement.importe));body.append(row);}
            if(!caja.movimientos.length){const row=document.createElement('tr');cell(row,'Sin movimientos de efectivo.').colSpan=4;body.append(row);}
            el('cash-detail-dialog').showModal();
        }catch(error){if(ticket===version)el('cash-message').textContent=error.message;}
    }
    async function submit(event,closing){
        event.preventDefault();const button=event.submitter;const ticket=version;button.disabled=true;
        try{
            await api(closing ? `ventas/cajas/${current.id}/cerrar` : 'ventas/cajas','POST',closing ? {contado:el('cash-counted').value,observaciones:el('cash-notes').value} : {fondo:el('cash-fund').value});
            if(ticket!==version)return;event.target.reset();await load();
        }catch(error){if(ticket===version)el('cash-message').textContent=error.message;}
        finally{button.disabled=false;}
    }
    function init(options){({api,getUser,money}=options);el('cash-open-form').addEventListener('submit',event=>submit(event,false));el('cash-close-form').addEventListener('submit',event=>submit(event,true));el('cash-refresh').addEventListener('click',load);el('cash-detail-close').addEventListener('click',()=>el('cash-detail-dialog').close());}
    return {init,load,clear};
})();
