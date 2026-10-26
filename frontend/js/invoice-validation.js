"use strict";
(()=>{
 const el=id=>document.getElementById(id),params=new URLSearchParams(location.search);
 let busy=false;
 async function load(){
  if(busy)return;busy=true;el('validation-detail').hidden=true;el('validation-retry').hidden=true;
  el('validation-status').textContent='Consultando factura…';el('validation-status').dataset.state='';
  try{
   const reference=params.get('referencia'),signature=params.get('firma');
   if(!/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(reference||'') || !/^[a-f0-9]{64}$/.test(signature||''))throw new Error('El enlace de validación no es válido. Escanea el QR original de la factura.');
   const response=await fetch('/api/ventas/facturas/validar?'+new URLSearchParams({referencia:reference,firma:signature}),{headers:{Accept:'application/json'},cache:'no-store',referrerPolicy:'no-referrer'});
   const data=await response.json().catch(()=>({}));
   if(!response.ok)throw new Error(response.status===404?'No se encontró una factura válida para este enlace.':'No se pudo consultar la factura. Intenta de nuevo.');
   const f=data.factura,labels={confirmada:'Vigente',anulando:'Anulación en proceso',anulada:'Anulada'};
   if(!f || !Object.hasOwn(labels,f.estado))throw new Error('La respuesta de validación no es válida.');
   el('validation-status').dataset.state=f.estado;
   el('validation-status').textContent=f.estado==='confirmada'?'Factura registrada y vigente.':f.estado==='anulada'?'Factura anulada. Este comprobante ya no está vigente.':'Factura con anulación en proceso. Este comprobante no debe considerarse vigente.';
   el('validation-number').textContent=f.numero;el('validation-issuer').textContent=f.emisor.nombre;
   el('validation-nit').textContent=f.emisor.nit;el('validation-address').textContent=f.emisor.direccion;
   el('validation-date').textContent=new Intl.DateTimeFormat('es-GT',{timeZone:'America/Guatemala',dateStyle:'long',timeStyle:'short'}).format(new Date(f.fecha));
   el('validation-state').textContent=labels[f.estado];
   el('validation-total').textContent=new Intl.NumberFormat('es-GT',{style:'currency',currency:f.moneda}).format(Number(f.total));
   el('validation-checked').textContent='Estado consultado: '+new Intl.DateTimeFormat('es-GT',{timeZone:'America/Guatemala',dateStyle:'short',timeStyle:'short'}).format(new Date());
   el('validation-detail').hidden=false;
  }catch(error){el('validation-status').dataset.state='error';el('validation-status').textContent=error instanceof TypeError?'No se pudo conectar. Revisa tu conexión e intenta de nuevo.':error.message;}
  finally{busy=false;el('validation-retry').hidden=false;}
 }
 el('validation-retry').addEventListener('click',load);load();
})();
