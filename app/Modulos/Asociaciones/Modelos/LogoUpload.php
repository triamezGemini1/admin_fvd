<?php

declare(strict_types=1);

namespace Fvd\Modulos\Asociaciones\Modelos;

/**
 * Subida de archivos del panel (logos, imágenes de usuario, afiches/invitaciones de torneo).
 */
final class LogoUpload
{
    /** @var array<string, string> extensión => mime aceptado (imágenes) */
    private const ALLOWED_IMAGE = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
    ];

    /** Invitación torneo: documentos */
    private const ALLOWED_INV = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Imagen (logo org/asoc, foto/cédula usuario, afiche torneo).
     *
     * @param array<string, mixed> $file elemento de $_FILES
     * @return string ruta relativa (p. ej. uploads/organizacion/…)
     */
    public static function guardar(array $file, string $relativeDir, string $prefix): string
    {
        return self::guardarConMap($file, $relativeDir, $prefix, self::ALLOWED_IMAGE, 'imagen');
    }

    /**
     * Invitación de torneo (PDF / Word).
     *
     * @param array<string, mixed> $file $_FILES['archivo']
     */
    public static function guardarInvitacionTorneo(array $file, string $relativeDir, string $prefix): string
    {
        return self::guardarConMap($file, $relativeDir, $prefix, self::ALLOWED_INV, 'documento');
    }

    /**
     * @param array<string, string> $allowed extensión => descripción
     */
    private static function guardarConMap(array $file, string $relativeDir, string $prefix, array $allowed, string $tipoEtiqueta): string
    {
        if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('No se recibió archivo.');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Error al subir el archivo.');
        }
        $root = realpath(dirname(__DIR__));
        if ($root === false) {
            throw new RuntimeException('No se pudo resolver la raíz del proyecto.');
        }
        $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                throw new RuntimeException('No se pudo crear el directorio de subidas.');
            }
        }
        $name = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !isset($allowed[$ext])) {
            throw new InvalidArgumentException('Tipo de ' . $tipoEtiqueta . ' no permitido.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Archivo temporal inválido.');
        }
        $destName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = $dir . DIRECTORY_SEPARATOR . $destName;
        if (!move_uploaded_file($tmp, $destPath)) {
            throw new RuntimeException('No se pudo guardar el archivo.');
        }

        return $relativeDir . '/' . $destName;
    }
}
