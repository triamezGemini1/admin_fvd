// src/js/main.js

import {
    REPORTE_FILAS_POR_PAGINA,
    reporteEsc,
    reporteHtmlPaginador,
    reporteLigarPaginador,
    reporteSliceCliente,
} from './reporte_tabla.js';
import { mountPortalPerfilHeader } from './portal_perfil_header.js';

let atletaPagina = 1;

/** Contexto de sesión (auth_context) para botones del listado de atletas */
let mainAuthCtx = null;

let mainAtletaFiltroAsoc = 0;
let mainAtletaFiltroStatus = '';

/** Lista de asociaciones para filtro en inicio (caché; solo admin gral.) */
let mainAsocItemsCache = /** @type {any[] | null} */ (null);

document.addEventListener('DOMContentLoaded', async () => {
    await initNavSegunRol();
    await cargarAtletas();
});

/**
 * Muestra «Crear torneo» solo si la sesión es administración general FVD.
 */
async function initNavSegunRol() {
    const nav = document.getElementById('main-nav');
    try {
        const res = await fetch('api/auth_context.php', { credentials: 'same-origin' });
        const ctx = await res.json().catch(() => ({}));
        if (res.ok) {
            mainAuthCtx = ctx;
        }
        if (!nav) {
            return;
        }
        const links = [];
        if (ctx.puede_panel_admin) {
            links.push(
                '<a href="panel.html" class="btn-secondary" style="font-size: 0.85rem; padding: 0.45rem 0.9rem;">Administración</a>',
                '<a href="inscripciones.html" class="btn-secondary" style="font-size: 0.85rem; padding: 0.45rem 0.9rem;">Inscripciones</a>'
            );
            if (ctx.rol === 'delegado') {
                links[0] =
                    '<a href="panel.html" class="btn-secondary" style="font-size: 0.85rem; padding: 0.45rem 0.9rem;">Panel asociación</a>';
            }
        }
        if (ctx.capabilities?.torneos?.create === true) {
            links.push(
                '<a href="crear_torneo.html" class="btn-secondary" style="font-size: 0.85rem; padding: 0.45rem 0.9rem;">Crear torneo</a>'
            );
        }
        if (ctx.puede_afiliar_atleta === true) {
            links.push(
                '<a href="afiliar_atleta.html" class="btn-secondary" style="font-size: 0.85rem; padding: 0.45rem 0.9rem;">Afiliar atleta</a>'
            );
        }
        if (links.length) {
            nav.innerHTML = '<span class="main-nav-actions">' + links.join(' ') + '</span>';
        }
        if (ctx.logged) {
            mountPortalPerfilHeader(nav);
        }
    } catch (e) {
        console.warn('auth_context', e);
    }
}

function buildGetAtletasQuery() {
    if (!mainAuthCtx || !mainAuthCtx.puede_panel_admin) {
        return '';
    }
    const parts = [];
    if (mainAuthCtx.rol === 'delegado') {
        const my = Number(mainAuthCtx.asociacion_id) || 0;
        if (my > 0) {
            parts.push(`asociacion_id=${encodeURIComponent(String(my))}`);
        }
    } else if (mainAtletaFiltroAsoc > 0) {
        parts.push(`asociacion_id=${encodeURIComponent(String(mainAtletaFiltroAsoc))}`);
    }
    if (mainAtletaFiltroStatus !== '' && mainAtletaFiltroStatus !== null && mainAtletaFiltroStatus !== undefined) {
        parts.push(`status=${encodeURIComponent(String(mainAtletaFiltroStatus))}`);
    }
    return parts.length ? `?${parts.join('&')}` : '';
}

/** Último listado de atletas cargado (paginación en cliente) */
let mainAtletasDataset = [];

async function cargarAtletas() {
    const contenedor = document.getElementById('app-container');

    try {
        const qs = buildGetAtletasQuery();
        const response = await fetch(`api/get_atletas.php${qs}`, {
            credentials: mainAuthCtx && mainAuthCtx.puede_panel_admin ? 'same-origin' : 'same-origin',
        });
        const raw = await response.text();
        let datos;
        try {
            datos = JSON.parse(raw);
        } catch {
            throw new Error('La API no devolvió JSON válido');
        }

        if (!response.ok) {
            contenedor.innerHTML = `<p class="error">${reporteEsc(datos.message || 'Error del servidor')}</p>`;
            return;
        }

        if (datos.message) {
            contenedor.innerHTML = `<p class="error">${reporteEsc(datos.message)}</p>`;
            return;
        }

        if (!Array.isArray(datos)) {
            contenedor.innerHTML = '<p class="error">Respuesta inesperada de la API.</p>';
            return;
        }

        mainAtletasDataset = datos;
        await renderizarTablaAtletas();
    } catch (error) {
        console.error('Error al cargar atletas:', error);
        contenedor.innerHTML = '<p class="error">Error de conexión con la API.</p>';
    }
}

async function renderizarToolbarFiltrosAtletas() {
    const puedeFiltrar = !!(mainAuthCtx && mainAuthCtx.puede_panel_admin);
    if (!puedeFiltrar) return { asocHtml: '', stHtml: '' };
    let optsAsoc = '<option value="0">Todas</option>';
    if (mainAuthCtx.rol === 'admingral') {
        if (mainAsocItemsCache === null) {
            mainAsocItemsCache = [];
            try {
                const res = await fetch('api/crud_asociaciones.php?page=1&perPage=500', { credentials: 'same-origin' });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok && Array.isArray(data.items)) {
                    mainAsocItemsCache = data.items;
                }
            } catch (_) {
                mainAsocItemsCache = [];
            }
        }
        optsAsoc =
            `<option value="0"${Number(mainAtletaFiltroAsoc) === 0 ? ' selected' : ''}>Todas</option>` +
            mainAsocItemsCache
                .map(
                    (a) =>
                        `<option value="${reporteEsc(String(a.id))}"${Number(mainAtletaFiltroAsoc) === Number(a.id) ? ' selected' : ''}>${reporteEsc(a.nombre)}</option>`
                )
                .join('');
    } else {
        optsAsoc = `<option value="${reporteEsc(String(mainAuthCtx.asociacion_id || 0))}" selected>Mi asociación</option>`;
    }
    const st = String(mainAtletaFiltroStatus);
    const selTodos = st === '' ? ' selected' : '';
    const sel9 = st === '9' ? ' selected' : '';
    const sel0 = st === '0' ? ' selected' : '';
    const asocHtml = `<label class="admin-toolbar-filter"><span>Asociación</span><select id="main-atl-asoc" class="admin-search"${mainAuthCtx.rol === 'delegado' ? ' disabled' : ''}>${optsAsoc}</select></label>`;
    const stHtml = `<label class="admin-toolbar-filter"><span>Estatus portal</span><select id="main-atl-st" class="admin-search">
        <option value=""${selTodos}>Todos</option>
        <option value="0"${sel0}>Activo (0)</option>
        <option value="9"${sel9}>Inactivo (9)</option>
    </select></label>`;
    return { asocHtml, stHtml };
}

async function renderizarTablaAtletas() {
    const atletas = mainAtletasDataset;
    const mainContainer = document.getElementById('app-container');
    const total = atletas.length;
    const per = REPORTE_FILAS_POR_PAGINA;
    const slice = reporteSliceCliente(atletas, atletaPagina);
    const pager = reporteHtmlPaginador('atl', atletaPagina, total, per);
    const puedeNuevo = !!(mainAuthCtx && mainAuthCtx.puede_afiliar_atleta === true);
    const encCed = (c) => encodeURIComponent(String(c || '').trim());
    const { asocHtml, stHtml } = await renderizarToolbarFiltrosAtletas();
    const filtros = mainAuthCtx && mainAuthCtx.puede_panel_admin ? `<div class="admin-toolbar">${asocHtml}${stHtml}</div>` : '';

    const filas = slice
        .map((a) => {
            const c = encCed(a.cedula);
            const verHref = `afiliar_atleta.html?modo=ver&cedula=${c}`;
            const edHref = `afiliar_atleta.html?modo=editar&cedula=${c}`;
            const acc = `<div class="reporte-acciones">
            <a class="btn-secondary btn-sm reporte-btn-ic" href="${verHref}" title="Ver" aria-label="Ver"><span class="reporte-btn-ic-sym" aria-hidden="true">👁</span></a>
            <a class="btn-secondary btn-sm reporte-btn-ic" href="${edHref}" title="Editar" aria-label="Editar"><span class="reporte-btn-ic-sym" aria-hidden="true">✎</span></a>
            <button type="button" class="btn-secondary btn-sm reporte-btn-ic" disabled title="Activar / desactivar (panel FVD)" aria-label="Activar"><span class="reporte-btn-ic-sym" aria-hidden="true">⏻</span></button>
        </div>`;
            const sx = a.sexo === 1 ? 'M' : a.sexo === 2 ? 'F' : '—';
            const asoc = a.asociacion_nombre && String(a.asociacion_nombre).trim() !== '' ? String(a.asociacion_nombre) : '—';
            const ust = a.usuario_status !== null && a.usuario_status !== undefined ? String(a.usuario_status) : '—';
            return `<tr>
                        <td>${reporteEsc(String(a.numfvd))}</td>
                        <td>${reporteEsc(a.cedula)}</td>
                        <td>${reporteEsc(a.nombre)}</td>
                        <td>${reporteEsc(asoc)}</td>
                        <td>${reporteEsc(sx)}</td>
                        <td>${reporteEsc(ust)}</td>
                        <td class="reporte-td-acciones">${acc}</td>
                    </tr>`;
        })
        .join('');

    const nuevoBtn = puedeNuevo
        ? `<a href="afiliar_atleta.html?modo=nuevo" class="btn-primary btn-sm reporte-btn-ic" title="Nueva afiliación" aria-label="Nueva afiliación"><span class="reporte-btn-ic-sym" aria-hidden="true">➕</span></a>`
        : '';

    mainContainer.innerHTML = `
        <div class="card reporte-vista">
            ${nuevoBtn ? `<div class="main-atletas-head"><div class="main-atletas-head__actions">${nuevoBtn}</div></div>` : ''}
            ${filtros}
            ${pager}
            <table class="fvd-table">
                <thead>
                    <tr>
                        <th>FVD #</th>
                        <th>Cédula</th>
                        <th>Nombre Completo</th>
                        <th>Asociación</th>
                        <th>Sexo</th>
                        <th>Estatus</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    ${filas}
                </tbody>
            </table>
        </div>
    `;

    const aplicarFiltrosMainAtl = () => {
        if (mainAuthCtx && mainAuthCtx.rol === 'admingral') {
            mainAtletaFiltroAsoc = parseInt(String(document.getElementById('main-atl-asoc')?.value || '0'), 10) || 0;
        }
        const sv = document.getElementById('main-atl-st')?.value;
        mainAtletaFiltroStatus = sv === undefined || sv === null ? '' : String(sv);
        atletaPagina = 1;
        void cargarAtletas();
    };
    document.getElementById('main-atl-asoc')?.addEventListener('change', aplicarFiltrosMainAtl);
    document.getElementById('main-atl-st')?.addEventListener('change', aplicarFiltrosMainAtl);

    reporteLigarPaginador('atl', atletaPagina, total, (np) => {
        atletaPagina = np;
        void renderizarTablaAtletas();
    }, per);
}

function renderizarPanelInscripcion(atletas) {
    const container = document.getElementById('app-container');

    container.innerHTML = `
        <div class="dashboard-panel">
            <aside class="panel-column">
                <div class="column-header">Afiliados Disponibles</div>
                <div class="column-body" id="disponibles-list">
                    ${atletas.map(a => `
                        <div class="atleta-card" onclick="seleccionarAtleta(${a.id})">
                            <strong>${a.nombre}</strong><br>
                            <small>FVD: ${a.numfvd} | ${a.sexo}</small>
                        </div>
                    `).join('')}
                </div>
            </aside>

            <section class="panel-column">
                <div class="column-header">Configuración de Inscripción</div>
                <div class="column-body" id="work-area">
                    <div class="placeholder-msg">Seleccione un atleta para iniciar</div>
                </div>
            </section>

            <aside class="panel-column">
                <div class="column-header">Inscritos en Torneo</div>
                <div class="column-body" id="inscritos-list">
                    </div>
            </aside>
        </div>
    `;
}

async function manejarLogin(e) {
    e.preventDefault();
    const user = document.getElementById('user').value;
    const pass = document.getElementById('pass').value;

    const res = await fetch('api/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: user, password: pass }),
    });

    const data = await res.json();

    if (data.status === 'success') {
        location.reload();
    } else {
        alert(data.message);
    }
}
