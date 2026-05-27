# Dos bases de datos (portal + personas)

| Uso | Dominio / servidor | Base de datos | Tabla |
|-----|-------------------|---------------|--------|
| Portal (login, panel) | `federacionvenezolanadedomino.com` | `federaci1_fvdmasteradmin1` | `usuarios`, … |
| Personas (cédula) | `laestaciondeldominohoy.com` | `laestaci1_fvdadmin` | `dbo_persona` |

La app PHP vive en **un solo sitio** (`…/admin_fvd/`) pero abre cada BD según el `.env`.

---

## Caso habitual FVD: mismo usuario y misma contraseña, dos dominios

Si en **ambos** servidores MySQL el usuario y la clave son **idénticos** (solo cambian host y nombre de BD):

```env
# Credenciales compartidas (opcional; si omite FVD_PERSONA_DB_USERNAME/PASSWORD se reutilizan)
FVD_MYSQL_USERNAME=federaci1_lacancion
FVD_MYSQL_PASSWORD="Mimusica$26"

# --- Portal (siempre localhost en el servidor del portal) ---
FVD_DB_HOST=localhost
FVD_DB_DATABASE=federaci1_fvdmasteradmin1
FVD_DB_USERNAME=federaci1_lacancion
FVD_DB_PASSWORD="Mimusica$26"

# --- Personas (host = dominio donde está la otra BD) ---
FVD_PERSONA_DB_HOST=laestaciondeldominohoy.com
FVD_PERSONA_DB_DATABASE=laestaci1_fvdadmin
# Sin FVD_PERSONA_DB_USERNAME ni FVD_PERSONA_DB_PASSWORD → usa las mismas que el portal
FVD_PERSONA_DB_TABLE=dbo_persona
```

**No hace falta** repetir usuario distinto (`federaci1_soloyo`, `laestaci1_lacancion`, etc.) si en realidad es el mismo en los dos lados.

### Requisito en el hosting del dominio personas

En cPanel de **laestaciondeldominohoy.com**:

1. **Remote MySQL®** → autorizar la IP del servidor de `federacionvenezolanadedomino.com`.
2. El usuario MySQL debe existir en **esa** cuenta con acceso a `laestaci1_fvdadmin`.
3. Misma contraseña que en el `.env` del portal.

Si MySQL remoto no está permitido, copie `dbo_persona` a la misma cuenta cPanel del portal y use `FVD_PERSONA_DB_HOST=localhost` para ambas.

---

## Caso A — Misma cuenta cPanel (ambas BD en un solo servidor)

Ambas bases en la **misma** cuenta: host **`localhost`** para las dos. Un solo usuario MySQL debe estar **asignado a las dos bases** en cPanel.

```env
FVD_DB_HOST=localhost
FVD_DB_DATABASE=federaci1_fvdmasteradmin1
FVD_DB_USERNAME=federaci1_lacancion
FVD_DB_PASSWORD="su_clave"

FVD_PERSONA_DB_HOST=localhost
FVD_PERSONA_DB_DATABASE=laestaci1_fvdadmin
FVD_PERSONA_DB_TABLE=dbo_persona
```

(Omita `FVD_PERSONA_DB_USERNAME` / `FVD_PERSONA_DB_PASSWORD` para reutilizar las del portal.)

---

## Comprobar conexiones

```
https://federacionvenezolanadedomino.com/admin_fvd/api/health.php?key=SU_CLAVE
```

- Portal: `database_primary.status` → `connected`
- Personas (solo al afiliar o con flag): `?probe_persona=1` → `connected`

Con `FVD_APP_DEBUG=1` verá el error MySQL exacto si falla.

---

## Errores frecuentes

| Error | Causa |
|-------|--------|
| `Access denied` portal | Clave incorrecta en `.env` o usuario no asignado a `federaci1_fvdmasteradmin1` |
| `Access denied` personas con usuario del portal | Normal si el host es remoto: active **MySQL remoto** en el otro dominio |
| Usuario `laestaci1_*` en logs | Mezcla de prefijos en `.env`; use el **mismo** usuario real en ambos bloques |
| Login 503 | Arregle primero **solo** `FVD_DB_*` (portal) |

---

## Desactivar BD personas temporalmente

```env
FVD_PERSONA_DB_DISABLED=1
```
