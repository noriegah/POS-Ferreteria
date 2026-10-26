"use strict";

// Reúne filtros, tarjetas, Chart.js y la descarga autenticada del reporte PDF.
const ReportView = (() => {
    const el = (id) => document.getElementById(id);
    let api, money, units, getToken, getSession, ensureSession, onUnauthorized, chart = null, page = 1, request = 0;
    let applied = null;
    function text(tag, value, className = '') {
        const node = document.createElement(tag); node.textContent = value; node.className = className; return node;
    }
    function clear() {
        request++; applied = null; page = 1;
        if (chart) { chart.destroy(); chart = null; }
        for (const id of ['report-series','report-categories','report-products','report-sales']) el(id).replaceChildren();
        for (const id of ['report-total','report-count','report-average','report-units']) el(id).textContent = '—';
        el('report-status').textContent = ''; el('report-results').hidden = true;
        el('report-prev').disabled = true; el('report-next').disabled = true;
        el('report-export').disabled = true;
    }
    function rank(target, rows) {
        const container = el(target); container.replaceChildren();
        if (!rows.length) { container.append(text('p','No hay ventas en este período.','hint')); return; }
        const maximum = Math.max(...rows.map(row => Number(row.unidades)), 1);
        for (const row of rows) {
            const item = text('div','','report-rank');
            const heading = text('div','','report-rank-heading');
            heading.append(text('strong',row.nombre), text('span',`${units(row.unidades)} uds. · ${money(row.total)}`));
            const bar = document.createElement('progress'); bar.max = maximum; bar.value = row.unidades;
            bar.setAttribute('aria-label',`${row.nombre}: ${units(row.unidades)} unidades`);
            item.append(heading,bar); container.append(item);
        }
    }
    function render(data) {
        const summary = data.resumen;
        el('report-total').textContent = money(summary.total);
        el('report-count').textContent = units(summary.ventas);
        el('report-average').textContent = money(summary.promedio);
        el('report-units').textContent = units(summary.unidades);
        el('report-period').textContent = `${data.periodo.desde} al ${data.periodo.hasta} · Hora de Guatemala`;
        el('report-empty').hidden = summary.ventas > 0;
        rank('report-categories',data.categorias);
        const products = el('report-products'); products.replaceChildren();
        for (const row of data.productos) {
            const tr = document.createElement('tr');
            for (const value of [row.codigo,row.nombre,units(row.unidades),money(row.total)]) tr.append(text('td',value));
            products.append(tr);
        }
        const sales = el('report-sales'); sales.replaceChildren();
        for (const row of data.ventas) {
            const tr = document.createElement('tr');
            for (const value of [`FAC-${String(row.id).padStart(6,'0')}`,row.fecha,row.comprador_nombre,money(row.total)]) tr.append(text('td',value));
            sales.append(tr);
        }
        for (const id of ['report-products','report-sales']) if (!el(id).children.length) {
            const cell = text('td','No hay ventas en este período.'); cell.colSpan = 4;
            const tr = document.createElement('tr'); tr.append(cell); el(id).append(tr);
        }
        page = data.paginacion.pagina;
        el('report-page').textContent = `Página ${page} de ${data.paginacion.paginas}`;
        el('report-prev').disabled = page <= 1;
        el('report-next').disabled = page >= data.paginacion.paginas;
        el('report-results').hidden = false;
        el('report-export').disabled = false;
        draw(data);
    }
    // El gráfico tiene una tabla equivalente para consultar cada importe sin usar el ratón.
    function draw(data) {
        if (chart) chart.destroy();
        const group = {dia:'día',semana:'semana',mes:'mes'}[data.agrupacion];
        el('report-chart-title').textContent = `Ventas por ${group}`;
        const rows = el('report-series'); rows.replaceChildren();
        for (const row of data.serie) {
            const tr = document.createElement('tr');
            for (const value of [row.etiqueta,units(row.ventas),money(row.total)]) tr.append(text('td',value));
            rows.append(tr);
        }
        const daily = data.agrupacion === 'dia';
        chart = new Chart(el('report-chart'),{
            type:daily ? 'line' : 'bar',
            data:{labels:data.serie.map(row=>row.etiqueta),datasets:[{
                label:'Total vendido',data:data.serie.map(row=>Number(row.total)),
                backgroundColor:daily ? 'rgba(25,118,101,.12)' : '#197665',borderColor:'#197665',
                borderWidth:daily ? 2 : 0,borderRadius:5,fill:daily,pointRadius:daily && data.serie.length<40 ? 3 : 0
            }]},
            options:{responsive:true,maintainAspectRatio:false,animation:false,locale:'es-GT',
                interaction:{mode:'index',intersect:false},
                plugins:{legend:{display:false},tooltip:{callbacks:{
                    label:context=>money(context.parsed.y),
                    afterLabel:context=>`${units(data.serie[context.dataIndex].ventas)} ventas`
                }}},
                scales:{x:{grid:{display:false},ticks:{autoSkip:true,maxTicksLimit:12,maxRotation:35}},
                    y:{beginAtZero:true,ticks:{callback:value=>money(value)},grid:{color:'#e8efeb'}}}
            }
        });
        el('report-chart').setAttribute('aria-label',`Ventas por ${group}. Total ${money(data.resumen.total)}. Los valores están en la tabla de datos.`);
    }
    async function load(newFilters = false, requestedPage = 1) {
        const ticket = ++request;
        if (newFilters || !applied) applied = {desde:el('report-from').value,hasta:el('report-to').value,agrupacion:el('report-group').value};
        const query = new URLSearchParams({...applied,pagina:String(requestedPage)});
        el('report-results').hidden = true;
        el('report-status').textContent = 'Cargando reportes…';
        el('report-filter-button').disabled = true;
        el('report-prev').disabled = true; el('report-next').disabled = true;
        el('report-export').disabled = true;
        try {
            const data = await api(`reportes/resumen?${query}`);
            if (ticket !== request) return;
            render(data); el('report-status').textContent = '';
        } catch (error) {
            if (ticket === request) el('report-status').textContent = error.message;
        } finally {
            if (ticket === request) el('report-filter-button').disabled = false;
        }
    }
    async function download() {
        if (!applied) return;
        // Exige aplicar las fechas editadas para que el archivo coincida con el período mostrado.
        if (applied.desde!==el('report-from').value || applied.hasta!==el('report-to').value || applied.agrupacion!==el('report-group').value) {
            el('report-status').textContent = 'Aplica los filtros antes de exportar.'; return;
        }
        const ticket = request; const session = getSession(); const filters = {...applied};
        el('report-export').disabled = true; el('report-status').textContent = 'Generando PDF del período completo…';
        try {
            await ensureSession();
            if(session!==getSession()) return;
            const response = await fetch(`/api/reportes/pdf?${new URLSearchParams(filters)}`,{
                headers:{Authorization:`Bearer ${getToken()}`,Accept:'application/pdf'},cache:'no-store'
            });
            if (response.status===401 && getSession()===session) { onUnauthorized(); return; }
            if (!response.ok) {
                const body = await response.json().catch(()=>({}));
                throw new Error(body.error || 'No se pudo generar el PDF. Intenta de nuevo.');
            }
            if (!response.headers.get('Content-Type')?.startsWith('application/pdf')) throw new Error('El servidor no devolvió un PDF válido.');
            const blob = await response.blob();
            if (ticket!==request || getSession()!==session) return;
            const url = URL.createObjectURL(blob); const link = document.createElement('a');
            link.href = url; link.download = `reporte-ventas-${filters.desde}-${filters.hasta}.pdf`;
            document.body.append(link); link.click(); link.remove(); setTimeout(()=>URL.revokeObjectURL(url),30000);
            el('report-status').textContent = 'PDF generado. Incluye todas las ventas del período.';
        } catch (error) {
            if (ticket===request) el('report-status').textContent = error.message || 'No se pudo descargar el PDF.';
        } finally {
            if (ticket===request) el('report-export').disabled = false;
        }
    }
    function init(options) {
        ({api,money,units,getToken,getSession,ensureSession,onUnauthorized} = options);
        const year = new Intl.DateTimeFormat('en-US',{year:'numeric',timeZone:'America/Guatemala'}).format(new Date());
        el('report-from').value = `${year}-01-01`; el('report-to').value = `${year}-12-31`;
        el('report-filters').addEventListener('submit',event => { event.preventDefault(); load(true); });
        el('report-prev').addEventListener('click',()=>load(false,page-1));
        el('report-next').addEventListener('click',()=>load(false,page+1));
        el('report-export').addEventListener('click',download);
    }
    return {init,load,clear};
})();
