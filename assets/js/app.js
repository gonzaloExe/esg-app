/* ESG - JavaScript principal, sin frameworks */

(function () {
    'use strict';

    const api = async (accion, opciones = {}) => {
        const method = opciones.method || 'GET';
        let url = 'api.php?accion=' + encodeURIComponent(accion);
        const fetchOptions = { method };

        if (method === 'POST') {
            fetchOptions.body = opciones.body;
        }

        const response = await fetch(url, fetchOptions);
        const data = await response.json().catch(() => ({
            ok: false,
            error: 'Respuesta inválida del servidor.'
        }));

        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Error en la operación.');
        }

        return data;
    };

    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    };

    const badgeEstado = (estado) => {
        return estado === 'resuelto'
            ? '<span class="badge text-bg-success">🟢 Resuelto</span>'
            : '<span class="badge text-bg-warning">🟡 Pendiente</span>';
    };

    async function cargarMisTickets() {
        const contenedor = document.getElementById('misTickets');
        if (!contenedor) return;

        contenedor.innerHTML = '<div class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</div>';

        try {
            const data = await api('obtener_mis_tickets');

            if (!data.tickets.length) {
                contenedor.innerHTML = `
                    <div class="empty-state">
                        <i class="fa-regular fa-folder-open fa-2x mb-2"></i>
                        <p class="mb-0">Todavía no tenés tickets.</p>
                    </div>`;
                return;
            }

            contenedor.innerHTML = data.tickets.map(t => `
                <article class="ticket-item">
                    <div class="d-flex justify-content-between gap-2">
                        <h3 class="h6 mb-1">${escapeHtml(t.titulo)}</h3>
                        ${badgeEstado(t.estado)}
                    </div>
                    <p class="mb-2 text-muted">${escapeHtml(t.descripcion)}</p>
                    <small class="text-secondary">
                        <i class="fa-regular fa-clock me-1"></i>${escapeHtml(t.fecha)}
                        ${t.resuelto_por ? ' · Resuelto por: ' + escapeHtml(t.resuelto_por) : ''}
                    </small>
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

            await Swal.fire({
                icon: 'success',
                title: 'Ticket enviado',
                text: 'La incidencia fue registrada correctamente.',
                confirmButtonText: 'Aceptar'
            });

            form.reset();
            await cargarMisTickets();
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'No se pudo enviar',
                text: error.message
            });
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

            tbodyTickets.innerHTML = tickets.tickets.length
                ? tickets.tickets.map(t => `
                    <tr>
                        <td>${t.id}</td>
                        <td>
                            <strong>${escapeHtml(t.titulo)}</strong>
                            <div class="small text-muted">${escapeHtml(t.descripcion)}</div>
                            ${t.foto ? '<span class="small text-secondary"><i class="fa-regular fa-image"></i> Foto adjunta</span>' : ''}
                        </td>
                        <td>${escapeHtml(t.pc_origen)}<br><small>${escapeHtml(t.usuario_origen)}</small></td>
                        <td>${escapeHtml(t.fecha)}</td>
                        <td>${badgeEstado(t.estado)}</td>
                        <td class="text-end text-nowrap">
                            ${t.estado === 'pendiente'
                                ? `<button class="btn btn-success btn-sm me-1" onclick="ESGApp.marcarHecho(${t.id})"><i class="fa-solid fa-check"></i></button>`
                                : ''}
                            <button class="btn btn-outline-danger btn-sm" onclick="ESGApp.borrarTicket(${t.id})">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('')
                : '<tr><td colspan="6" class="text-center text-muted py-4">No hay tickets.</td></tr>';

            tbodyUsuarios.innerHTML = usuarios.usuarios.map(u => `
                <tr>
                    <td><code>${escapeHtml(u.pc_identificador)}</code></td>
                    <td>${escapeHtml(u.nombre_usuario)}</td>
                    <td>${escapeHtml(u.rol)}</td>
                    <td>${Number(u.activo) === 1 ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Inactivo</span>'}</td>
                    <td>${escapeHtml(u.fecha_ultima_conexion || '-')}</td>
                    <td class="text-end">
                        ${u.rol === 'usuario'
                            ? `<button class="btn btn-sm ${Number(u.activo) === 1 ? 'btn-outline-danger' : 'btn-outline-success'}" onclick="ESGApp.toggleUsuario(${u.id})">
                                ${Number(u.activo) === 1 ? 'Desactivar' : 'Activar'}
                               </button>`
                            : '<span class="text-muted">Protegido</span>'}
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
            const confirmacion = await Swal.fire({
                icon: 'question',
                title: '¿Marcar como hecho?',
                text: 'El ticket pasará a estado resuelto.',
                showCancelButton: true,
                confirmButtonText: 'Sí, marcar',
                cancelButtonText: 'Cancelar'
            });

            if (!confirmacion.isConfirmed) return;

            try {
                await postSimple('marcar_hecho', { id });
                await cargarAdmin();
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Error', text: error.message });
            }
        },

        borrarTicket: async function (id) {
            const confirmacion = await Swal.fire({
                icon: 'warning',
                title: '¿Borrar ticket?',
                text: 'Esta acción elimina el ticket permanentemente.',
                showCancelButton: true,
                confirmButtonText: 'Sí, borrar',
                cancelButtonText: 'Cancelar'
            });

            if (!confirmacion.isConfirmed) return;

            try {
                await postSimple('borrar_ticket', { id });
                await cargarAdmin();
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Error', text: error.message });
            }
        },

        toggleUsuario: async function (id) {
            const confirmacion = await Swal.fire({
                icon: 'question',
                title: 'Cambiar estado del usuario',
                text: '¿Deseás activar/desactivar este usuario?',
                showCancelButton: true,
                confirmButtonText: 'Sí, cambiar',
                cancelButtonText: 'Cancelar'
            });

            if (!confirmacion.isConfirmed) return;

            try {
                await postSimple('desactivar_usuario', { id });
                await cargarAdmin();
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Error', text: error.message });
            }
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
        }
    });
})();
