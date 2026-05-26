/**
 * Jornada: asociación + torneo activo (único para inscripciones).
 * Selector de torneo solo si hay campeonato con 2+ variantes activas.
 */

const KEY_ASOC = 'fvd_jornada_asociacion';
const KEY_TORNEO = 'fvd_jornada_torneo_activo';
const KEY_TORNEOS_LIST = 'fvd_jornada_torneos_activos';
const KEY_CAMPEONATO_VARIANTES = 'fvd_jornada_campeonato_variantes';

function esc(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

async function fetchJson(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

/** @type {{ id: number, nombre: string, logo?: string, delegado?: string } | null} */
let asociacionJornada = null;

/** @type {{ torneo: number, nombre: string } | null} */
let torneoActivoJornada = null;

/** @type {number | null} */
let torneoActivoCanonicoId = null;

/** @type {Array<{ torneo: number, nombre: string }>} */
let campeonatoVariantesJornada = [];

/** @type {boolean} */
let permiteSelectorCampeonato = false;

/**
 * @param {object} ctx
 */
export function persistAsociacionJornada(ctx) {
    const id = ctx.asociacion_id != null ? parseInt(String(ctx.asociacion_id), 10) : 0;
    if (id < 1) return;
    const a = ctx.asociacion_activa && typeof ctx.asociacion_activa === 'object' ? ctx.asociacion_activa : {};
    let del = String(a.delegado || '').trim();
    if (del === '0' || del === '0.0') del = '';
    const payload = {
        id,
        nombre: String(a.nombre || '').trim() || `Asociación #${id}`,
        logo: String(a.logo || '').trim(),
        delegado: del,
    };
    asociacionJornada = payload;
    try {
        sessionStorage.setItem(KEY_ASOC, JSON.stringify(payload));
    } catch (_) {}
}

function persistTorneoActivoJornada(torneo) {
    if (!torneo || !torneo.torneo) return;
    torneoActivoJornada = {
        torneo: parseInt(String(torneo.torneo), 10) || 0,
        nombre: String(torneo.nombre || '').trim() || `Torneo ${torneo.torneo}`,
    };
    try {
        sessionStorage.setItem(KEY_TORNEO, JSON.stringify(torneoActivoJornada));
        sessionStorage.setItem('fvd_delegado_torneo_id', String(torneoActivoJornada.torneo));
    } catch (_) {}
}

function persistCampeonatoVariantes(variantes, permite) {
    campeonatoVariantesJornada = Array.isArray(variantes) ? variantes : [];
    permiteSelectorCampeonato = !!permite && campeonatoVariantesJornada.length >= 2;
    try {
        if (campeonatoVariantesJornada.length > 0) {
            sessionStorage.setItem(KEY_CAMPEONATO_VARIANTES, JSON.stringify(campeonatoVariantesJornada));
        } else {
            sessionStorage.removeItem(KEY_CAMPEONATO_VARIANTES);
        }
    } catch (_) {}
}

/**
 * @param {object} ctx auth_context / torneos_activos_list
 */
export function persistJornadaDesdeAuth(ctx) {
    if (!ctx || typeof ctx !== 'object') return;
    persistAsociacionJornada(ctx);
    if (ctx.torneo_activo_id != null) {
        torneoActivoCanonicoId = parseInt(String(ctx.torneo_activo_id), 10) || null;
    }
    persistCampeonatoVariantes(ctx.campeonato_variantes, ctx.permite_selector_campeonato);
    if (ctx.torneo_jornada && ctx.torneo_jornada.torneo > 0) {
        persistTorneoActivoJornada(ctx.torneo_jornada);
    } else if (ctx.torneo_jornada_id > 0) {
        const found = (ctx.campeonato_variantes || []).find((t) => Number(t.torneo) === Number(ctx.torneo_jornada_id));
        persistTorneoActivoJornada(
            found || { torneo: ctx.torneo_jornada_id, nombre: `Torneo ${ctx.torneo_jornada_id}` }
        );
    }
}

export function getAsociacionJornada() {
    if (asociacionJornada) return asociacionJornada;
    try {
        const raw = sessionStorage.getItem(KEY_ASOC);
        if (!raw) return null;
        const o = JSON.parse(raw);
        if (o && o.id > 0) {
            asociacionJornada = o;
            return o;
        }
    } catch (_) {
        /* ignore */
    }
    return null;
}

export function getCampeonatoVariantesJornada() {
    if (campeonatoVariantesJornada.length > 0) return campeonatoVariantesJornada;
    try {
        const raw = sessionStorage.getItem(KEY_CAMPEONATO_VARIANTES);
        if (!raw) return [];
        const arr = JSON.parse(raw);
        if (Array.isArray(arr)) {
            campeonatoVariantesJornada = arr;
            return arr;
        }
    } catch (_) {
        /* ignore */
    }
    return [];
}

export function permiteSelectorCampeonatoJornada() {
    if (permiteSelectorCampeonato) return true;
    return getCampeonatoVariantesJornada().length >= 2;
}

/**
 * @returns {number}
 */
export function getDelegadoTorneoIdSeleccionado() {
    if (torneoActivoJornada) {
        return parseInt(String(torneoActivoJornada.torneo), 10) || 0;
    }
    const cached = readTorneoActivoJornadaCache();
    return cached ? parseInt(String(cached.torneo), 10) || 0 : 0;
}

/** @deprecated */
export function getDelegadoTorneosCache() {
    return permiteSelectorCampeonatoJornada() ? getCampeonatoVariantesJornada() : torneoActivoJornada ? [torneoActivoJornada] : [];
}

/**
 * @param {number} torneoId
 */
export function setDelegadoTorneoIdSeleccionado(torneoId) {
    const id = parseInt(String(torneoId), 10) || 0;
    if (id < 1) return;
    if (!permiteSelectorCampeonatoJornada()) {
        return;
    }
    const list = getCampeonatoVariantesJornada();
    const found = list.find((t) => Number(t.torneo) === id);
    if (!found) return;
    persistTorneoActivoJornada(found);
}

function readTorneoActivoJornadaCache() {
    try {
        const raw = sessionStorage.getItem(KEY_TORNEO);
        if (!raw) return null;
        const o = JSON.parse(raw);
        if (o && o.torneo > 0) {
            torneoActivoJornada = o;
            return o;
        }
    } catch (_) {
        /* ignore */
    }
    return null;
}

function panelAdminActivo(auth) {
    return !!(auth && auth.logged && (auth.rol === 'admingral' || auth.rol === 'delegado'));
}

function aplicarDatosJornada(data, options = {}) {
    if (!data || typeof data !== 'object') return;
    persistJornadaDesdeAuth(data);
    const tidSel = getDelegadoTorneoIdSeleccionado();
    if (tidSel < 1 && data.torneo_jornada) {
        persistTorneoActivoJornada(data.torneo_jornada);
    }
}

function htmlTorneoJornada(data, options = {}) {
    const tid = getDelegadoTorneoIdSeleccionado();
    const variantes = getCampeonatoVariantesJornada();
    const selector = permiteSelectorCampeonatoJornada();

    if (tid < 1) {
        return '<span class="portal-jornada-tor portal-jornada-tor--empty">Sin torneo activo</span>';
    }

    if (selector) {
        const opts = variantes
            .map((t) => {
                const id = parseInt(String(t.torneo), 10) || 0;
                const sel = id === tid ? ' selected' : '';
                return `<option value="${id}"${sel}>${esc(t.nombre || `Torneo ${id}`)}</option>`;
            })
            .join('');
        return (
            `<span class="portal-jornada-tor portal-jornada-tor--camp">` +
            `<span class="portal-jornada-tor__label">Variante campeonato:</span> ` +
            `<select id="portal-jornada-tor-select" class="portal-jornada-tor-select" aria-label="Variante del campeonato">${opts}</select>` +
            `</span>`
        );
    }

    const lab = esc(torneoActivoJornada?.nombre || '');
    return (
        `<span class="portal-jornada-tor">` +
        `<span class="portal-jornada-tor__label">Torneo activo:</span> ` +
        `<strong class="portal-jornada-tor__name">${lab}</strong>` +
        `</span>`
    );
}

const JORNADA_BAR_ID = 'delegado-torneos-context';

/**
 * Coloca la barra de jornada (ámbito + torneo activo) justo debajo de header.main-header.
 */
export function ensurePortalJornadaBarPlacement() {
    const hdr = document.querySelector('header.main-header');
    if (!hdr) return null;

    let wrap = document.getElementById(JORNADA_BAR_ID);
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = JORNADA_BAR_ID;
        wrap.className = 'delegado-torneos-context is-hidden';
        wrap.setAttribute('aria-label', 'Torneos activos');
    }

    if (hdr.nextElementSibling !== wrap) {
        hdr.insertAdjacentElement('afterend', wrap);
    }

    return wrap;
}

/**
 * @param {{
 *   showWhen?: (ctx: object) => boolean,
 *   onTorneoChange?: (torneoId: number) => void,
 * }} [options]
 */
export async function initDelegadoTorneosBar(options = {}) {
    const wrap = ensurePortalJornadaBarPlacement();
    if (!wrap) return;

    let auth = { rol: '', asociacion_id: null, logged: false };
    try {
        const { res, data } = await fetchJson('api/auth_context.php');
        if (res.ok && data.logged) {
            auth = { logged: true, rol: data.rol || '', asociacion_id: data.asociacion_id };
            aplicarDatosJornada(data, options);
        }
    } catch (_) {
        /* ignore */
    }

    const show =
        typeof options.showWhen === 'function'
            ? options.showWhen(auth)
            : panelAdminActivo(auth) || (getAsociacionJornada()?.id ?? 0) > 0;
    if (!show) {
        wrap.classList.add('is-hidden');
        wrap.innerHTML = '';
        return;
    }

    wrap.classList.remove('is-hidden');
    wrap.innerHTML = '<p class="ag-muted delegado-torneos-context__empty">Cargando jornada…</p>';

    const esAg = auth.rol === 'admingral';
    const asoc = getAsociacionJornada();
    const tidNow = getDelegadoTorneoIdSeleccionado();
    const q =
        tidNow > 0 && permiteSelectorCampeonatoJornada()
            ? `api/torneos_activos_list.php?torneo_id=${encodeURIComponent(String(tidNow))}`
            : 'api/torneos_activos_list.php';
    const { res, data } = await fetchJson(q);
    if (res.ok && data.ok) {
        aplicarDatosJornada(data, options);
    }

    let asocHtml = '';
    if (esAg && (!asoc || asoc.id < 1)) {
        asocHtml = `<span class="portal-jornada-asoc"><span class="portal-jornada-asoc__label">Ámbito:</span> <strong>Administración general FVD</strong></span>`;
    } else if (asoc && asoc.id > 0) {
        asocHtml = `<span class="portal-jornada-asoc"><span class="portal-jornada-asoc__label">Asociación:</span> <strong>${esc(asoc.nombre || 'Asociación')}</strong></span>`;
    }

    const sep = asocHtml ? `<span class="portal-jornada-sep" aria-hidden="true">|</span>` : '';
    const torneoHtml = htmlTorneoJornada(data, options);

    wrap.innerHTML =
        `<div class="delegado-torneos-context__inner portal-jornada-bar__inner">` +
        asocHtml +
        sep +
        torneoHtml +
        `</div>`;

    const sel = document.getElementById('portal-jornada-tor-select');
    if (sel) {
        sel.addEventListener('change', () => {
            const id = parseInt(String(sel.value), 10) || 0;
            if (id < 1) return;
            setDelegadoTorneoIdSeleccionado(id);
            if (typeof options.onTorneoChange === 'function') {
                options.onTorneoChange(id);
            }
        });
    }
}

export async function initPortalJornadaBar(options = {}) {
    return initDelegadoTorneosBar(options);
}
