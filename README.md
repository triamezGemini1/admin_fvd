# Admin FVD

Panel de administración de la Federación Venezolana de Damas (FVD): torneos, nómina (`movimiento_torneo`), finanzas por asociación, supervisión y reportes.

## Requisitos

- PHP 8.2+ (extensiones: `pdo_mysql`, `json`, `mbstring`)
- MySQL / MariaDB
- Apache (WAMP, XAMPP o similar)
- Node.js (solo si recompila estilos Tailwind)

## Instalación local (WAMP)

1. Clonar o copiar el proyecto en la carpeta de Apache, por ejemplo `C:\wamp64\www\admin_fvd`.
2. Crear la base de datos e importar migraciones desde `sql/migrations/`.
3. Copiar `.env.example` a `.env` y configurar credenciales de MySQL.
4. Abrir en el navegador: `http://localhost/admin_fvd/`.

La conexión PDO usa `Database.php` y variables de entorno `FVD_DB_*` (ver `.env.example`).

## Estructura principal

| Ruta | Descripción |
|------|-------------|
| `app/Modulos/` | Lógica por módulos (Atletas, Finanzas, Torneos, Informes, …) |
| `api/` | Endpoints JSON del panel |
| `src/js/` | Frontend del panel |
| `dist/assets/` | CSS compilado |
| `sql/migrations/` | Scripts SQL versionados |
| `tools/` | Utilidades CLI (carga nómina, diagnósticos) |

## Git

```bash
git add .
git commit -m "Initial commit: admin FVD"
```

### Publicar en GitHub

1. Crear un repositorio vacío en GitHub (sin README ni .gitignore).
2. En esta carpeta:

```bash
git remote add origin https://github.com/TU_USUARIO/admin-fvd.git
git branch -M main
git push -u origin main
```

Con GitHub CLI (tras `gh auth login`):

```bash
gh repo create admin-fvd --private --source=. --remote=origin --push
```

## Archivos que no se versionan

- `.env` — credenciales locales
- `ACCESO_ADMIN.txt` — notas de acceso de desarrollo
- `datosinfo/*.sql` — volcados de base de datos
- Contenido de `uploads/` (solo se guardan `.gitkeep`)
