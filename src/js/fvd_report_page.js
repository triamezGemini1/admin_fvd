/**
 * Inicialización común de páginas de informes / reportes FVD.
 */
import {
    ensureFvdBrandLogos,
    ensureFvdHeaderTitle,
    ensureFvdPrintBrand,
    ensureFvdReportFooters,
} from './fvd_app_header.js';
import { mountPortalPerfilHeader } from './portal_perfil_header.js';
import { ensurePortalJornadaBarPlacement } from './delegado_torneos_bar.js';

/**
 * @param {{ nav?: string, mountPerfil?: boolean }} [opts]
 */
/** Reaplica marca tras render dinámico (tablas / hojas generadas en cliente). */
export function refreshFvdReportBranding() {
    ensureFvdHeaderTitle();
    ensureFvdBrandLogos();
    ensureFvdPrintBrand();
    ensureFvdReportFooters();
}

export function initFvdReportPage(opts = {}) {
    const navSelector = opts.nav ?? '#main-nav, .main-nav-actions';
    const mountPerfil = opts.mountPerfil !== false;

    ensurePortalJornadaBarPlacement();
    refreshFvdReportBranding();

    if (mountPerfil) {
        const navEl = document.querySelector(navSelector);
        if (navEl) {
            mountPortalPerfilHeader(navEl);
        }
    }
}
