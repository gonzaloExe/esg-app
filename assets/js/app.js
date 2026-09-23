/* ESG - JavaScript principal */
(function () {
    'use strict';

    const api = async (accion, opciones = {}) => {
        const method = opciones.method || 'GET';
        let url = 'api.php?accion=' + encodeURIComponent(accion);
        const fetchOptions = { method };
        if (method === 'POST') fetchOptions.body = opciones.body;

        const response = await fetch(url, fetchOptions);
        const data = await response.json().catch(() => ({ ok: false, error: 'Respuesta inválida del servidor.' }));
        if (!response.ok || !data.ok) throw new Error(data.error || 'Error en la operación.');
        return data;
    };

    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    };

    const fotoUrl = (id) => `foto.php?id=${encodeURIComponent(id)}`;

    const fotoHtml = (ticketId) => `
        <div class="ticket-photo-actions">
            <a href="${fotoUrl(ticketId)}" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                <i class="fa-solid fa-eye me-1"></i>Ver
            </a>
        </div>`;

    const fotoCellHtml = (ticketId, tieneFoto) => tieneFoto
        ? `<a href="${fotoUrl(ticketId)}" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                <i class="fa-solid fa-eye me-1"></i>Ver
           </a>`
        : '<span class="text-muted small">Sin foto</span>';

    let ticketsAdminCache = [];

    const normalizarFechaTicket = (valor) => {
        const texto = String(valor ?? '').trim();
        const iso = texto.match(/^(\d{4}-\d{2}-\d{2})/);
        if (iso) return iso[1];
        const latam = texto.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
        if (latam) return `${latam[3]}-${latam[2]}-${latam[1]}`;
        return '';
    };

    const obtenerFiltrosAdmin = () => ({
        fecha: document.getElementById('filtroFecha')?.value || '',
        ni: (document.getElementById('filtroNI')?.value || '').trim().toLowerCase()
    });

    const ticketsFiltradosAdmin = () => {
        const { fecha, ni } = obtenerFiltrosAdmin();
        return ticketsAdminCache.filter((t) => {
            const coincideFecha = !fecha || normalizarFechaTicket(t.fecha) === fecha;
            const coincideNI = !ni || String(t.numero_identificacion_pc ?? '').toLowerCase().includes(ni);
            return coincideFecha && coincideNI;
        });
    };

    const renderTicketsAdmin = () => {
        const tbody = document.querySelector('#tablaTickets tbody');
        if (!tbody) return;
        const tickets = ticketsFiltradosAdmin();
        const total = ticketsAdminCache.length;
        const resultado = document.getElementById('resultadoFiltros');
        const tieneFiltros = obtenerFiltrosAdmin();
        if (resultado) {
            resultado.textContent = (tieneFiltros.fecha || tieneFiltros.ni)
                ? `Mostrando ${tickets.length} de ${total} tickets.`
                : `${total} tickets registrados.`;
        }
        tbody.innerHTML = tickets.length
            ? tickets.map(t => `
                    <tr>
                        <td>${t.id}</td>
                        <td>
                            <strong>${escapeHtml(t.titulo)}</strong>
                            <div class="small text-muted">${escapeHtml(t.descripcion)}</div>
                        </td>
                        <td>${escapeHtml(t.pc_origen)}<br><small>${escapeHtml(t.usuario_origen)}</small></td>
                        <td><strong>${escapeHtml(t.numero_identificacion_pc)}</strong></td>
                        <td>${fotoCellHtml(t.id, !!t.foto)}</td>
                        <td>${escapeHtml(t.fecha)}</td>
                        <td>${badgeEstado(t.estado)}</td>
                        <td class="text-end text-nowrap">
                            ${t.estado === 'pendiente' ? `<button class="btn btn-success btn-sm me-1" onclick="ESGApp.marcarHecho(${t.id})"><i class="fa-solid fa-check"></i></button>` : ''}
                            <button class="btn btn-outline-danger btn-sm" onclick="ESGApp.borrarTicket(${t.id})"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                `).join('')
            : '<tr><td colspan="8" class="text-center text-muted py-4">No hay tickets que coincidan con los filtros.</td></tr>';
    };

    const badgeEstado = (estado) => estado === 'resuelto'
        ? '<span class="badge text-bg-success">🟢 Resuelto</span>'
        : '<span class="badge text-bg-warning">🟡 Pendiente</span>';

    async function cargarMisTickets() {
        const contenedor = document.getElementById('misTickets');
        if (!contenedor) return;
        contenedor.innerHTML = '<div class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</div>';

        try {
            const data = await api('obtener_mis_tickets');
            if (!data.tickets.length) {
                contenedor.innerHTML = '<div class="empty-state"><i class="fa-regular fa-folder-open fa-2x mb-2"></i><p class="mb-0">Todavía no tenés tickets.</p></div>';
                return;
            }

            contenedor.innerHTML = data.tickets.map(t => `
                <article class="ticket-item">
                    <div class="d-flex justify-content-between gap-2">
                        <h3 class="h6 mb-1">#${t.id} — ${escapeHtml(t.titulo)}</h3>
                        ${badgeEstado(t.estado)}
                    </div>
                    <p class="mb-2 text-muted">${escapeHtml(t.descripcion)}</p>
                    <div class="small text-secondary mb-2">
                        <strong>Número de identificación de la PC:</strong> ${escapeHtml(t.numero_identificacion_pc)} ·
                        <i class="fa-regular fa-clock me-1"></i>${escapeHtml(t.fecha)}
                        ${t.resuelto_por ? ' · Resuelto por: ' + escapeHtml(t.resuelto_por) : ''}
                    </div>
                    <div class="mt-2"><strong>Fotos:</strong></div>
                    ${t.foto ? fotoHtml(t.id, 'ticket-foto') : '<div class="small text-muted">Sin foto adjunta.</div>'}
                </article>
            `).join('');
        } catch (error) {
            contenedor.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
        }
    }

    async function enviarTicket(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const boton = form.querySelector('button[type="submit"]');
        boton.disabled = true;
        try {
            const formData = new FormData(form);
            await api('crear_ticket', { method: 'POST', body: formData });
            await Swal.fire({ icon: 'success', title: 'Ticket enviado', text: 'La incidencia fue registrada correctamente.', confirmButtonText: 'Aceptar' });
            form.reset();
            await cargarMisTickets();
        } catch (error) {
            await Swal.fire({ icon: 'error', title: 'No se pudo enviar', text: error.message });
        } finally {
            boton.disabled = false;
        }
    }

    async function cargarAdmin() {
        const tbodyTickets = document.querySelector('#tablaTickets tbody');
        const tbodyUsuarios = document.querySelector('#tablaUsuarios tbody');
        if (!tbodyTickets || !tbodyUsuarios) return;

        try {
            const [stats, tickets, usuarios] = await Promise.all([
                api('obtener_estadisticas'),
                api('obtener_todos_tickets'),
                api('obtener_usuarios')
            ]);

            document.getElementById('statTotal').textContent = stats.estadisticas.total;
            document.getElementById('statPendientes').textContent = stats.estadisticas.pendientes;
            document.getElementById('statResueltos').textContent = stats.estadisticas.resueltos;
            document.getElementById('statUsuarios').textContent = stats.estadisticas.usuarios;

            ticketsAdminCache = tickets.tickets || [];
            renderTicketsAdmin();

            tbodyUsuarios.innerHTML = usuarios.usuarios.map(u => `
                <tr>
                    <td><code>${escapeHtml(u.pc_identificador)}</code></td>
                    <td>${escapeHtml(u.nombre_usuario)}</td>
                    <td>${escapeHtml(u.rol)}</td>
                    <td>${Number(u.activo) === 1 ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Inactivo</span>'}</td>
                    <td>${escapeHtml(u.fecha_ultima_conexion || '-')}</td>
                    <td class="text-end">
                        ${u.rol === 'usuario' ? `<button class="btn btn-sm ${Number(u.activo) === 1 ? 'btn-outline-danger' : 'btn-outline-success'}" onclick="ESGApp.toggleUsuario(${u.id})">${Number(u.activo) === 1 ? 'Desactivar' : 'Activar'}</button>` : '<span class="text-muted">Protegido</span>'}
                    </td>
                </tr>
            `).join('');
        } catch (error) {
            Swal.fire({ icon: 'error', title: 'Error', text: error.message });
        }
    }

    async function postSimple(accion, datos) {
        const formData = new FormData();
        formData.append('csrf', window.ESG.csrf);
        Object.entries(datos).forEach(([k, v]) => formData.append(k, v));
        return api(accion, { method: 'POST', body: formData });
    }

    window.ESGApp = {
        marcarHecho: async function (id) {
            const c = await Swal.fire({ icon: 'question', title: '¿Marcar como hecho?', text: 'El ticket pasará a estado resuelto.', showCancelButton: true, confirmButtonText: 'Sí, marcar', cancelButtonText: 'Cancelar' });
            if (!c.isConfirmed) return;
            try { await postSimple('marcar_hecho', { id }); await cargarAdmin(); }
            catch (error) { Swal.fire({ icon: 'error', title: 'Error', text: error.message }); }
        },
        borrarTicket: async function (id) {
            const c = await Swal.fire({ icon: 'warning', title: '¿Borrar ticket?', text: 'Esta acción elimina el ticket permanentemente.', showCancelButton: true, confirmButtonText: 'Sí, borrar', cancelButtonText: 'Cancelar' });
            if (!c.isConfirmed) return;
            try { await postSimple('borrar_ticket', { id }); await cargarAdmin(); }
            catch (error) { Swal.fire({ icon: 'error', title: 'Error', text: error.message }); }
        },
        toggleUsuario: async function (id) {
            const c = await Swal.fire({ icon: 'question', title: 'Cambiar estado del usuario', text: '¿Deseás activar/desactivar este usuario?', showCancelButton: true, confirmButtonText: 'Sí, cambiar', cancelButtonText: 'Cancelar' });
            if (!c.isConfirmed) return;
            try { await postSimple('desactivar_usuario', { id }); await cargarAdmin(); }
            catch (error) { Swal.fire({ icon: 'error', title: 'Error', text: error.message }); }
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        const ticketForm = document.getElementById('ticketForm');
        if (ticketForm) {
            ticketForm.addEventListener('submit', enviarTicket);
            cargarMisTickets();
            document.getElementById('btnActualizar')?.addEventListener('click', cargarMisTickets);
        }
        if (document.getElementById('tablaTickets')) {
            cargarAdmin();
            document.getElementById('btnActualizarAdmin')?.addEventListener('click', cargarAdmin);
            document.getElementById('btnAplicarFiltros')?.addEventListener('click', renderTicketsAdmin);
            document.getElementById('btnLimpiarFiltros')?.addEventListener('click', () => {
                const fecha = document.getElementById('filtroFecha');
                const ni = document.getElementById('filtroNI');
                if (fecha) fecha.value = '';
                if (ni) ni.value = '';
                renderTicketsAdmin();
            });
            document.getElementById('filtroNI')?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') renderTicketsAdmin();
            });
            document.getElementById('filtroFecha')?.addEventListener('change', renderTicketsAdmin);
        }
    });
})();
