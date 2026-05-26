<?php

declare(strict_types=1);

namespace Fvd\Modulos\Torneos\Modelos;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Panel de inscripciones: torneo activo, disponibles vs inscritos en `movimiento_torneo`, alta de inscripción.
 *
 * Procedimiento de listados (fuente única `movimiento_torneo`):
 * - Inscritos: `movimiento_torneo` con `torneo_id`, `asociacion_id` e `inscripcion = 1`.
 * - Disponibles: afiliados de la asociación que no tienen esa inscripción activa en el torneo.
 */
class InscripcionTorneo
{
    private const T_U = 'usuarios';

    private const T_M = 'movimiento_torneo';

    private const T_T = 'torneosact';

    /** @var bool|null */
    private static $usuariosTieneColumnaEntidad = null;

    /** @var array<string, bool> */
    private static $movimientoColumnas = [];

    public static function movimientoTieneColumna(\PDO $pdo, string $columna): bool
    {
        $col = preg_replace('/[^a-z_]/i', '', $columna) ?: '';
        if ($col === '') {
            return false;
        }
        if (isset(self::$movimientoColumnas[$col])) {
            return self::$movimientoColumnas[$col];
        }
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . self::T_M . ' LIKE ' . $pdo->quote($col));
        self::$movimientoColumnas[$col] = $stmt !== false && $stmt->fetch(\PDO::FETCH_ASSOC) !== false;

        return self::$movimientoColumnas[$col];
    }

    /**
     * @param array<string, mixed> $u
     */
    public static function cedulaParaMovimiento(array $u, int $userId): string
    {
        $cedula = trim((string) ($u['cedula'] ?? ''));
        if ($cedula !== '') {
            return $cedula;
        }
        $nf = (int) ($u['numfvd'] ?? 0);
        if ($nf > 0) {
            return (string) $nf;
        }

        return 'U' . $userId;
    }

    /**
     * En este esquema FVD el «código entidad» del afiliado es `usuarios.asociacion_id`.
     * Si existiera columna legacy `entidad`, se incluye en el filtro.
     *
     * @return array{sql: string, needs_entidad_bind: bool}
     */
    public static function filtroUsuarioAsociacionActivaSql(\PDO $pdo, string $aliasUsuario = 'u'): array
    {
        $a = preg_replace('/[^a-z_]/i', '', $aliasUsuario) ?: 'u';
        if (self::usuariosTieneColumnaEntidad($pdo)) {
            return [
                'sql' => " AND ({$a}.asociacion_id = :aid OR {$a}.entidad = :aid_ent) ",
                'needs_entidad_bind' => true,
            ];
        }

        return [
            'sql' => " AND {$a}.asociacion_id = :aid ",
            'needs_entidad_bind' => false,
        ];
    }

    /**
     * @param \PDO|null $pdo
     */
    public static function usuariosTieneColumnaEntidad(?\PDO $pdo = null): bool
    {
        if (self::$usuariosTieneColumnaEntidad !== null) {
            return self::$usuariosTieneColumnaEntidad;
        }
        if ($pdo === null) {
            return false;
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM " . self::T_U . " LIKE 'entidad'");
        self::$usuariosTieneColumnaEntidad = $stmt !== false && $stmt->fetch(\PDO::FETCH_ASSOC) !== false;

        return self::$usuariosTieneColumnaEntidad;
    }

    /**
     * @param \PDOStatement $stmt
     */
    public static function bindFiltroUsuarioAsociacionActiva(\PDOStatement $stmt, int $asociacionId, array $filtro): void
    {
        $stmt->bindValue(':aid', $asociacionId, \PDO::PARAM_INT);
        if ($filtro['needs_entidad_bind'] ?? false) {
            $stmt->bindValue(':aid_ent', $asociacionId, \PDO::PARAM_INT);
        }
    }

    /**
     * Ámbito territorial de una fila `movimiento_torneo` + usuario para la asociación activa.
     * Incluye `usuarios.entidad` cuando existe (código legacy alineado con disponibles).
     */
    public static function sqlAmbitoMovimientoAsociacionSql(\PDO $pdo, string $aliasMov = 'm', string $aliasUsuario = 'u'): string
    {
        $m = preg_replace('/[^a-z_]/i', '', $aliasMov) ?: 'm';
        $u = preg_replace('/[^a-z_]/i', '', $aliasUsuario) ?: 'u';
        $sql = " AND ({$m}.asociacion_id = :aid_mov OR {$u}.asociacion_id = :aid_usu";
        if (self::usuariosTieneColumnaEntidad($pdo)) {
            $sql .= " OR {$u}.entidad = :aid_ent";
        }

        return $sql . ') ';
    }

    public static function bindAmbitoMovimientoAsociacion(\PDOStatement $stmt, int $asociacionId, \PDO $pdo): void
    {
        $stmt->bindValue(':aid_mov', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':aid_usu', $asociacionId, \PDO::PARAM_INT);
        if (self::usuariosTieneColumnaEntidad($pdo)) {
            $stmt->bindValue(':aid_ent', $asociacionId, \PDO::PARAM_INT);
        }
    }

    /** Condición SQL: inscripción activa en movimiento_torneo (acepta 1, "1", etc.). */
    public static function sqlInscripcionActiva(string $aliasMov = 'm'): string
    {
        $m = preg_replace('/[^a-z_]/i', '', $aliasMov) ?: 'm';

        return ' CAST(' . $m . '.inscripcion AS UNSIGNED) = 1 ';
    }

    /**
     * Excluye usuarios con inscripción activa en el torneo (`movimiento_torneo.inscripcion = 1`).
     */
    public static function sqlExcluirUsuariosInscritosEnTorneoSql(string $aliasUsuario = 'u', string $aliasMovIns = 'mi'): string
    {
        $u = preg_replace('/[^a-z_]/i', '', $aliasUsuario) ?: 'u';
        $mi = preg_replace('/[^a-z_]/i', '', $aliasMovIns) ?: 'mi';

        return ' AND NOT EXISTS (
            SELECT 1 FROM ' . self::T_M . ' ' . $mi . '
            WHERE ' . $mi . '.id_usuario = ' . $u . '.id
            AND ' . $mi . '.torneo_id = :tid_no_insc
            AND ' . self::sqlInscripcionActiva($mi) . '
        ) ';
    }

    /**
     * @return array<string, mixed>|null fila torneo (PK `torneo`)
     */
    public static function torneoActivo(\PDO $pdo): ?array
    {
        $sql = 'SELECT * FROM ' . self::T_T . ' WHERE finalizado_en IS NULL ORDER BY fechator DESC, torneo DESC LIMIT 1';
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return 'individual'|'parejas'|'equipos'|null
     */
    public static function modalidadDesdeTorneo(?array $torneo): ?string
    {
        if ($torneo === null) {
            return null;
        }
        $clase = isset($torneo['clase']) ? (int) $torneo['clase'] : 0;
        if ($clase === 1) {
            return 'individual';
        }
        if ($clase === 2) {
            return 'parejas';
        }
        if ($clase === 3) {
            return 'equipos';
        }

        return null;
    }

    /**
     * Integrantes por ingreso (mistorneos: individual=1, parejas=max(2,pareclub), equipos=max(2,pareclub) típ. 4).
     */
    public static function jugadoresRequeridosPorTorneo(array $torneo): int
    {
        $modalidad = self::modalidadDesdeTorneo($torneo);
        $pareclub = max(0, (int) ($torneo['pareclub'] ?? 0));
        if ($modalidad === 'individual') {
            return 1;
        }
        if ($modalidad === 'parejas') {
            return max(2, $pareclub > 0 ? $pareclub : 2);
        }
        if ($modalidad === 'equipos') {
            return max(2, $pareclub > 0 ? $pareclub : 4);
        }

        return 0;
    }

    /**
     * Normaliza nombre de pareja/equipo para `movimiento_torneo.grupo_nombre`.
     */
    public static function normalizarGrupoNombreInscripcion(string $modalidad, ?string $grupoNombre, int $grupoId): ?string
    {
        if ($modalidad === 'individual') {
            return null;
        }
        $gn = $grupoNombre !== null ? trim($grupoNombre) : '';
        if ($gn !== '') {
            return $gn;
        }
        if ($modalidad === 'parejas') {
            return $grupoId > 0 ? 'Pareja ' . $grupoId : 'Pareja';
        }
        if ($modalidad === 'equipos') {
            throw new InvalidArgumentException('Indique el nombre del equipo.');
        }

        return null;
    }

    /**
     * Filtro sexo según `torneosact.tipo`: 1=M, 2=F, 3=Mixto (cualquiera).
     *
     * @return array{sql: string, params: array<string, int>}
     */
    public static function filtroSexoSql(int $tipoTorneo): array
    {
        if ($tipoTorneo === 1) {
            return ['sql' => ' AND u.sexo = :sx ', 'params' => [':sx' => 1]];
        }
        if ($tipoTorneo === 2) {
            return ['sql' => ' AND u.sexo = :sx ', 'params' => [':sx' => 2]];
        }

        return ['sql' => ' AND u.sexo IN (1, 2) ', 'params' => []];
    }

    /**
     * `torneosact.tipo`: 1=M, 2=F; otro o null → mixto (sin restricción M/F más allá de IN 1,2).
     */
    public static function normalizarTipoTorneo(mixed $tipo): int
    {
        $t = (int) $tipo;

        return ($t === 1 || $t === 2) ? $t : 3;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * Orden fijo de secciones en el listado/reporte de disponibles.
     *
     * @return array<string, array{label: string, orden: int}>
     */
    public static function mapaOrdenGruposDisponibles(): array
    {
        return [
            'listo' => ['label' => 'Listos para inscribir', 'orden' => 1],
            'pendiente_carnet' => ['label' => 'Pendiente carnet', 'orden' => 2],
            'pendiente_anualidad' => ['label' => 'Pendiente anualidad', 'orden' => 3],
            'pendiente_afiliacion' => ['label' => 'Pendiente afiliación', 'orden' => 4],
            'sin_movimiento' => ['label' => 'Sin movimiento en este torneo', 'orden' => 5],
        ];
    }

    /**
     * Plantilla de grupos vacíos (siempre desplegar las 5 secciones por estatus).
     *
     * @return list<array{clave: string, label: string, orden: int, items: list<array<string, mixed>>}>
     */
    public static function plantillaGruposDisponiblesVacios(): array
    {
        $grupos = [];
        foreach (self::mapaOrdenGruposDisponibles() as $clave => $meta) {
            $grupos[] = [
                'clave' => $clave,
                'label' => $meta['label'],
                'orden' => $meta['orden'],
                'items' => [],
            ];
        }
        usort($grupos, static fn ($a, $b) => $a['orden'] <=> $b['orden']);

        return $grupos;
    }

    public static function listarDisponibles(\PDO $pdo, int $torneoId, int $asociacionId, int $tipoTorneo): array
    {
        $fa = self::filtroUsuarioAsociacionActivaSql($pdo, 'u');
        $sql = 'SELECT u.id, u.cedula, u.nombre, u.numfvd, u.sexo, u.fechnac, u.email, u.celular
            FROM ' . self::T_U . ' u
            WHERE u.status IN (1, 9)
            ' . $fa['sql'] . '
            ' . self::sqlExcluirUsuariosInscritosEnTorneoSql('u', 'mi') . '
            ORDER BY u.nombre ASC';
        $stmt = $pdo->prepare($sql);
        self::bindFiltroUsuarioAsociacionActiva($stmt, $asociacionId, $fa);
        $stmt->bindValue(':tid_no_insc', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * Afiliados de la asociación sin `movimiento_torneo.inscripcion = 1` en el torneo activo.
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   grupos: list<array{clave: string, label: string, orden: int, items: list<array<string, mixed>>}>
     * }
     */
    public static function listarDisponiblesAgrupadosPorEstatus(
        \PDO $pdo,
        int $torneoId,
        int $asociacionId,
        int $tipoTorneo
    ): array {
        $fa = self::filtroUsuarioAsociacionActivaSql($pdo, 'u');
        $sql = 'SELECT u.id, u.cedula, u.nombre, u.numfvd, u.sexo, u.fechnac, u.email, u.celular,
                COALESCE(ao.nombre, \'\') AS asociacion_nombre,
                m.id AS mov_id, m.afiliacion, m.anualidad, m.carnet, m.traspaso, m.inscripcion,
                m.estatus AS mov_estatus
            FROM ' . self::T_U . ' u
            LEFT JOIN asociaciones ao ON ao.id = u.asociacion_id
            LEFT JOIN ' . self::T_M . ' m ON m.id_usuario = u.id AND m.torneo_id = :tid
            WHERE u.status IN (1, 9)
            ' . $fa['sql'] . '
            ' . self::sqlExcluirUsuariosInscritosEnTorneoSql('u', 'mi') . '
            ORDER BY u.nombre ASC';
        $stmt = $pdo->prepare($sql);
        self::bindFiltroUsuarioAsociacionActiva($stmt, $asociacionId, $fa);
        $stmt->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $stmt->bindValue(':tid_no_insc', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $rows = $rows === false ? [] : $rows;

        $ordenGrupos = self::mapaOrdenGruposDisponibles();
        $buckets = [];
        foreach (array_keys($ordenGrupos) as $clave) {
            $buckets[$clave] = [];
        }

        foreach ($rows as $row) {
            $grupo = self::grupoEstatusDisponible($row);
            $clave = $grupo['clave'];
            $row['estatus_grupo'] = $clave;
            $row['estatus_label'] = $grupo['label'];
            $row['puede_inscribir'] = true;
            if (!isset($buckets[$clave])) {
                $buckets[$clave] = [];
            }
            $buckets[$clave][] = $row;
        }

        foreach ($buckets as $clave => $lista) {
            usort($lista, static function (array $a, array $b): int {
                return strcasecmp((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? ''));
            });
            $buckets[$clave] = $lista;
        }

        $grupos = [];
        foreach ($ordenGrupos as $clave => $meta) {
            $grupos[] = [
                'clave' => $clave,
                'label' => $meta['label'],
                'orden' => $meta['orden'],
                'items' => $buckets[$clave] ?? [],
            ];
        }
        usort($grupos, static fn ($a, $b) => $a['orden'] <=> $b['orden']);

        $items = [];
        foreach ($grupos as $g) {
            foreach ($g['items'] as $it) {
                $items[] = $it;
            }
        }

        return ['items' => $items, 'grupos' => $grupos];
    }

    /**
     * @param array<string, mixed> $row fila con LEFT JOIN movimiento (mov_id puede ser null)
     *
     * @return array{clave: string, label: string}
     */
    public static function grupoEstatusDisponible(array $row): array
    {
        if (!isset($row['mov_id']) || $row['mov_id'] === null || (int) $row['mov_id'] < 1) {
            return ['clave' => 'sin_movimiento', 'label' => 'Sin movimiento en este torneo'];
        }
        if (!self::indicadorVigente('afiliacion', (int) ($row['afiliacion'] ?? 0))) {
            return ['clave' => 'pendiente_afiliacion', 'label' => 'Pendiente afiliación'];
        }
        if (!self::indicadorVigente('anualidad', (int) ($row['anualidad'] ?? 0))) {
            return ['clave' => 'pendiente_anualidad', 'label' => 'Pendiente anualidad'];
        }
        if (!self::indicadorVigente('carnet', (int) ($row['carnet'] ?? 0))) {
            return ['clave' => 'pendiente_carnet', 'label' => 'Pendiente carnet'];
        }

        return ['clave' => 'listo', 'label' => 'Listo para inscribir'];
    }

    public static function indicadorVigente(string $campo, int $valor): bool
    {
        if ($campo === 'afiliacion' || $campo === 'anualidad') {
            return in_array($valor, [1, 5], true);
        }
        if ($campo === 'carnet') {
            return in_array($valor, [1, 20], true);
        }

        return $valor >= 1;
    }

    /**
     * Inscritos: `movimiento_torneo` donde torneo = activo, asociacion = activa, inscripcion = 1.
     *
     * @return list<array<string, mixed>>
     */
    public static function listarInscritos(\PDO $pdo, int $torneoId, int $asociacionId): array
    {
        if ($torneoId < 1 || $asociacionId < 1) {
            return [];
        }

        $colGrupoId = self::movimientoTieneColumna($pdo, 'grupo_id') ? 'm.grupo_id' : 'NULL AS grupo_id';
        $sql = 'SELECT m.id AS mov_id, m.id_usuario, m.cedula, m.numfvd, m.sexo, m.inscripcion, m.torneo_id,
                m.asociacion_id, m.estatus AS mov_estatus, m.grupo_nombre, ' . $colGrupoId . ',
                m.afiliacion, m.anualidad, m.carnet, m.traspaso, m.posrnk, m.movimiento,
                u.nombre AS usuario_nombre, u.celular, u.fechnac, u.email,
                u.urlimgfoto, u.urlimgcedula
            FROM ' . self::T_M . ' m
            LEFT JOIN ' . self::T_U . ' u ON u.id = m.id_usuario
            WHERE m.torneo_id = :tid
            AND m.asociacion_id = :aid
            AND ' . self::sqlInscripcionActiva('m') . '
            ORDER BY COALESCE(NULLIF(TRIM(u.nombre), \'\'), m.cedula) ASC, m.numfvd ASC, m.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $stmt->bindValue(':aid', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * Categoría por edad (misma regla que portal mistorneos inscripciones).
     *
     * @return array{valor: int, nombre: string}
     */
    public static function calcularCategoria(?string $fechnac): array
    {
        if ($fechnac === null || trim($fechnac) === '') {
            return ['valor' => 0, 'nombre' => ''];
        }
        try {
            $nac = new \DateTimeImmutable(trim($fechnac));
            $hoy = new \DateTimeImmutable('today');
            $edad = (int) $nac->diff($hoy)->y;
        } catch (\Throwable $e) {
            unset($e);
            return ['valor' => 0, 'nombre' => ''];
        }
        if ($edad < 19) {
            return ['valor' => 1, 'nombre' => 'Junior (< 19 años)'];
        }
        if ($edad > 60) {
            return ['valor' => 3, 'nombre' => 'Master (> 60 años)'];
        }

        return ['valor' => 2, 'nombre' => 'Libre (19-60 años)'];
    }

    /**
     * @param list<array<string, mixed>> $items filas con clave `sexo` (1/2)
     *
     * @return array{total: int, hombres: int, mujeres: int}
     */
    public static function estadisticasDesdeFilas(array $items): array
    {
        $h = 0;
        $m = 0;
        foreach ($items as $row) {
            $sx = (int) ($row['sexo'] ?? 0);
            if ($sx === 1) {
                $h++;
            } elseif ($sx === 2) {
                $m++;
            }
        }

        return ['total' => count($items), 'hombres' => $h, 'mujeres' => $m];
    }

    /**
     * Enriquece filas de inscritos con categoría calculada.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function enriquecerFilasInscritos(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            $nf = (int) ($row['numfvd'] ?? 0);
            $uid = (int) ($row['id_usuario'] ?? 0);
            $ced = trim((string) ($row['cedula'] ?? ''));
            $nom = trim((string) ($row['usuario_nombre'] ?? ''));
            if ($nom === '') {
                if ($nf > 0) {
                    $nom = 'Nº FVD ' . $nf;
                } elseif ($ced !== '') {
                    $nom = $ced;
                } elseif ($uid > 0) {
                    $nom = 'Usuario #' . $uid;
                } else {
                    $nom = '—';
                }
            }
            $row['nombre_usuario'] = $nom;
            $row['celular'] = trim((string) ($row['celular'] ?? ''));
            $row['email'] = trim((string) ($row['email'] ?? ''));
            $row['foto_url'] = self::rutaImagenWeb(isset($row['urlimgfoto']) ? (string) $row['urlimgfoto'] : null);
            $row['cedula_img_url'] = self::rutaImagenWeb(isset($row['urlimgcedula']) ? (string) $row['urlimgcedula'] : null);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Ruta web relativa al proyecto para imágenes de usuario (foto / cédula).
     */
    public static function rutaImagenWeb(?string $path): ?string
    {
        $p = trim((string) $path);
        if ($p === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $p)) {
            return $p;
        }

        return ltrim(str_replace('\\', '/', $p), '/');
    }

    /**
     * Búsqueda por cédula para el formulario de inscripción (usuarios FVD, no tabla persona externa).
     *
     * @return array{
     *   encontrado: bool,
     *   ya_inscrito?: bool,
     *   error?: string,
     *   usuario?: array<string, mixed>,
     *   categ?: array{valor: int, nombre: string}
     * }
     */
    public static function buscarUsuarioInscripcionPorCedula(
        \PDO $pdo,
        string $cedula,
        int $torneoId,
        int $asociacionContextoId
    ): array {
        $variantes = self::variantesCedulaBusqueda($cedula);
        if ($variantes === []) {
            return ['encontrado' => false, 'error' => 'Indique una cédula válida.'];
        }

        foreach ($variantes as $v) {
            $stmt = $pdo->prepare(
                'SELECT m.id, m.inscripcion FROM ' . self::T_M . ' m
                WHERE m.torneo_id = :t AND m.cedula = :c AND m.inscripcion = 1 LIMIT 1'
            );
            $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
            $stmt->bindValue(':c', $v, \PDO::PARAM_STR);
            $stmt->execute();
            if ($stmt->fetch(\PDO::FETCH_ASSOC) !== false) {
                return [
                    'encontrado' => false,
                    'ya_inscrito' => true,
                    'error' => 'El jugador con cédula ' . $v . ' ya está inscrito en este torneo.',
                ];
            }
        }

        $u = null;
        foreach ($variantes as $v) {
            $u = self::buscarUnUsuarioPreferenciaAsociacion($pdo, $v, $asociacionContextoId);
            if ($u !== null) {
                break;
            }
        }
        if ($u === null) {
            return ['encontrado' => false, 'error' => 'No se encontró atleta con esa cédula en el sistema FVD.'];
        }

        $uid = (int) $u['id'];
        $stmt = $pdo->prepare(
            'SELECT inscripcion FROM ' . self::T_M . ' WHERE id_usuario = :u AND torneo_id = :t LIMIT 1'
        );
        $stmt->bindValue(':u', $uid, \PDO::PARAM_INT);
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $mov = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($mov !== false && (int) ($mov['inscripcion'] ?? 0) === 1) {
            return [
                'encontrado' => false,
                'ya_inscrito' => true,
                'error' => 'El atleta ya está inscrito en este torneo.',
            ];
        }

        $full = self::obtenerUsuario($pdo, $uid);
        if ($full === null) {
            return ['encontrado' => false, 'error' => 'Usuario no encontrado.'];
        }

        $cat = self::calcularCategoria(isset($full['fechnac']) ? (string) $full['fechnac'] : null);

        return [
            'encontrado' => true,
            'usuario' => [
                'id' => $uid,
                'cedula' => (string) ($full['cedula'] ?? ''),
                'nombre' => (string) ($full['nombre'] ?? ''),
                'sexo' => (int) ($full['sexo'] ?? 0),
                'fechnac' => $full['fechnac'] ?? null,
                'celular' => $full['celular'] ?? null,
                'numfvd' => (int) ($full['numfvd'] ?? 0),
            ],
            'categ' => $cat,
        ];
    }

    /**
     * @param list<string> $busquedas cédula, nombre o email por línea
     * @return list<int> ids usuario
     */
    public static function resolverUsuariosPorLineas(\PDO $pdo, array $busquedas, int $asociacionId): array
    {
        $ids = [];
        foreach ($busquedas as $raw) {
            $q = trim((string) $raw);
            if ($q === '') {
                throw new InvalidArgumentException('Hay líneas de búsqueda vacías.');
            }
            $u = self::buscarUnUsuarioPreferenciaAsociacion($pdo, $q, $asociacionId);
            if ($u === null) {
                throw new InvalidArgumentException('No se encontró usuario para: ' . $q);
            }
            $id = (int) $u['id'];
            if (in_array($id, $ids, true)) {
                throw new InvalidArgumentException('No repita el mismo atleta: ' . $q);
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Búsqueda predictiva (mín. 3 caracteres en API): usuarios de la asociación.
     *
     * @return list<array{id: int, cedula: string, nombre: string, numfvd: int, sexo: int, email: string}>
     */
    public static function buscarUsuariosParaAutocomplete(\PDO $pdo, string $termino, ?int $asociacionIdFiltro, int $limite = 15, ?int $torneoIdSoloElegibles = null): array
    {
        $termino = trim($termino);
        if ($termino === '' || $limite < 1) {
            return [];
        }
        $limite = min($limite, 30);
        $eleg = '';
        if ($torneoIdSoloElegibles !== null && $torneoIdSoloElegibles > 0) {
            $eleg = self::sqlExcluirUsuariosInscritosEnTorneoSql('u', 'meleg');
        }
        $fa = $asociacionIdFiltro !== null && $asociacionIdFiltro > 0
            ? self::filtroUsuarioAsociacionActivaSql($pdo, 'u')
            : ['sql' => '', 'needs_entidad_bind' => false];
        $sql = 'SELECT u.id, u.cedula, u.nombre, u.numfvd, u.sexo, u.email
            FROM ' . self::T_U . " u
            WHERE u.status IN (1, 9)
            {$fa['sql']}
            AND (
                u.cedula LIKE CONCAT('%', :qb, '%')
                OR u.email LIKE CONCAT('%', :qb2, '%')
                OR u.nombre LIKE CONCAT('%', :qb3, '%')
            )
            {$eleg}
            ORDER BY u.nombre ASC
            LIMIT " . (int) $limite;
        $stmt = $pdo->prepare($sql);
        if ($asociacionIdFiltro !== null && $asociacionIdFiltro > 0) {
            self::bindFiltroUsuarioAsociacionActiva($stmt, $asociacionIdFiltro, $fa);
        }
        $stmt->bindValue(':qb', $termino, \PDO::PARAM_STR);
        $stmt->bindValue(':qb2', $termino, \PDO::PARAM_STR);
        $stmt->bindValue(':qb3', $termino, \PDO::PARAM_STR);
        if ($torneoIdSoloElegibles !== null && $torneoIdSoloElegibles > 0) {
            $stmt->bindValue(':tid_no_insc', $torneoIdSoloElegibles, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * @param list<int> $usuarioIds
     * @return array{had_traspaso: bool}
     */
    public static function inscribir(
        PDO $pdo,
        int $torneoId,
        int $asociacionId,
        string $modalidad,
        array $usuarioIds,
        ?string $grupoNombre
    ): array {
        $torneo = self::obtenerTorneoPorId($pdo, $torneoId);
        if ($torneo === null) {
            throw new InvalidArgumentException('Torneo no válido.');
        }
        if (self::modalidadDesdeTorneo($torneo) !== $modalidad) {
            throw new InvalidArgumentException('La modalidad no coincide con el torneo activo.');
        }
        $requeridos = self::jugadoresRequeridosPorTorneo($torneo);
        $n = count($usuarioIds);
        if ($requeridos < 1 || $n !== $requeridos) {
            throw new InvalidArgumentException(
                'Debe inscribir exactamente ' . $requeridos . ' atleta(s) por ingreso para esta modalidad.'
            );
        }
        TorneoMovimientoLock::assertInscripcionPermitida($pdo, $torneoId);
        $hadTraspaso = false;
        $pdo->beginTransaction();
        try {
            $grupoId = null;
            if ($modalidad !== 'individual' && self::movimientoTieneColumna($pdo, 'grupo_id')) {
                $grupoId = self::allocarGrupoId($pdo, $torneoId);
            }
            $grupoNombreFinal = self::normalizarGrupoNombreInscripcion(
                $modalidad,
                $grupoNombre,
                $grupoId ?? 0
            );
            foreach ($usuarioIds as $uid) {
                $u = self::obtenerUsuario($pdo, (int) $uid);
                if ($u === null) {
                    throw new InvalidArgumentException('Usuario no encontrado.');
                }
                if (self::upsertInscripcion($pdo, (int) $uid, $u, $torneoId, $asociacionId, $grupoNombreFinal, $grupoId)) {
                    $hadTraspaso = true;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['had_traspaso' => $hadTraspaso];
    }

    /**
     * Baja lógica de inscripción: `movimiento_torneo.inscripcion = 0`.
     *
     * @return array{movimiento_id: int, id_usuario: int, cedula: string, torneo_id: int}
     */
    public static function retirar(
        PDO $pdo,
        int $torneoId,
        int $asociacionContextoId,
        ?int $movimientoId,
        ?string $cedula
    ): array {
        if ($torneoId < 1) {
            throw new InvalidArgumentException('torneo_id inválido.');
        }
        $movimientoId = $movimientoId !== null && $movimientoId > 0 ? $movimientoId : null;
        $cedula = $cedula !== null ? trim($cedula) : '';
        if ($movimientoId === null && $cedula === '') {
            throw new InvalidArgumentException('Indique id_inscripcion o cedula.');
        }

        TorneoMovimientoLock::assertInscripcionPermitida($pdo, $torneoId);

        $row = self::resolverMovimientoInscrito($pdo, $torneoId, $asociacionContextoId, $movimientoId, $cedula);
        if ($row === null) {
            throw new InvalidArgumentException('Inscripción no encontrada, no activa o sin permiso para esta asociación.');
        }

        $mid = (int) $row['id'];
        $stmt = $pdo->prepare(
            'UPDATE ' . self::T_M . ' SET inscripcion = 0 WHERE id = :id AND torneo_id = :t AND inscripcion = 1 LIMIT 1'
        );
        $stmt->bindValue(':id', $mid, PDO::PARAM_INT);
        $stmt->bindValue(':t', $torneoId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() < 1) {
            throw new InvalidArgumentException('No se pudo retirar la inscripción.');
        }

        return [
            'movimiento_id' => $mid,
            'id_usuario' => (int) ($row['id_usuario'] ?? 0),
            'cedula' => (string) ($row['cedula'] ?? ''),
            'torneo_id' => $torneoId,
        ];
    }

    /**
     * @return array<string, mixed>|null fila movimiento_torneo + usuario_asociacion_id
     */
    private static function resolverMovimientoInscrito(
        PDO $pdo,
        int $torneoId,
        int $asociacionContextoId,
        ?int $movimientoId,
        string $cedula
    ): ?array {
        $scopeSql = ' AND m.asociacion_id = :aid_scope ';
        if ($movimientoId !== null && $movimientoId > 0) {
            $sql = 'SELECT m.id, m.id_usuario, m.cedula, m.inscripcion, m.torneo_id, m.asociacion_id
                FROM ' . self::T_M . ' m
                WHERE m.id = :mid AND m.torneo_id = :tid AND ' . self::sqlInscripcionActiva('m') . $scopeSql . ' LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':mid', $movimientoId, PDO::PARAM_INT);
            $stmt->bindValue(':tid', $torneoId, PDO::PARAM_INT);
            $stmt->bindValue(':aid_scope', $asociacionContextoId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        }

        foreach (self::variantesCedulaBusqueda($cedula) as $variante) {
            $sql = 'SELECT m.id, m.id_usuario, m.cedula, m.inscripcion, m.torneo_id, m.asociacion_id
                FROM ' . self::T_M . ' m
                WHERE m.torneo_id = :tid AND m.cedula = :c AND ' . self::sqlInscripcionActiva('m') . $scopeSql . ' LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':tid', $torneoId, PDO::PARAM_INT);
            $stmt->bindValue(':c', $variante, PDO::PARAM_STR);
            $stmt->bindValue(':aid_scope', $asociacionContextoId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function variantesCedulaBusqueda(string $cedula): array
    {
        $cedula = preg_replace('/\s+/', '', trim($cedula)) ?? '';
        if ($cedula === '') {
            return [];
        }
        $out = [$cedula];
        $solo = preg_replace('/^[VEJP]/i', '', $cedula) ?? '';
        if ($solo !== '' && $solo !== $cedula) {
            $out[] = $solo;
        }
        $digits = preg_replace('/\D/', '', $cedula) ?? '';
        if ($digits !== '' && !in_array($digits, $out, true)) {
            $out[] = $digits;
        }

        return array_values(array_unique($out));
    }

    private static function validarSexoUsuario(int $sexo, int $tipoTorneo): void
    {
        if ($tipoTorneo === 1 && $sexo !== 1) {
            throw new InvalidArgumentException('El torneo es masculino: sexo del atleta no válido.');
        }
        if ($tipoTorneo === 2 && $sexo !== 2) {
            throw new InvalidArgumentException('El torneo es femenino: sexo del atleta no válido.');
        }
        if ($tipoTorneo === 3 && $sexo !== 1 && $sexo !== 2) {
            throw new InvalidArgumentException('Sexo del atleta no válido para torneo mixto.');
        }
    }

    /**
     * Exige fila en `movimiento_torneo` para este torneo con tridente de anualidad y carnet al día (valor 1).
     */
    private static function assertMovimientoAnualidadCarnetOk(\PDO $pdo, int $userId, int $torneoId, string $nombreAtleta): void
    {
        $stmt = $pdo->prepare(
            'SELECT anualidad, carnet FROM ' . self::T_M . ' WHERE id_usuario = :u AND torneo_id = :t LIMIT 1'
        );
        $stmt->bindValue(':u', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException(
                'Sin movimiento en este torneo para «' . $nombreAtleta . '»: el atleta debe estar afiliado al torneo activo antes de inscribir.'
            );
        }
        if ((int) ($row['anualidad'] ?? 0) < 1 || (int) ($row['carnet'] ?? 0) < 1) {
            throw new InvalidArgumentException(
                'Anualidad o carnet no vigentes para «' . $nombreAtleta . '» (revise movimiento en este torneo).'
            );
        }
    }

    /**
     * Identificador común para pareja/equipo dentro del mismo torneo (resultados agrupados).
     */
    private static function allocarGrupoId(\PDO $pdo, int $torneoId): int
    {
        if (!self::movimientoTieneColumna($pdo, 'grupo_id')) {
            return 1;
        }
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(grupo_id), 0) + 1 FROM ' . self::T_M . ' WHERE torneo_id = :t');
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $n = (int) $stmt->fetchColumn();

        return $n > 0 ? $n : 1;
    }

    /**
     * @param array<string, mixed> $u fila usuario
     * @return bool true si se marcó traspaso (atleta de otra asociación inscrito bajo esta asociación)
     */
    private static function upsertInscripcion(\PDO $pdo, int $userId, array $u, int $torneoId, int $asociacionId, ?string $grupoNombre, ?int $grupoId): bool
    {
        $cedula = self::cedulaParaMovimiento($u, $userId);
        $numfvd = (int) ($u['numfvd'] ?? 0);
        $sexo = (int) ($u['sexo'] ?? 0);
        $origenAid = (int) ($u['asociacion_id'] ?? 0);
        $traspaso = ($origenAid > 0 && $origenAid !== $asociacionId) ? 1 : 0;
        $gn = $grupoNombre !== null ? trim($grupoNombre) : null;
        $gn = $gn === '' ? null : $gn;
        $gid = $grupoId !== null && $grupoId > 0 ? $grupoId : null;
        $tieneGrupoNombre = self::movimientoTieneColumna($pdo, 'grupo_nombre');
        $tieneGrupoId = self::movimientoTieneColumna($pdo, 'grupo_id');

        $chk = $pdo->prepare('SELECT id FROM ' . self::T_M . ' WHERE id_usuario = :u AND torneo_id = :t LIMIT 1');
        $chk->bindValue(':u', $userId, \PDO::PARAM_INT);
        $chk->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $chk->execute();
        $mid = $chk->fetchColumn();
        if ($mid !== false) {
            $sets = [
                'inscripcion = 1',
                'cedula = :c',
                'numfvd = :n',
                'sexo = :s',
                'asociacion_id = :a',
                'traspaso = :tp',
            ];
            if ($tieneGrupoNombre) {
                $sets[] = 'grupo_nombre = :g';
            }
            if ($tieneGrupoId) {
                $sets[] = 'grupo_id = :gid';
            }
            $sql = 'UPDATE ' . self::T_M . ' SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':c', $cedula, \PDO::PARAM_STR);
            $stmt->bindValue(':n', $numfvd, \PDO::PARAM_INT);
            $stmt->bindValue(':s', $sexo, \PDO::PARAM_INT);
            $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
            if ($tieneGrupoNombre) {
                $stmt->bindValue(':g', $gn, $gn === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            }
            if ($tieneGrupoId) {
                $stmt->bindValue(':gid', $gid, $gid === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            }
            $stmt->bindValue(':tp', $traspaso, \PDO::PARAM_INT);
            $stmt->bindValue(':id', (int) $mid, \PDO::PARAM_INT);
            $stmt->execute();
            if ($traspaso === 1) {
                error_log(sprintf(
                    '[FVD][TRASPASO_INSCRIPCION] torneo_id=%d asociacion_inscripcion=%d usuario_id=%d asociacion_atleta=%d',
                    $torneoId,
                    $asociacionId,
                    $userId,
                    $origenAid
                ));
            }

            return $traspaso === 1;
        }

        $cols = ['id_usuario', 'cedula', 'numfvd', 'sexo', 'asociacion_id', 'torneo_id', 'inscripcion', 'traspaso'];
        $placeholders = [':u', ':c', ':n', ':s', ':a', ':t', '1', ':tp'];
        if ($tieneGrupoNombre) {
            $cols[] = 'grupo_nombre';
            $placeholders[] = ':g';
        }
        if ($tieneGrupoId) {
            $cols[] = 'grupo_id';
            $placeholders[] = ':gid';
        }
        $sqlIns = 'INSERT INTO ' . self::T_M . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmtIns = $pdo->prepare($sqlIns);
        $stmtIns->bindValue(':u', $userId, \PDO::PARAM_INT);
        $stmtIns->bindValue(':c', $cedula, \PDO::PARAM_STR);
        $stmtIns->bindValue(':n', $numfvd, \PDO::PARAM_INT);
        $stmtIns->bindValue(':s', $sexo, \PDO::PARAM_INT);
        $stmtIns->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmtIns->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmtIns->bindValue(':tp', $traspaso, \PDO::PARAM_INT);
        if ($tieneGrupoNombre) {
            $stmtIns->bindValue(':g', $gn, $gn === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        }
        if ($tieneGrupoId) {
            $stmtIns->bindValue(':gid', $gid, $gid === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        }
        $stmtIns->execute();
        if ($traspaso === 1) {
            error_log(sprintf(
                '[FVD][TRASPASO_INSCRIPCION] torneo_id=%d asociacion_inscripcion=%d usuario_id=%d asociacion_atleta=%d (nuevo movimiento)',
                $torneoId,
                $asociacionId,
                $userId,
                $origenAid
            ));
        }

        return $traspaso === 1;
    }

    /**
     * Actualiza teléfono del afiliado en `usuarios` (ámbito asociación activa).
     */
    public static function actualizarCelularUsuario(
        \PDO $pdo,
        int $usuarioId,
        int $asociacionId,
        string $celular
    ): void {
        self::actualizarDatosContactoUsuario($pdo, $usuarioId, $asociacionId, $celular, null);
    }

    /**
     * Actualiza teléfono y/o email del afiliado en `usuarios` (ámbito asociación activa).
     */
    public static function actualizarDatosContactoUsuario(
        \PDO $pdo,
        int $usuarioId,
        int $asociacionId,
        ?string $celular,
        ?string $email
    ): void {
        if ($usuarioId < 1) {
            throw new InvalidArgumentException('usuario_id inválido.');
        }
        $u = self::obtenerUsuario($pdo, $usuarioId);
        if ($u === null) {
            throw new InvalidArgumentException('Usuario no encontrado.');
        }
        $uidAsoc = (int) ($u['asociacion_id'] ?? 0);
        if ($uidAsoc !== $asociacionId) {
            throw new InvalidArgumentException('El atleta no pertenece a su asociación.');
        }

        $sets = [];
        $params = [':id' => $usuarioId, ':aid' => $asociacionId];

        if ($celular !== null) {
            $cel = preg_replace('/\s+/', '', trim($celular)) ?? '';
            if (strlen($cel) > 20) {
                throw new InvalidArgumentException('Teléfono demasiado largo (máx. 20 caracteres).');
            }
            $sets[] = 'celular = :cel';
            $params[':cel'] = $cel === '' ? null : $cel;
        }

        if ($email !== null) {
            $em = trim($email);
            if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Correo electrónico no válido.');
            }
            if (strlen($em) > 120) {
                throw new InvalidArgumentException('Correo demasiado largo (máx. 120 caracteres).');
            }
            $sets[] = 'email = :em';
            $params[':em'] = $em === '' ? null : $em;
        }

        if ($sets === []) {
            throw new InvalidArgumentException('No hay datos para actualizar.');
        }

        $sql = 'UPDATE ' . self::T_U . ' SET ' . implode(', ', $sets) . ' WHERE id = :id AND asociacion_id = :aid LIMIT 1';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            if ($k === ':cel' || $k === ':em') {
                $stmt->bindValue($k, $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            } else {
                $stmt->bindValue($k, $v, \PDO::PARAM_INT);
            }
        }
        $stmt->execute();
        if ($stmt->rowCount() < 1) {
            throw new InvalidArgumentException('No se pudo actualizar los datos del atleta.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buscarUnUsuarioEnAsociacion(\PDO $pdo, string $q, int $asociacionId): ?array
    {
        $q2 = '%' . $q . '%';
        $fa = self::filtroUsuarioAsociacionActivaSql($pdo, 'u');
        $stmt = $pdo->prepare(
            'SELECT u.id, u.cedula, u.nombre, u.numfvd, u.sexo, u.asociacion_id, u.email FROM ' . self::T_U . ' u
            WHERE (u.cedula = :c OR u.email = :e OR u.nombre LIKE :n)
            ' . $fa['sql'] . '
            ORDER BY (u.cedula = :c2) DESC, (u.email = :e2) DESC
            LIMIT 1'
        );
        self::bindFiltroUsuarioAsociacionActiva($stmt, $asociacionId, $fa);
        $stmt->bindValue(':c', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':e', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':n', $q2, \PDO::PARAM_STR);
        $stmt->bindValue(':c2', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':e2', $q, \PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Resolución de texto: primero en la asociación del contexto; si no hay coincidencia, búsqueda global (traspaso).
     *
     * @return array<string, mixed>|null
     */
    private static function buscarUnUsuarioPreferenciaAsociacion(\PDO $pdo, string $q, int $contextoAsociacionId): ?array
    {
        $local = self::buscarUnUsuarioEnAsociacion($pdo, $q, $contextoAsociacionId);
        if ($local !== null) {
            return $local;
        }

        return self::buscarUnUsuarioGlobal($pdo, $q);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buscarUnUsuarioGlobal(\PDO $pdo, string $q): ?array
    {
        $q2 = '%' . $q . '%';
        $stmt = $pdo->prepare(
            'SELECT id, cedula, nombre, numfvd, sexo, asociacion_id, email FROM ' . self::T_U . '
            WHERE (cedula = :c OR email = :e OR nombre LIKE :n)
            ORDER BY (cedula = :c2) DESC, (email = :e2) DESC
            LIMIT 1'
        );
        $stmt->bindValue(':c', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':e', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':n', $q2, \PDO::PARAM_STR);
        $stmt->bindValue(':c2', $q, \PDO::PARAM_STR);
        $stmt->bindValue(':e2', $q, \PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Inscripciones con posible traspaso pendiente de revisión por administración general.
     */
    public static function contarTraspasosInscripcionPendientes(\PDO $pdo, int $torneoId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::T_M . ' WHERE torneo_id = :t AND inscripcion = 1 AND traspaso = 1'
        );
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Inscripciones con traspaso pendiente de supervisión FVD, en la nómina de la asociación de inscripción.
     *
     * @return list<array<string, mixed>>
     */
    public static function listarSolicitudesTraspasoInscripcion(\PDO $pdo, int $torneoId, int $asociacionInscripcionId, ?string $q): array
    {
        $sql = 'SELECT m.id AS movimiento_id, m.cedula, m.numfvd, m.sexo, m.grupo_nombre,
            u.id AS usuario_id, u.nombre AS nombre_usuario, u.email AS usuario_email,
            u.asociacion_id AS usuario_asociacion_id,
            COALESCE(ao.nombre, \'—\') AS asociacion_origen_nombre
            FROM ' . self::T_M . ' m
            INNER JOIN ' . self::T_U . ' u ON u.id = m.id_usuario
            LEFT JOIN asociaciones ao ON ao.id = u.asociacion_id
            WHERE m.torneo_id = :tid AND m.asociacion_id = :aid
              AND m.inscripcion = 1 AND m.traspaso = 1';
        $qTrim = $q !== null ? trim($q) : '';
        if ($qTrim !== '') {
            $sql .= ' AND (u.cedula LIKE :qc OR u.nombre LIKE :qn OR u.email LIKE :qe)';
        }
        $sql .= ' ORDER BY u.nombre ASC, m.cedula ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':tid', $torneoId, \PDO::PARAM_INT);
        $stmt->bindValue(':aid', $asociacionInscripcionId, \PDO::PARAM_INT);
        if ($qTrim !== '') {
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qTrim);
            $like = '%' . $esc . '%';
            $stmt->bindValue(':qc', $like, \PDO::PARAM_STR);
            $stmt->bindValue(':qn', $like, \PDO::PARAM_STR);
            $stmt->bindValue(':qe', $like, \PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * @return array{inscritos: int, costo_bs: float|null, tasa_eur_bs: float, total_bs: float|null, total_eur: float|null}
     */
    public static function resumenFinanzasInscripcion(\PDO $pdo, int $torneoId, int $asociacionId, ?float $costoTorneoBs, float $tasaEurBs): array
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::T_M . ' m
            INNER JOIN ' . self::T_U . ' u ON u.id = m.id_usuario
            WHERE m.torneo_id = :t AND m.inscripcion = 1
            AND (m.asociacion_id = :a OR u.asociacion_id = :a2)'
        );
        $stmt->bindValue(':t', $torneoId, \PDO::PARAM_INT);
        $stmt->bindValue(':a', $asociacionId, \PDO::PARAM_INT);
        $stmt->bindValue(':a2', $asociacionId, \PDO::PARAM_INT);
        $stmt->execute();
        $n = (int) $stmt->fetchColumn();
        $costo = $costoTorneoBs !== null ? (float) $costoTorneoBs : null;
        $totalBs = $costo !== null ? round($n * $costo, 2) : null;
        $totalEur = ($totalBs !== null && $tasaEurBs > 0) ? round($totalBs / $tasaEurBs, 2) : null;

        return [
            'inscritos' => $n,
            'costo_bs' => $costo,
            'tasa_eur_bs' => $tasaEurBs,
            'total_bs' => $totalBs,
            'total_eur' => $totalEur,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function obtenerUsuario(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::T_U . ' WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function obtenerTorneoPorId(\PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . self::T_T . ' WHERE torneo = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
