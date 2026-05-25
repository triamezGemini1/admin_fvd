/**
 * Componente tarjeta FVD (Admin Gral / Admin Asoc).
 * Título pastel + degradado en cabecera; ítems con acento corporativo.
 */

import { reporteEsc } from './reporte_tabla.js';

/** @type {readonly string[]} */
export const FVD_CARD_TONES = ['servicios', 'supervision', 'operaciones', 'finanzas', 'delegado'];

/**
 * @param {string} tone
 */
function normalizeTone(tone) {
    const t = String(tone || 'servicios')
        .toLowerCase()
        .replace(/[^a-z]/g, '');
    return FVD_CARD_TONES.includes(t) ? t : 'servicios';
}

/**
 * @param {string} accent
 */
function normalizeAccent(accent) {
    const a = String(accent || 'sup3')
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '');
    return a || 'sup3';
}

/**
 * @param {Record<string, string>} attrs
 */
function buildDataAttrs(attrs) {
    if (!attrs || typeof attrs !== 'object') return '';
    return Object.entries(attrs)
        .map(([k, v]) => {
            const key = String(k).replace(/[^a-z0-9_-]/gi, '');
            if (!key) return '';
            return ` data-${key.replace(/_/g, '-')}="${reporteEsc(String(v))}"`;
        })
        .join('');
}

/**
 * @param {{
 *   label: string,
 *   icon?: string,
 *   accent?: string,
 *   href?: string,
 *   type?: string,
 *   attrs?: Record<string, string>
 * }} item
 */
export function htmlFvdCardItem(item) {
    const accent = normalizeAccent(item.accent);
    const icon = item.icon ? `<span class="fvd-card__item-ic" aria-hidden="true">${reporteEsc(item.icon)}</span>` : '';
    const label = item.label != null ? String(item.label) : '';
    const cls = `fvd-card__item fvd-card__item--accent-${accent}`;
    const data = buildDataAttrs(item.attrs || {});
    const inner = `<span class="fvd-card__item-label">${label}</span>${icon}`;
    const href = item.href ? String(item.href).trim() : '';
    if (href) {
        return `<a class="${cls} fvd-card__item--link" href="${reporteEsc(href)}"${data}>${inner}</a>`;
    }
    const type = item.type === 'submit' ? 'submit' : 'button';
    return `<button type="${type}" class="${cls}"${data}>${inner}</button>`;
}

/**
 * @param {{
 *   tone?: string,
 *   title: string,
 *   titleId?: string,
 *   items?: Array<Parameters<typeof htmlFvdCardItem>[0]>,
 *   extraClass?: string
 * }} opts
 */
export function htmlFvdCard(opts) {
    const tone = normalizeTone(opts.tone);
    const titleId = opts.titleId ? reporteEsc(String(opts.titleId)) : '';
    const idAttr = titleId ? ` id="${titleId}"` : '';
    const labelledBy = titleId || 'fvd-card-h';
    const extra = opts.extraClass ? ` ${String(opts.extraClass).replace(/[^a-zA-Z0-9_\\-\\s]/g, '')}` : '';
    const items = Array.isArray(opts.items) ? opts.items : [];
    const body = items.map((it) => htmlFvdCardItem(it)).join('');

    return `<section class="fvd-card fvd-card--tone-${tone}${extra}" aria-labelledby="${labelledBy}">
        <header class="fvd-card__head"><h2 class="fvd-card__title"${idAttr}>${reporteEsc(opts.title || '')}</h2></header>
        <div class="fvd-card__body">${body}</div>
    </section>`;
}

/**
 * @param {Array<Parameters<typeof htmlFvdCard>[0]>} cards
 * @param {string} [gridClass]
 */
export function htmlFvdCardGrid(cards, gridClass = '') {
    const list = Array.isArray(cards) ? cards : [];
    const cls = gridClass ? `fvd-card-grid ${gridClass}` : 'fvd-card-grid';
    return `<div class="${cls}">${list.map((c) => htmlFvdCard(c)).join('')}</div>`;
}
