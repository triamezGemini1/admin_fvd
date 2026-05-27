# Despliegue en producción — FVD Admin

**Dominio:** `https://federacionvenezolanadedomino.com`  
**Ruta en hosting:** `public_html/admin_fvd`  
**URL del portal:** `https://federacionvenezolanadedomino.com/admin_fvd/`

---

## 1. Requisitos del servidor

| Requisito | Mínimo |
|-----------|--------|
| PHP | 8.2+ (extensiones: `pdo_mysql`, `json`, `mbstring`, `fileinfo`, `session`) |
| MySQL / MariaDB | 10.x+ |
| Apache | `mod_rewrite` recomendado (`.htaccess` incluido) |
| HTTPS | Certificado SSL activo (sesión segura en producción) |

---

## 2. Subir archivos

1. Clonar o copiar el proyecto dentro de `public_html/admin_fvd/` (contenido del repo, no la carpeta padre duplicada).
2. **No** subir el archivo `.env` del repositorio local.
3. En el servidor, crear `.env` a partir de la plantilla:

```bash
cp .env.production.example .env
nano .env   # o editor del panel de hosting
```

4. Completar credenciales de MySQL (`FVD_DB_*` y, si aplica, `FVD_PERSONA_DB_*`).

---

## 3. Variables de entorno (`.env`)

| Variable | Valor en producción |
|----------|---------------------|
| `FVD_APP_ENV` | `production` |
| `FVD_BASE_PATH` | `/admin_fvd` |
| `FVD_PUBLIC_URL` | `https://federacionvenezolanadedomino.com/admin_fvd` |
| `FVD_DB_HOST` | Host MySQL del hosting (suele ser `localhost`) |
| `FVD_DB_DATABASE` | Nombre de la BD (ej. `fvdmasteradmin`) |
| `FVD_DB_USERNAME` / `FVD_DB_PASSWORD` | Usuario MySQL del panel |

La cookie de sesión quedará limitada a la ruta `/admin_fvd` para no interferir con otros sitios del mismo dominio.

---

## 4. Base de datos

1. Crear la base `fvdmasteradmin` (o la que defina el hosting).
2. Importar el esquema base si existe dump inicial.
3. Ejecutar migraciones **en orden** desde `sql/migrations/`:

```
001_usuarios_maestro_movimiento_torneo.sql
002_migrate_atletas_a_usuarios_y_movimiento_torneo.sql
… (todas hasta la última, p. ej. 014_…)
```

Desde phpMyAdmin o línea de comandos:

```bash
mysql -u USUARIO -p fvdmasteradmin < sql/migrations/011_finanza_gasto_torneo.sql
```

---

## 5. Permisos de carpetas (escritura)

El usuario de PHP (Apache/`www-data`) debe poder escribir en:

| Carpeta | Uso |
|---------|-----|
| `uploads/` | Logos, afiches, archivos de torneo |
| `uploads/torneos/` | Afiches e invitaciones |
| `uploads/asociaciones/` | Logos de asociaciones |
| `uploads/usuarios/` | Fotos de perfil |
| `dist/assets/img/uploads/` | Fotos y cédulas de afiliación |

Ejemplo (Linux, ajuste usuario/grupo según hosting):

```bash
chmod -R 775 uploads dist/assets/img/uploads
chown -R USUARIO_WEB:GRUPO_WEB uploads dist/assets/img/uploads
```

---

## 6. Apache (`.htaccess`)

El archivo `.htaccess` en la raíz de `admin_fvd`:

- Bloquea acceso a `.env` y carpetas `app/`, `sql/`, `tools/`, etc.
- Define `RewriteBase /admin_fvd/` (cámbielo si la app no está en esa subcarpeta).
- Descomente las líneas de **forzar HTTPS** cuando el SSL esté activo.

Si la app está en otra ruta, edite:

```apache
RewriteBase /su-ruta/
```

y en `.env`:

```
FVD_BASE_PATH=/su-ruta
```

---

## 7. Comprobar el despliegue

| URL | Qué debe ocurrir |
|-----|------------------|
| `https://federacionvenezolanadedomino.com/admin_fvd/` | Login (`index.php`) |
| `https://federacionvenezolanadedomino.com/admin_fvd/panel.html` | Panel (tras iniciar sesión) |
| `https://federacionvenezolanadedomino.com/admin_fvd/api/auth_context.php` | JSON (`logged: false` sin sesión) |
| `https://federacionvenezolanadedomino.com/admin_fvd/api/health.php` | Diagnóstico BD portal (`database_primary`) |
| `.../api/health.php?probe_persona=1` | Prueba BD personas remota (solo bajo demanda en app) |
| `.../api/atleta/registrar.php` | POST afiliación (endpoint canónico) |

### Diagnóstico «Servicio no disponible» en el login

Ese mensaje significa que **PHP no pudo conectar a MySQL**. Revise en este orden:

1. **¿Existe `.env`?** En `public_html/admin_fvd/.env` (copie desde `.env.production.example`).
2. **Credenciales del panel de hosting** (cPanel → MySQL®): usuario, contraseña y nombre de BD (a menudo con prefijo, ej. `usuario_fvdmasteradmin`).
3. **`FVD_DB_HOST=localhost`** (en hosting compartido suele fallar con `127.0.0.1`).
4. **Base importada** y migraciones ejecutadas.
5. Abra en el navegador:
   ```
   https://federacionvenezolanadedomino.com/admin_fvd/api/health.php
   ```
   Debe mostrar `"database_primary":{"status":"connected"}`. Si `"env_file":"missing"`, falta el `.env`.
   La BD personas **no** se prueba por defecto (`database_persona.status: not_probed`). Para validar la conexión remota:
   ```
   .../api/health.php?probe_persona=1
   ```
   Ver también `docs/DEPLOY_DOS_BASES_DATOS.md`.

Opcional en `.env`:
```
FVD_HEALTH_KEY=una_clave_secreta
FVD_APP_DEBUG=1
```
Luego: `api/health.php?key=una_clave_secreta` mostrará el error exacto de MySQL (solo mientras depura; ponga `FVD_APP_DEBUG=0` al terminar).

### «Credenciales inválidas» (pero `health.php` conecta la BD)

La base de datos responde, pero el usuario o la contraseña no coinciden con la tabla `usuarios`, o el `status` no permite acceso.

| Causa | Qué hacer |
|-------|-----------|
| Usuario incorrecto | Probar **username**, **email** o **cédula** exactos (sensible a espacios). |
| Contraseña distinta | Tras migración SQL 002 la clave inicial suele ser `MigracionFVD2026!` hasta cambiarla. Admin local: `php tools/bootstrap_portal_admin.php` → `admin_fvd_general` / `FvdAdmin2026!`. |
| `status` no es 1 ni 9 | En phpMyAdmin: `SELECT username, status, role FROM usuarios WHERE username='su_usuario';` — debe ser **1** (aprobado) o **9** (pendiente/habilitado). |
| `.env` apunta a otra BD | Si `FVD_DB_*` quedó sobrescrito con datos de la BD personas, el login busca usuarios en la BD equivocada. |

Diagnóstico con clave (en `.env`: `FVD_HEALTH_KEY=una_clave`):

```
https://federacionvenezolanadedomino.com/admin_fvd/api/health.php?key=una_clave&probe_user=admin_fvd_general
```

Muestra si el usuario existe, `status`, `role` y si el hash es bcrypt (sin revelar la contraseña).

Errores frecuentes:

- **503 / Servicio no disponible:** revisar `.env` y conexión MySQL (pasos anteriores).
- **Credenciales inválidas:** tabla anterior + `probe_user` en `health.php`.
- **Login OK pero se pierde la sesión:** verificar `FVD_BASE_PATH=/admin_fvd` y HTTPS (`secure` cookie).
- **No suben fotos:** permisos en `uploads/` y `dist/assets/img/uploads/`.

---

## 8. Actualizar versiones

```bash
cd public_html/admin_fvd
git pull origin main   # o la rama que use
# Revisar nuevas migraciones en sql/migrations/
# No sobrescribir .env ni uploads/
```

### Paquete ZIP desde Windows (local)

```powershell
cd C:\wamp64\www\admin_fvd
powershell -ExecutionPolicy Bypass -File scripts\package-deploy.ps1
```

Genera `deploy-fvd-admin.zip` en la raíz del proyecto. Extraiga en `public_html/admin_fvd/` y cree `.env` en el servidor.

---

## 9. Seguridad

- Mantener `FVD_APP_ENV=production` (oculta errores PHP al navegador).
- No exponer `.env`, `datosinfo/`, ni dumps SQL por HTTP.
- Usar contraseñas fuertes en MySQL y usuarios del portal.
- Activar HTTPS y descomentar la redirección en `.htaccess`.

---

## 10. Enlace público recomendado

Página de entrada para delegados y administración:

**https://federacionvenezolanadedomino.com/admin_fvd/**

(O `index.php` explícito: `.../admin_fvd/index.php`)
