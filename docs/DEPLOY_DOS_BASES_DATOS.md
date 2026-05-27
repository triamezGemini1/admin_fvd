# Dos bases de datos en el mismo hosting (dominios distintos)

Escenario típico FVD:

| Uso | Dominio | Base de datos | Tabla principal |
|-----|---------|---------------|-----------------|
| Portal admin FVD (login, torneos, finanzas) | `federacionvenezolanadedomino.com` | `federaci1_fvdmasteradmin1` | `usuarios`, `movimiento_torneo`, … |
| Personas / cédula (afiliación) | `laestaciondeldominohoy.com` | `laestaci1_fvdadmin` | `dbo_persona` |

La aplicación vive en **un solo dominio** (`…/admin_fvd/`) pero puede leer **las dos BDs** según el `.env`.

---

## Caso A — Misma cuenta cPanel (lo más habitual)

Todas las BDs del mismo usuario de hosting se conectan con **`localhost`** desde PHP, aunque el “sitio web” de cada BD sea otro dominio.

```env
# BD principal (portal)
FVD_DB_HOST=localhost
FVD_DB_DATABASE=federaci1_fvdmasteradmin1
FVD_DB_USERNAME=federaci1_lacancion
FVD_DB_PASSWORD="su_clave_principal"

# BD personas (NO repita FVD_DB_* aquí)
FVD_PERSONA_DB_HOST=localhost
FVD_PERSONA_DB_DATABASE=laestaci1_fvdadmin
FVD_PERSONA_DB_USERNAME=federaci1_soloyo
FVD_PERSONA_DB_PASSWORD="su_clave_personas"
FVD_PERSONA_DB_TABLE=dbo_persona
```

En cPanel → **Bases de datos MySQL**:

1. Cada BD tiene su **usuario** (`federaci1_lacancion`, `federaci1_soloyo`).
2. Cada usuario debe estar **asignado a su BD** con todos los privilegios.
3. El usuario de la BD principal **no** accede a la BD personas salvo que lo agregue manualmente (no es necesario si usa `federaci1_soloyo` en `FVD_PERSONA_*`).

---

## Caso B — Cuentas cPanel distintas o servidor distinto

La BD personas está en **otro servidor** o cuenta. Entonces:

```env
FVD_PERSONA_DB_HOST=laestaciondeldominohoy.com
# o la IP que indique el panel de laestaciondeldominohoy.com
```

En el cPanel de **laestaciondeldominohoy.com**:

1. **MySQL remoto** → permitir el host/IP del servidor de `federacionvenezolanadedomino.com`.
2. Usuario `federaci1_soloyo` con acceso remoto a `laestaci1_fvdadmin`.

Si el hosting no permite MySQL remoto, opciones:

- Mover/copiar `dbo_persona` a la misma cuenta que el portal, o
- Exponer un API en laestaciondeldominohoy.com (no implementado por defecto en este proyecto).

---

## Comprobar ambas conexiones

Abra (con sesión de admin o tras subir `health.php`):

```
https://federacionvenezolanadedomino.com/admin_fvd/api/health.php
```

Respuesta esperada:

```json
"database_primary": { "status": "connected", ... },
"database_persona": { "status": "connected", "table_probe": { "ok": true } }
```

Si `database_persona` falla pero `database_primary` conecta:

- Revise prefijos `FVD_PERSONA_DB_*` (no `FVD_DB_*`).
- Revise usuario/contraseña de la BD personas en cPanel.
- Pruebe `FVD_PERSONA_DB_HOST=localhost` vs host remoto según caso A o B.

Con diagnóstico detallado en `.env`:

```
FVD_APP_DEBUG=1
```

---

## Desactivar BD personas temporalmente

Si solo necesita probar login/panel:

```env
FVD_PERSONA_DB_DISABLED=1
```

La afiliación por cédula externa quedará desactivada hasta configurar la segunda BD.

---

## Errores frecuentes

| Error | Causa |
|-------|--------|
| Login «Servicio no disponible» | Falla **BD principal** (`FVD_DB_*`) |
| Afiliación sin datos externos | Falla **BD personas** (`FVD_PERSONA_*`) |
| `Access denied` | Usuario MySQL no asignado a esa BD |
| Sobrescribir `FVD_DB_*` al final del `.env` | Segunda BD mal etiquetada; use solo `FVD_PERSONA_DB_*` |
