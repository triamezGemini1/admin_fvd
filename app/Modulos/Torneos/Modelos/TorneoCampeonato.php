<?php

declare(strict_types=1);

namespace Fvd\Modulos\Torneos\Modelos;

/**
 * Alta de campeonatos: varios registros en `torneosact` con el mismo `grupo_evento_id`.
 */
class TorneoCampeonato
{
    public const MODO_GENERO = 'campeonato_genero';

    public const MODO_CATEGORIA = 'campeonato_categoria';

    private const T_GRUPO = 'fvd_campeonato_grupo';

    /**
     * @return list<string>
     */
    public static function modosValidos(): array
    {
        return [self::MODO_GENERO, self::MODO_CATEGORIA];
    }

    public static function esModoCampeonato(string $modo): bool
    {
        return in_array(trim($modo), self::modosValidos(), true);
    }

    /**
     * @param array<string, mixed> $datosBase datos comunes del formulario (sin grupo_evento_id)
     * @return array{grupo_evento_id: int, torneo_ids: list<int>, message: string}
     */
    public static function crearDesdeFormulario(
        \PDO $pdo,
        array $datosBase,
        string $modo,
        ?string $rutaInvitacion,
        ?string $rutaAfiche
    ): array {
        $modo = trim($modo);
        if (!self::esModoCampeonato($modo)) {
            throw new \InvalidArgumentException('Modo de campeonato no válido.');
        }

        $nombreBase = trim((string) ($datosBase['nombre'] ?? ''));
        if ($nombreBase === '') {
            throw new \InvalidArgumentException('El nombre del campeonato es obligatorio.');
        }

        $variantes = self::variantesPorModo($modo, $datosBase);
        if ($variantes === []) {
            throw new \InvalidArgumentException('No hay variantes definidas para este campeonato.');
        }

        $pdo->beginTransaction();
        try {
            $grupoId = self::siguienteGrupoEventoId($pdo);
            self::registrarNombreGrupo($pdo, $grupoId, $nombreBase);

            $ids = [];
            foreach ($variantes as $var) {
                $fila = $datosBase;
                $fila['nombre'] = $nombreBase . (string) ($var['nombre_suffix'] ?? '');
                $fila['grupo_evento_id'] = $grupoId;
                if (isset($var['tipo'])) {
                    $fila['tipo'] = $var['tipo'];
                }
                if (isset($var['rondas'])) {
                    $fila['rondas'] = $var['rondas'];
                }
                $ids[] = Torneo::crear($pdo, $fila, $rutaInvitacion, $rutaAfiche);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $n = count($ids);
        $msg = $modo === self::MODO_GENERO
            ? "Campeonato registrado: {$n} torneos (masculino y femenino) en grupo {$grupoId}."
            : "Campeonato registrado: {$n} torneos (Sub 12, Sub 15 y Sub 18) en grupo {$grupoId}.";

        return [
            'grupo_evento_id' => $grupoId,
            'torneo_ids' => $ids,
            'message' => $msg,
        ];
    }

    public static function siguienteGrupoEventoId(\PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT COALESCE(MAX(grupo_evento_id), 0) + 1 AS g FROM torneosact');
        if ($stmt === false) {
            throw new \RuntimeException('No se pudo calcular el ID de grupo del campeonato.');
        }
        $g = (int) $stmt->fetchColumn();

        return $g > 0 ? $g : 1;
    }

    /**
     * @return list<array{nombre_suffix: string, tipo?: int, rondas?: int}>
     */
    public static function variantesPorModo(string $modo, array $datosBase): array
    {
        if ($modo === self::MODO_GENERO) {
            $rondas = isset($datosBase['rondas']) && $datosBase['rondas'] !== ''
                ? (int) $datosBase['rondas']
                : Torneo::DEFAULT_RONDAS;

            return [
                ['nombre_suffix' => ' MASCULINO', 'tipo' => 1, 'rondas' => $rondas],
                ['nombre_suffix' => ' FEMENINO', 'tipo' => 2, 'rondas' => $rondas],
            ];
        }

        if ($modo === self::MODO_CATEGORIA) {
            $tipoBase = isset($datosBase['tipo']) && $datosBase['tipo'] !== '' ? (int) $datosBase['tipo'] : 3;
            if ($tipoBase < 1) {
                $tipoBase = 3;
            }

            return [
                ['nombre_suffix' => ' CATEG SUB 12', 'tipo' => $tipoBase, 'rondas' => 5],
                ['nombre_suffix' => ' CATEG SUB 15', 'tipo' => $tipoBase, 'rondas' => 7],
                ['nombre_suffix' => ' CATEG SUB 18', 'tipo' => $tipoBase, 'rondas' => 7],
            ];
        }

        return [];
    }

    private static function tablaGrupoNominalDisponible(\PDO $pdo): bool
    {
        try {
            $st = $pdo->query("SHOW TABLES LIKE '" . self::T_GRUPO . "'");

            return $st !== false && $st->fetch(\PDO::FETCH_NUM) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Metadatos del torneo para nómina / finanzas (identificador y filtro de atletas).
     *
     * @return array{
     *   torneo_id: int,
     *   nombre: string,
     *   clavetor: string|null,
     *   grupo_evento_id: int|null,
     *   es_campeonato: bool,
     *   modo_campeonato: string|null,
     *   variante_etiqueta: string,
     *   tipo_torneo: int,
     *   filtro_atleta: array<string, mixed>|null,
     *   fechator: string|null
     * }
     */
    public static function metaProcesamientoTorneo(\PDO $pdo, int $torneoId): array
    {
        if ($torneoId < 1) {
            throw new \InvalidArgumentException('torneo_id inválido.');
        }
        $stmt = $pdo->prepare(
            'SELECT torneo, nombre, clavetor, tipo, grupo_evento_id, fechator, finalizado_en
             FROM torneosact WHERE torneo = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $torneoId, \PDO::PARAM_INT);
        $stmt->execute();
        $tf = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($tf === false) {
            throw new \InvalidArgumentException('Torneo no encontrado.');
        }
        $gid = (int) ($tf['grupo_evento_id'] ?? 0);
        $tipo = (int) ($tf['tipo'] ?? 0);
        $nombre = (string) ($tf['nombre'] ?? '');
        $modoGrupo = $gid > 0 ? self::detectarModoGrupo($pdo, $gid) : null;

        return [
            'torneo_id' => $torneoId,
            'nombre' => $nombre,
            'clavetor' => isset($tf['clavetor']) ? (string) $tf['clavetor'] : null,
            'grupo_evento_id' => $gid > 0 ? $gid : null,
            'es_campeonato' => $gid > 0,
            'modo_campeonato' => $modoGrupo,
            'variante_etiqueta' => self::etiquetaVarianteTorneo($nombre, $tipo, $modoGrupo),
            'tipo_torneo' => $tipo,
            'filtro_atleta' => self::construirFiltroAtleta($nombre, $tipo, $modoGrupo),
            'fechator' => $tf['fechator'] ?? null,
            'finalizado_en' => $tf['finalizado_en'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function torneosDeGrupo(\PDO $pdo, int $grupoEventoId): array
    {
        if ($grupoEventoId < 1) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT torneo, nombre, clavetor, tipo, grupo_evento_id, fechator, finalizado_en, rondas
             FROM torneosact WHERE grupo_evento_id = :g ORDER BY torneo ASC'
        );
        $stmt->bindValue(':g', $grupoEventoId, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    public static function detectarModoGrupo(\PDO $pdo, int $grupoEventoId): ?string
    {
        $rows = self::torneosDeGrupo($pdo, $grupoEventoId);
        if (count($rows) < 2) {
            return null;
        }
        $subs = 0;
        $tipos = [];
        foreach ($rows as $r) {
            $nom = (string) ($r['nombre'] ?? '');
            if (self::categoriaLimiteDesdeNombre($nom) !== null) {
                $subs++;
            }
            $t = (int) ($r['tipo'] ?? 0);
            if ($t > 0) {
                $tipos[$t] = true;
            }
        }
        if ($subs >= 2) {
            return self::MODO_CATEGORIA;
        }
        if (isset($tipos[1]) && isset($tipos[2]) && count($tipos) === 2) {
            return self::MODO_GENERO;
        }

        return null;
    }

    public static function etiquetaVarianteTorneo(string $nombre, int $tipo, ?string $modoGrupo): string
    {
        if ($modoGrupo === self::MODO_CATEGORIA) {
            $lim = self::categoriaLimiteDesdeNombre($nombre);
            if ($lim === 12) {
                return 'Categoría Sub 12';
            }
            if ($lim === 15) {
                return 'Categoría Sub 15';
            }
            if ($lim === 18) {
                return 'Categoría Sub 18';
            }
        }
        if ($modoGrupo === self::MODO_GENERO || $tipo === 1 || $tipo === 2) {
            if ($tipo === 1 || self::nombreContiene($nombre, 'MASC')) {
                return 'Masculino';
            }
            if ($tipo === 2 || self::nombreContiene($nombre, 'FEM')) {
                return 'Femenino';
            }
        }
        if ($tipo === 3) {
            return 'Mixto';
        }

        return 'Torneo';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function construirFiltroAtleta(string $nombreTorneo, int $tipoTorneo, ?string $modoGrupo): ?array
    {
        if ($modoGrupo === self::MODO_GENERO) {
            if ($tipoTorneo === 1) {
                return ['tipo' => 'genero', 'sexo' => 1];
            }
            if ($tipoTorneo === 2) {
                return ['tipo' => 'genero', 'sexo' => 2];
            }

            return null;
        }
        if ($modoGrupo === self::MODO_CATEGORIA) {
            $lim = self::categoriaLimiteDesdeNombre($nombreTorneo);
            if ($lim !== null) {
                return ['tipo' => 'categoria', 'categoria_limite' => $lim];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row fila atleta/usuario (con sexo, categ, fechnac)
     * @param array<string, mixed>|null $filtro
     */
    public static function atletaAplicaAFiltroTorneo(array $row, ?array $filtro, ?string $fechator): bool
    {
        if ($filtro === null || $filtro === []) {
            return true;
        }
        $tipo = (string) ($filtro['tipo'] ?? '');
        if ($tipo === 'genero') {
            $req = (int) ($filtro['sexo'] ?? 0);
            $sexo = (int) ($row['u_sexo'] ?? 0);
            if ($sexo < 1) {
                $sexo = (int) ($row['a_sexo'] ?? 0);
            }

            return $req > 0 && $sexo === $req;
        }
        if ($tipo === 'categoria') {
            $lim = (int) ($filtro['categoria_limite'] ?? 0);
            if ($lim < 1) {
                return true;
            }

            return self::atletaEnCategoria($row, $lim, $fechator);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    /**
     * Sexo del atleta: 1=M, 2=F (prioriza `usuarios`, luego `atletas`).
     *
     * @param array<string, mixed> $row
     */
    public static function sexoAtletaDesdeFila(array $row): int
    {
        $sexo = (int) ($row['u_sexo'] ?? 0);
        if ($sexo < 1) {
            $sexo = (int) ($row['a_sexo'] ?? 0);
        }

        return $sexo === 1 || $sexo === 2 ? $sexo : 0;
    }

    /**
     * Torneos del campeonato por género: clave 1 = masculino, 2 = femenino.
     *
     * @return array{1?: int, 2?: int}
     */
    public static function mapTorneosIdPorSexoEnGrupo(\PDO $pdo, int $grupoEventoId): array
    {
        if ($grupoEventoId < 1) {
            return [];
        }
        if (self::detectarModoGrupo($pdo, $grupoEventoId) !== self::MODO_GENERO) {
            return [];
        }
        $map = [];
        foreach (self::torneosDeGrupo($pdo, $grupoEventoId) as $r) {
            $tid = (int) ($r['torneo'] ?? 0);
            $tipo = (int) ($r['tipo'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            if ($tipo === 1) {
                $map[1] = $tid;
            } elseif ($tipo === 2) {
                $map[2] = $tid;
            }
        }

        return $map;
    }

    /**
     * Torneos del campeonato por categoría: clave 12, 15 o 18 = límite Sub.
     *
     * @return array{12?: int, 15?: int, 18?: int}
     */
    public static function mapTorneosIdPorCategoriaEnGrupo(\PDO $pdo, int $grupoEventoId): array
    {
        if ($grupoEventoId < 1) {
            return [];
        }
        if (self::detectarModoGrupo($pdo, $grupoEventoId) !== self::MODO_CATEGORIA) {
            return [];
        }
        $map = [];
        foreach (self::torneosDeGrupo($pdo, $grupoEventoId) as $r) {
            $tid = (int) ($r['torneo'] ?? 0);
            $lim = self::categoriaLimiteDesdeNombre((string) ($r['nombre'] ?? ''));
            if ($tid > 0 && $lim !== null) {
                $map[$lim] = $tid;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row fila atleta/usuario
     */
    public static function resolverTorneoIdEnGrupoPorAtleta(\PDO $pdo, int $grupoEventoId, array $row): ?int
    {
        $map = self::mapTorneosIdPorSexoEnGrupo($pdo, $grupoEventoId);
        if ($map === []) {
            return null;
        }
        $sexo = self::sexoAtletaDesdeFila($row);

        return $map[$sexo] ?? null;
    }

    /**
     * Elimina de cada torneo del grupo filas cuyo sexo no corresponde (afina estadísticas de participación).
     *
     * @return array{masculino: int, femenino: int, total: int}
     */
    public static function limpiarMovimientoGeneroCruzadoEnGrupo(\PDO $pdo, int $grupoEventoId): array
    {
        $map = self::mapTorneosIdPorSexoEnGrupo($pdo, $grupoEventoId);
        $out = ['masculino' => 0, 'femenino' => 0, 'total' => 0];
        $tidM = (int) ($map[1] ?? 0);
        $tidF = (int) ($map[2] ?? 0);
        if ($tidM > 0) {
            $st = $pdo->prepare(
                'DELETE m FROM `movimiento_torneo` m
                 LEFT JOIN `usuarios` u ON u.id = m.id_usuario
                 LEFT JOIN `atletas` a ON TRIM(a.cedula) = TRIM(m.cedula)
                 WHERE m.torneo_id = :t
                   AND COALESCE(NULLIF(u.sexo, 0), NULLIF(a.sexo, 0), 0) <> 1'
            );
            $st->bindValue(':t', $tidM, \PDO::PARAM_INT);
            $st->execute();
            $out['masculino'] = $st->rowCount();
        }
        if ($tidF > 0) {
            $st = $pdo->prepare(
                'DELETE m FROM `movimiento_torneo` m
                 LEFT JOIN `usuarios` u ON u.id = m.id_usuario
                 LEFT JOIN `atletas` a ON TRIM(a.cedula) = TRIM(m.cedula)
                 WHERE m.torneo_id = :t
                   AND COALESCE(NULLIF(u.sexo, 0), NULLIF(a.sexo, 0), 0) <> 2'
            );
            $st->bindValue(':t', $tidF, \PDO::PARAM_INT);
            $st->execute();
            $out['femenino'] = $st->rowCount();
        }
        $out['total'] = $out['masculino'] + $out['femenino'];

        return $out;
    }

    public static function atletaEnCategoria(array $row, int $limiteSub, ?string $fechator): bool
    {
        $categ = (int) ($row['categ'] ?? 0);
        if (in_array($categ, [12, 15, 18], true)) {
            return $categ === $limiteSub;
        }
        $edad = self::edadEnFecha($row['fechnac'] ?? null, $fechator);
        if ($edad === null) {
            return false;
        }
        if ($limiteSub === 12) {
            return $edad <= 12;
        }
        if ($limiteSub === 15) {
            return $edad >= 13 && $edad <= 15;
        }
        if ($limiteSub === 18) {
            return $edad >= 16 && $edad <= 18;
        }

        return false;
    }

    public static function categoriaLimiteDesdeNombre(string $nombre): ?int
    {
        $n = function_exists('mb_strtoupper') ? mb_strtoupper($nombre, 'UTF-8') : strtoupper($nombre);
        if (str_contains($n, 'SUB 12') || str_contains($n, 'SUB12')) {
            return 12;
        }
        if (str_contains($n, 'SUB 15') || str_contains($n, 'SUB15')) {
            return 15;
        }
        if (str_contains($n, 'SUB 18') || str_contains($n, 'SUB18')) {
            return 18;
        }

        return null;
    }

    public static function edadEnFecha(?string $fechnac, ?string $fechaRef): ?int
    {
        $fn = trim((string) $fechnac);
        $fr = trim((string) $fechaRef);
        if ($fn === '' || $fr === '') {
            return null;
        }
        try {
            $nac = new \DateTimeImmutable(substr($fn, 0, 10));
            $ref = new \DateTimeImmutable(substr($fr, 0, 10));

            return (int) $nac->diff($ref)->y;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function nombreContiene(string $nombre, string $needle): bool
    {
        $n = function_exists('mb_strtoupper') ? mb_strtoupper($nombre, 'UTF-8') : strtoupper($nombre);

        return str_contains($n, $needle);
    }

    private static function registrarNombreGrupo(\PDO $pdo, int $grupoId, string $nombreNominal): void
    {
        if ($grupoId < 1 || !self::tablaGrupoNominalDisponible($pdo)) {
            return;
        }
        $nom = trim($nombreNominal);
        if ($nom === '') {
            $nom = '#' . $grupoId;
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::T_GRUPO . ' (grupo_evento_id, nombre_nominal)
                VALUES (:g, :n)
                ON DUPLICATE KEY UPDATE nombre_nominal = VALUES(nombre_nominal)'
            );
            $stmt->bindValue(':g', $grupoId, \PDO::PARAM_INT);
            $stmt->bindValue(':n', $nom, \PDO::PARAM_STR);
            $stmt->execute();
        } catch (\Throwable $e) {
            error_log('TorneoCampeonato::registrarNombreGrupo: ' . $e->getMessage());
        }
    }
}
