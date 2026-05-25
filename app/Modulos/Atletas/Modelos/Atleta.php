<?php

declare(strict_types=1);

namespace Fvd\Modulos\Atletas\Modelos;

use Fvd\Modulos\Torneos\Modelos\InscripcionTorneo;

/**
 * Acceso a la tabla `atletas` del esquema fvdmasteradmin.
 */
class Atleta
{
    private $conn;

    private $table_name = 'atletas';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Listado de atletas con datos de usuario/asociación si existen.
     *
     * @param int|null $filtraAsociacionId >0: solo filas con `usuarios.asociacion_id` igual
     * @param int|null $filtraUsuarioStatus filtra por `usuarios.status` (requiere fila en `usuarios`)
     */
    public function listarActivos(?int $filtraAsociacionId = null, ?int $filtraUsuarioStatus = null)
    {
        $parts = ['1=1'];
        $params = [];
        if ($filtraAsociacionId !== null && $filtraAsociacionId > 0) {
            $parts[] = 'u.asociacion_id = :aid';
            $params[':aid'] = $filtraAsociacionId;
        }
        if ($filtraUsuarioStatus !== null) {
            $parts[] = 'u.id IS NOT NULL AND u.status = :ust';
            $params[':ust'] = $filtraUsuarioStatus;
        }
        $where = implode(' AND ', $parts);
        $query = 'SELECT a.id, a.cedula, a.nombre, a.numfvd, a.sexo, a.fechnac, a.estatus,
                  COALESCE(aso.nombre, \'\') AS asociacion_nombre,
                  COALESCE(aso.logo, \'\') AS asociacion_logo,
                  COALESCE(u.urlimgfoto, \'\') AS foto_url,
                  u.status AS usuario_status
                  FROM ' . $this->table_name . ' a
                  LEFT JOIN usuarios u ON TRIM(u.cedula) = TRIM(a.cedula)
                  LEFT JOIN asociaciones aso ON aso.id = u.asociacion_id
                  WHERE ' . $where . '
                  ORDER BY a.nombre ASC';

        $stmt = $this->conn->prepare($query);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, \PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt;
    }

    public function calcularEdad($fecha_nacimiento)
    {
        $nacimiento = new DateTime($fecha_nacimiento);
        $hoy = new DateTime();

        return $hoy->diff($nacimiento)->y;
    }

    public function listarPorAsociacion($id_asociacion)
    {
        $query = 'SELECT id, cedula, nombre, numfvd, sexo, fechnac, estatus
                  FROM ' . $this->table_name . '
                  WHERE asociacion = :id_asociacion
                  ORDER BY nombre ASC';

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_asociacion', $id_asociacion, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Búsqueda predictiva para inscripciones: delega en `InscripcionTorneo` sobre `usuarios`.
     * La tabla legacy `atletas` no participa; la tabla `equipos` del volcado antiguo es ranking por club, no sustituye `grupo_id` en `movimiento_torneo`.
     *
     * @return list<array<string, mixed>>
     */
    public static function buscarUsuariosPredInscripcion(\PDO $pdo, string $termino, ?int $asociacionId, int $limite = 15, ?int $torneoActivoId = null): array
    {
        return InscripcionTorneo::buscarUsuariosParaAutocomplete($pdo, $termino, $asociacionId, $limite, $torneoActivoId);
    }

    /**
     * Inscribe integrantes (pareja/equipo) vinculados por `grupo_id` en `movimiento_torneo`.
     *
     * @param list<int> $usuarioIds
     */
    public static function inscribirIntegrantesTorneo(\PDO $pdo, int $torneoId, int $asociacionId, string $modalidad, array $usuarioIds, ?string $grupoNombre): void
    {
        InscripcionTorneo::inscribir($pdo, $torneoId, $asociacionId, $modalidad, $usuarioIds, $grupoNombre);
    }
}
