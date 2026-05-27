<?php

declare(strict_types=1);

namespace Fvd\Servicios;

use Fvd\Database\ConnectionException;
use Fvd\Database\ConnectionManager;
use Fvd\Modulos\Atletas\Modelos\AfiliacionAtleta;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Orquestador de afiliaciones: validación de identidad (BD remota lazy) y persistencia en BD Portal.
 */
final class AffiliationService
{
    /**
     * Valida cédula en portal y, si es nueva, consulta identidad en BD Personas (lazy).
     *
     * @return array{
     *   ok: bool,
     *   exists: bool,
     *   blocked: bool,
     *   user: array<string, mixed>|null,
     *   message?: string,
     *   persona_externa?: array<string, mixed>,
     *   persona_fuente?: string,
     *   error?: string
     * }
     */
    public static function validarIdentidadParaAfiliacion(string $cedulaRaw, ?string $nacionalidad = null): array
    {
        $cedula = AfiliacionAtleta::normalizarCedula($cedulaRaw);
        if ($cedula === '') {
            throw new InvalidArgumentException('Indique la cédula.');
        }

        $pdo = self::getPortalConnection();

        $chk = AfiliacionAtleta::verificarAccesoConsultaCedula($pdo, $cedula);
        if (!$chk['allowed']) {
            return [
                'ok' => true,
                'exists' => true,
                'blocked' => true,
                'message' => $chk['message'] ?? 'No autorizado.',
                'user' => null,
            ];
        }

        if ($chk['user'] !== null) {
            return [
                'ok' => true,
                'exists' => true,
                'blocked' => false,
                'user' => $chk['user'],
            ];
        }

        $payload = [
            'ok' => true,
            'exists' => false,
            'blocked' => false,
            'user' => null,
        ];

        $externa = self::buscarPersonaExterna($cedulaRaw !== '' ? $cedulaRaw : $cedula, $nacionalidad);
        if ($externa !== null) {
            $payload['persona_externa'] = $externa['persona'];
            $payload['persona_fuente'] = $externa['fuente'];
        }

        return $payload;
    }

    /**
     * Registra o actualiza afiliación en BD Portal (transacción). No abre BD Personas.
     *
     * @param array<string, mixed> $post
     * @param array{foto: ?string, cedula_img: ?string} $rutasRelativas
     * @return array{user_id: int, movimiento_tridente: bool}
     */
    public static function registrarAfiliacion(array $post, array $rutasRelativas): array
    {
        $pdo = self::getPortalConnection();

        return AfiliacionAtleta::guardar($pdo, $post, $rutasRelativas);
    }

    /**
     * Procesa subida de imagen de afiliación.
     *
     * @param array<string, mixed> $file
     * @param array<string, string> $extMap
     */
    public static function guardarImagenUpload(
        array $file,
        string $dir,
        array $extMap,
        string $cedulaBase,
        string $suffix
    ): ?string {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Error al subir la imagen.');
        }
        $name = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !isset($extMap[$ext])) {
            throw new RuntimeException('Formato de imagen no permitido (JPG, PNG, WebP).');
        }
        $safeCed = preg_replace('/\W/', '_', $cedulaBase);
        if ($safeCed === '') {
            $safeCed = 'ced';
        }
        $destName = $safeCed . '_' . $suffix . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = $dir . DIRECTORY_SEPARATOR . $destName;
        if (!move_uploaded_file((string) $file['tmp_name'], $destPath)) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }

        return 'dist/assets/img/uploads/' . $destName;
    }

    /**
     * Resuelve directorio de subidas para imágenes de afiliación.
     */
    public static function resolverDirectorioUploads(string $projectRoot): string
    {
        $uploadRoot = $projectRoot . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'assets'
            . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($uploadRoot)) {
            @mkdir($uploadRoot, 0755, true);
        }
        $resolved = realpath($uploadRoot) ?: $uploadRoot;
        if (!is_dir($resolved)) {
            if (!@mkdir($resolved, 0755, true)) {
                throw new RuntimeException('No se pudo crear la carpeta de subidas.');
            }
        }

        return $resolved;
    }

    /**
     * Mensaje seguro para errores de conexión (sin credenciales).
     */
    public static function mensajeErrorConexion(ConnectionException $e): string
    {
        if ($e->connectionName() === 'personas') {
            return 'Base de datos de personas no disponible. Intente más tarde o contacte al administrador.';
        }

        return 'Servicio no disponible. Verifique la configuración de la base de datos.';
    }

    private static function getPortalConnection(): PDO
    {
        try {
            return ConnectionManager::getPortalConnection();
        } catch (ConnectionException $e) {
            throw new RuntimeException(self::mensajeErrorConexion($e), 0, $e);
        }
    }

    /**
     * Consulta BD Personas solo cuando la cédula no existe en portal.
     *
     * @return array{persona: array<string, mixed>, fuente: string}|null
     */
    private static function buscarPersonaExterna(string $cedulaInput, ?string $nacionalidad): ?array
    {
        if (!PersonaService::isConfigured()) {
            return null;
        }

        try {
            $result = PersonaService::buscarDesdeTextoCedula($cedulaInput, $nacionalidad);
        } catch (Throwable $e) {
            error_log('AffiliationService::buscarPersonaExterna — ' . $e->getMessage());

            return null;
        }

        if (empty($result['encontrado']) || !isset($result['persona']) || !is_array($result['persona'])) {
            return null;
        }

        return [
            'persona' => $result['persona'],
            'fuente' => (string) ($result['fuente'] ?? 'externa'),
        ];
    }
}
