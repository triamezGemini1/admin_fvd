# Guía visual FVD Portal (auditoría de diseño)

Este documento describe la apariencia **implementada en código** (HTML + `dist/assets/css/main.css`) para que puedas cotejarla con los mockups o capturas que enviaste. No sustituye capturas de pantalla reales; sirve como checklist de estructura, color y layout.

---

## Rutas de estilo (verificación)

- **Único bundle CSS:** `dist/assets/css/main.css`
- **Referencias:** `index.php`, `panel.html`, `inscripciones.html`, `afiliar_atleta.html` y `crear_torneo.html` enlazan con  
  `href="dist/assets/css/main.css"` (ruta relativa desde la raíz del sitio `admin_fvd`).

---

## Variables de color globales (`:root`)

| Token | Valor | Uso principal |
|--------|--------|----------------|
| `--primary-blue` | `#004a99` | Títulos, bordes de cabecera, acentos institucionales |
| `--accent-gold` | `#c5a059` | Botón primario dorado (login y acciones destacadas) |
| `--light-grey` | `#f4f7f6` | Fondo general del `body` en vistas de panel/tablas |
| `--white` | `#ffffff` | Cabecera, tarjetas, tablas sobre fondo claro |
| `--text-main` | `#333333` | Texto principal |
| `--error-red` / `--success-green` | `#dc3545` / `#28a745` | Mensajes de error y éxito |

**“Azul profundo” (login):** la pantalla de acceso usa un fondo en **degradado** de `var(--primary-blue)` hacia `#002a55` (clase `.login-container`), con la **caja de login blanca**, sombra y bordes redondeados.

---

## 1. Login (`index.php` sin sesión)

- **Fondo:** pantalla completa, flex centrado; gradiente diagonal azul oscuro (institucional).
- **Contenido:** tarjeta `.login-box` centrada (máx. ~400px): logo FVD arriba, título “Portal FVD”, texto gris `#555`, campos a ancho completo con borde gris claro, botón **dorado** “Entrar” (`.btn-primary`).
- **Tipografía:** sistema (Segoe UI, Tahoma, etc.).

---

## 2. Cabecera común (`header.main-header`)

- Fondo blanco, borde inferior **3px** en `--primary-blue`.
- **Izquierda:** logo + (en el shell del portal con sesión) contenedor de afiliados secundarios.
- **Derecha:** navegación con botones estilo `.btn-secondary` (gris oscuro `#2c3e50`).
- **Comportamiento:** `position: sticky; top: 0` para mantener la barra visible al desplazarse.

---

## 3. Panel de administración (`panel.html`)

- **Contenedor:** `#admin-root.admin-panel` — área principal bajo la cabecera; mensajes de acceso y pestañas se montan por JS (`panel.js`).
- **Modal:** diálogo `.admin-modal` para formularios de edición (título, cuerpo, botones Guardar/Cerrar).
- **Pie:** footer centrado, texto gris, borde superior claro.

*(Las tarjetas CRUD concretas dependen del contenido generado en JS; el marco visual sigue tarjetas blancas, sombras suaves y tipografía azul en títulos de sección.)*

---

## 4. Inscripciones al torneo (`inscripciones.html`)

### Bloque superior

- Título “Inscripciones” en `--primary-blue`.
- Zona de formulario con subtítulo “Formulario de inscripción” y texto de ayuda; controles generados en `#ins-form-zone`.

### Layout de **dos columnas** (listas)

- Contenedor `.inscripciones-columns`: **grid de 2 columnas iguales** (`1fr 1fr`), gap ~1rem.
- **Breakpoint:** por debajo de **900px** pasa a **una columna** apilada.
- Cada columna (`.inscripciones-col`): borde `#e2e6ea`, fondo `#fafbfc`, cabecera en azul institucional (“Disponibles” / “Inscritos”), texto lead gris `#555`.
- Listas `.inscripciones-ul`: altura máxima acotada (`min(52vh, 420px)`), **scroll vertical interno** en cada lista.

> **Nota de alcance:** En el HTML actual la sección “Disponibles e inscritos” es **2 columnas**. La clase `.dashboard-panel` del CSS define un layout de **3 columnas** (300px | 1fr | 350px) pensado para otro flujo (delegados); si tu mockup exige 3 columnas fijas en esta misma página, habría que alinear el markup/JS con ese grid.

---

## 5. Formulario de afiliación (`afiliar_atleta.html`)

### Marco general

- `body.afiliacion-body`: columna flex, altura mínima 100vh.
- `main.afiliacion-main`: relleno ~1rem, fondo **`#f4f6f9`** (gris azulado claro, distinto del login).
- Tarjeta `.afiliacion-card`: ancho máx. **900px**, centrada, fondo blanco, radio 10px, sombra ligera, borde `#e2e8f0`.

### Títulos y texto

- H1 `.afiliacion-title`: azul `--primary-blue`, ~1.35rem.
- Párrafo lead gris `#555`.
- Subtítulo “Multimedia” `.afiliacion-subtitle`: mismo azul institucional.

### Grid del formulario (datos del atleta)

- `.afiliacion-grid`: **2 columnas** (`1fr 1fr`), gap horizontal ~1rem.
- Campo ancho completo (nombre): `.afiliacion-field--full` ocupa ambas columnas.
- **Móvil:** por debajo de **720px** el grid pasa a **1 columna**.
- Inputs: borde `#ccc`, padding uniforme, esquinas 6px.

### Categoría por edad

- Bloque `.afiliacion-categoria`: fondo **`#eef6ff`**, texto en `--primary-blue`, negrita.
- Delegado sin Nº FVD: `.afiliacion-pendiente` con fondo crema `#fff8e6`, borde discontinuo dorado.

### Multimedia y **vistas previas de fotos**

- Subsección `.afiliacion-grid--media`: mismo grid de **2 columnas** (foto del atleta | imagen de cédula).
- Cada bloque `.afiliacion-media`: input `file` (JPEG/PNG/Webp) y debajo un contenedor `.afiliacion-preview`:
  - **Estado inicial:** placeholder “Vista previa” (`.afiliacion-preview-ph`, gris `#888`) centrado en caja de mín. **120px** de alto, fondo `#f8f9fa`, borde **discontinuo** `#ccc`, esquinas 8px.
  - **Tras elegir imagen:** `afiliar_atleta.js` asigna `data:` URL a `img.afiliacion-preview-img` (`display: block`), oculta el placeholder; imagen con `max-height: 160px`, `border-radius: 6px`.
- Si la cédula ya existe en BD, las mismas previews pueden rellenarse desde URL (`setPreviewFromUrl`).

### Botonera

- `.afiliacion-actions`: flex con wrap, separador superior; botones reutilizan `.torneo-alta-btn` (primario relleno azul en esta pantalla; secundarios translúcidos sobre contexto claro).

---

## 6. Crear torneo (`crear_torneo.html`)

- Tarjeta `.torneo-form-card`, grid de campos `.torneo-form-grid` (auto-fill mín. 200px).
- Fila de archivos `.torneo-file-row`: **2 columnas** en escritorio, 1 en móvil; previews de afiche con caja mínima y placeholder gris.

---

## Resumen para cotejo con tus imágenes

1. **Login:** gradiente azul profundo + tarjeta blanca + CTA dorada.  
2. **Interior:** fondos claros (`--light-grey` o `#f4f6f9`), cabecera blanca con ribete azul.  
3. **Afiliación:** tarjeta blanca centrada, formulario en **2 columnas**, multimedia en **2 columnas** con **previews enmarcadas** (discontinuas hasta cargar imagen).  
4. **Inscripciones:** listas “Disponibles / Inscritos” en **2 columnas** con scroll interno.

Si algún mockup no coincide (por ejemplo tres columnas fijas en inscripciones), anótalo como delta respecto a esta guía y se puede ajustar el HTML/CSS de forma acotada.
