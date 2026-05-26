/**
 * Marca institucional FVD (cabeceras, logos e impresión de reportes).
 */
export const FVD_HEADER_TITLE = 'FEDERACION VENEZOLANA DE DOMINO';
export const FVD_ORG_LABEL = 'Federación Venezolana de Dominó';
export const FVD_LOGO_URL = 'img/fvd-portal-logo.png';

const FVD_LOGO_SELECTORS = 'img.main-logo, img.ag-gasto-torneo-logo, .ag-fvd-print-brand__logo';

const FVD_PRINT_BRAND_TARGETS = '.ag-fvd-reporte-hoja, .ag-fin-periodo-main, .ag-gasto-torneo-main';

export function fvdPrintBrandMarkup(logoUrl = FVD_LOGO_URL) {
    return (
        `<div class="ag-fvd-print-brand" aria-hidden="true">` +
        `<img class="ag-fvd-print-brand__logo" src="${logoUrl}" alt="${FVD_ORG_LABEL}">` +
        `<p class="ag-fvd-print-brand__title">${FVD_HEADER_TITLE}</p>` +
        `</div>`
    );
}

export function ensureFvdBrandLogos() {
    document.querySelectorAll(FVD_LOGO_SELECTORS).forEach((img) => {
        if (img.getAttribute('src') !== FVD_LOGO_URL) {
            img.setAttribute('src', FVD_LOGO_URL);
        }
        if (!img.getAttribute('alt')) {
            img.setAttribute('alt', FVD_ORG_LABEL);
        }
    });
}

export function ensureFvdHeaderTitle() {
    document.querySelectorAll('header.main-header').forEach((hdr) => {
        const brand = hdr.querySelector('.brand-container');
        let title = hdr.querySelector('.fvd-header-title');
        if (!title) {
            title = document.createElement('p');
            title.className = 'fvd-header-title';
            title.textContent = FVD_HEADER_TITLE;
            title.setAttribute('aria-label', FVD_ORG_LABEL);
            if (brand) {
                brand.appendChild(title);
            } else {
                hdr.insertBefore(title, hdr.firstChild);
            }
        } else if (brand && title.parentElement !== brand) {
            brand.appendChild(title);
        }
    });
    document.querySelectorAll('.ag-gasto-torneo-org.fvd-header-title, #gasto-org-nombre').forEach((el) => {
        el.textContent = FVD_HEADER_TITLE;
    });
}

export function ensureFvdPrintBrand() {
    document.querySelectorAll(FVD_PRINT_BRAND_TARGETS).forEach((root) => {
        if (root.querySelector('.ag-fvd-print-brand')) {
            return;
        }
        const wrap = document.createElement('div');
        wrap.innerHTML = fvdPrintBrandMarkup();
        root.insertBefore(wrap.firstElementChild, root.firstChild);
    });
}

export function ensureFvdReportFooters() {
    const year = String(new Date().getFullYear());
    document.querySelectorAll('.ag-fvd-report-footer [data-fvd-year]').forEach((el) => {
        el.textContent = year;
    });
    document.querySelectorAll('body.ag-fvd-report-page > footer:not(.ag-fvd-report-footer) p').forEach((p) => {
        if (/Federaci[oó]n Venezolana de Domin[oó]/i.test(p.textContent || '')) {
            p.textContent = `© ${year} ${FVD_ORG_LABEL} — Portal administrativo`;
        }
    });
}

function initFvdAppHeader() {
    ensureFvdHeaderTitle();
    ensureFvdBrandLogos();
    ensureFvdPrintBrand();
    ensureFvdReportFooters();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFvdAppHeader);
} else {
    initFvdAppHeader();
}
