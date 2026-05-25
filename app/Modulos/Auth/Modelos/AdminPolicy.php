<?php

declare(strict_types=1);

namespace Fvd\Modulos\Auth\Modelos;

/**
 * Permisos del panel CRUD (admin general vs delegado).
 * La organización FVD solo la gestiona admin general; el delegado administra su asociación y usuarios de su asociación.
 * Nº FVD y aprobación de estatus (9 → 1): solo administración general.
 */
class AdminPolicy
{
    /** Columnas de asociaciones que un delegado puede modificar (datos de contacto). */
    public const ASOCIACION_CAMPOS_DELEGADO = [
        'direccion', 'telefono', 'email', 'numreg', 'providencia', 'delegado', 'logo',
    ];

    public static function puedeAccederPanel(): bool
    {
        if (!Auth::check()) {
            return false;
        }
        $r = Auth::rol();

        return $r === 'admingral' || $r === 'delegado';
    }

    /**
     * @return array{
     *   organizacion: array{read:bool,write:bool},
     *   torneos: array{read:bool,write:bool,create:bool,delete:bool},
     *   asociaciones: array{read:bool,write:bool,create:bool,delete:bool},
     *   usuarios: array{read:bool,write:bool,create:bool,delete:bool,assign_numfvd:bool,approve_status:bool}
     * }
     */
    public static function capabilities(): array
    {
        if (!Auth::check()) {
            return self::allFalse();
        }
        if (Auth::rol() === 'admingral') {
            return [
                'organizacion' => ['read' => true, 'write' => true],
                'torneos' => ['read' => true, 'write' => true, 'create' => true, 'delete' => true],
                'asociaciones' => ['read' => true, 'write' => true, 'create' => true, 'delete' => true],
                'usuarios' => [
                    'read' => true, 'write' => true, 'create' => true, 'delete' => true,
                    'assign_numfvd' => true,
                    'approve_status' => true,
                ],
            ];
        }
        if (Auth::rol() === 'delegado') {
            return [
                'organizacion' => ['read' => false, 'write' => false],
                'torneos' => ['read' => true, 'write' => false, 'create' => false, 'delete' => false],
                'asociaciones' => ['read' => true, 'write' => true, 'create' => false, 'delete' => false],
                'usuarios' => [
                    'read' => true, 'write' => true, 'create' => true, 'delete' => false,
                    'assign_numfvd' => false,
                    'approve_status' => false,
                ],
            ];
        }

        return self::allFalse();
    }

    /** Inscripción al torneo activo (no implica asignar Nº FVD). */
    public static function puedeInscribirTorneo(): bool
    {
        if (!Auth::check()) {
            return false;
        }
        $r = Auth::rol();

        return $r === 'admingral' || $r === 'delegado';
    }

    public static function assertInscripcionTorneo(): void
    {
        if (!self::puedeInscribirTorneo()) {
            self::deny403();
        }
    }

    public static function puedeAsignarNumFvd(): bool
    {
        return Auth::check() && Auth::rol() === 'admingral';
    }

    /** Flujo aprobación acceso portal: estatus 9 pendiente → 1 activo. */
    public static function puedeAprobarEstatusUsuario(): bool
    {
        return Auth::check() && Auth::rol() === 'admingral';
    }

    /** Finanzas federativas (cargos, pagos, tasa BCV): solo administración general. */
    public static function puedeGestionarFinanzas(): bool
    {
        return Auth::check() && Auth::rol() === 'admingral';
    }

    public static function assertFinanzasGestion(): void
    {
        if (!self::puedeGestionarFinanzas()) {
            self::deny403();
        }
    }

    /** Lectura del informe consolidado nacional (todas las asociaciones). */
    public static function puedeLeerInformeConsolidadoGlobal(): bool
    {
        return Auth::check() && Auth::rol() === 'admingral' && self::puedeAccederPanel();
    }

    /**
     * Informe consolidado global (todas las asociaciones): administración general con acceso al panel.
     */
    public static function assertInformeConsolidadoGlobal(): void
    {
        if (!self::puedeLeerInformeConsolidadoGlobal()) {
            self::deny403();
        }
    }

    /**
     * Lectura de informe / estado financiero por una asociación concreta.
     * Administración general: igual que antes (gestión financiera).
     * Delegado: solo su propia asociación.
     */
    public static function assertLecturaInformeFinanzaPorAsociacion(int $asociacionId): void
    {
        if ($asociacionId < 1) {
            self::deny403();
        }
        if (Auth::rol() === 'admingral') {
            if (!self::puedeAccederPanel()) {
                self::deny403();
            }

            return;
        }
        if (Auth::rol() === 'delegado') {
            $m = Auth::asociacionId();
            if ($m === null || (int) $m !== $asociacionId) {
                self::deny403();
            }

            return;
        }
        self::deny403();
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private static function allFalse(): array
    {
        $z = ['read' => false, 'write' => false, 'create' => false, 'delete' => false];
        $u = array_merge($z, ['assign_numfvd' => false, 'approve_status' => false]);

        return [
            'organizacion' => ['read' => false, 'write' => false],
            'torneos' => $z,
            'asociaciones' => $z,
            'usuarios' => $u,
        ];
    }

    public static function assertOrganizacionRead(): void
    {
        if (!self::capabilities()['organizacion']['read']) {
            self::deny403();
        }
    }

    public static function assertOrganizacionWrite(): void
    {
        if (!self::capabilities()['organizacion']['write']) {
            self::deny403();
        }
    }

    public static function assertTorneoRead(): void
    {
        if (!self::capabilities()['torneos']['read']) {
            self::deny403();
        }
    }

    public static function assertTorneoWrite(): void
    {
        if (!self::capabilities()['torneos']['write']) {
            self::deny403();
        }
    }

    public static function assertTorneoCreate(): void
    {
        if (!self::capabilities()['torneos']['create']) {
            self::deny403();
        }
    }

    public static function assertTorneoDelete(): void
    {
        if (!self::capabilities()['torneos']['delete']) {
            self::deny403();
        }
    }

    public static function assertAsociacionRead(): void
    {
        if (!self::capabilities()['asociaciones']['read']) {
            self::deny403();
        }
    }

    public static function assertAsociacionWrite(): void
    {
        if (!self::capabilities()['asociaciones']['write']) {
            self::deny403();
        }
    }

    public static function assertAsociacionCreate(): void
    {
        if (!self::capabilities()['asociaciones']['create']) {
            self::deny403();
        }
    }

    public static function assertAsociacionDelete(): void
    {
        if (!self::capabilities()['asociaciones']['delete']) {
            self::deny403();
        }
    }

    public static function assertUsuarioRead(): void
    {
        if (!self::capabilities()['usuarios']['read']) {
            self::deny403();
        }
    }

    public static function assertUsuarioWrite(): void
    {
        if (!self::capabilities()['usuarios']['write']) {
            self::deny403();
        }
    }

    public static function assertUsuarioCreate(): void
    {
        if (!self::capabilities()['usuarios']['create']) {
            self::deny403();
        }
    }

    public static function assertUsuarioDelete(): void
    {
        if (!self::capabilities()['usuarios']['delete']) {
            self::deny403();
        }
    }

    /** Supervisión FVD (`movimiento_torneo`, flags 1): solo administración general. */
    public static function assertSupervisionAdmingral(): void
    {
        if (!Auth::check() || Auth::rol() !== 'admingral') {
            self::deny403();
        }
    }

    /** @return never */
    private static function deny403(): void
    {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => 'No tiene permiso para esta acción.']);
        exit;
    }
}
