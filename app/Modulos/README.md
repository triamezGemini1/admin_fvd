# Arquitectura modular FVD

Cada módulo exportable vive en `app/Modulos/{Nombre}/` con:

| Carpeta | Contenido |
|---------|-----------|
| `Modelos/` | Clases de dominio (`namespace Fvd\Modulos\{Nombre}\Modelos`) |
| `Controladores/` | Endpoints JSON (antes en `/api`) |
| `Vistas/` | Plantillas HTML del módulo |
| `rutas.php` | Registro de rutas HTTP del módulo |

## Módulos identificados (plan de migración)

| Módulo | Estado | Modelos / APIs principales |
|--------|--------|----------------------------|
| **Torneos** | Migrado | Torneo, AdminTorneo, InscripcionTorneo, TorneoMovimientoLock |
| **Atletas** | Migrado | Atleta, AfiliacionAtleta, MigracionAtletasAUsuarios, SyncMovimientoTorneoTridenteDesdeAtletas |
| **Asociaciones** | Migrado | AdminAsociacion, OrganizacionFvd, LogoUpload |
| **Finanzas** | Migrado | FinanzaFvd, DeudaAsociaciones, finanza_* |
| **Informes** | Migrado | InformeFvd, informe_consolidado, informe_recibo*, informe_actualizar_deudas |
| **Delegados** | Migrado | DelegadoMovimientoTorneo, DelegadoActividad, FvdSolicitudesDelegado, delegado_*, torneos_activos_list |
| **Supervision** | Migrado | SupervisionFvd, supervision_fvd |
| **Auth** | Migrado | Auth, AdminPolicy, AdminUsuario, login, auth_context, guards |

## Compatibilidad

Los archivos en `app/{Clase}.php` que fueron modularizados son **puentes** (`class_alias`) hacia las clases namespaced. No eliminar hasta completar la migración de referencias.

## Autoload

`app/Autoload.php` — prefijo `Fvd\` → carpeta `app/`.

## Rutas globales

`rutas.php` en la raíz del proyecto agrega las rutas de cada `app/Modulos/*/rutas.php`.
