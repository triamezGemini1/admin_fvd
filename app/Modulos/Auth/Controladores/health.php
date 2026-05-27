<?php

declare(strict_types=1);

/**
 * Diagnóstico de despliegue: BD principal + BD personas (sin contraseñas).
 * BD personas solo se prueba con ?probe_persona=1 (lazy loading estricto).
 */
require_once __DIR__ . '/../../bootstrap_modulo.php';

use Fvd\Config\DatabaseConfig;
use Fvd\Config\DatabaseFactory;
use Fvd\Config\Env;
use Fvd\Config\PersonaDatabaseFactory;
use Fvd\Database\ConnectionManager;

header('Content-Type: application/json; charset=UTF-8');

$key = trim(Env::get('FVD_HEALTH_KEY', '') ?? '');
if ($key !== '') {
    $req = trim((string) ($_GET['key'] ?? ''));
    if (!hash_equals($key, $req)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Clave de diagnóstico incorrecta.']);
        exit;
    }
}

$debug = Env::get('FVD_APP_DEBUG', '0') === '1';
$probePersona = isset($_GET['probe_persona']) && $_GET['probe_persona'] === '1';

$pdoMain = ConnectionManager::tryGetPortalConnection();
$mainOk = $pdoMain !== null;

$pdoPersona = null;
$personaProbe = null;
if ($probePersona) {
    $pdoPersona = PersonaDatabaseFactory::connect();
    $personaProbe = PersonaDatabaseFactory::probeTable();
}

$personaDisabled = PersonaDatabaseFactory::isDisabled();
$personaConfigured = PersonaDatabaseFactory::isConfigured();
$personaOk = !$probePersona || $personaDisabled || ($pdoPersona !== null && ($personaProbe['ok'] ?? false));

$loginProbe = null;
$probeUser = trim((string) ($_GET['probe_user'] ?? ''));
if ($key !== '' && $probeUser !== '' && $pdoMain !== null) {
    $loginProbe = loginHealthProbe($pdoMain, $probeUser);
}

$portalSummary = DatabaseConfig::portalSummary();
$personasSummary = DatabaseConfig::personasSummary();

echo json_encode([
    'ok' => $mainOk && $personaOk,
    'php' => PHP_VERSION,
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'env_file' => DatabaseFactory::envFileExists() ? 'found' : 'missing',
    'env_path' => $debug ? DatabaseFactory::envFilePath() : null,
    'env_warnings' => Env::warnings(),
    'env_primary' => array_merge(DatabaseFactory::configSummary(), $portalSummary),
    'app_env' => Env::get('FVD_APP_ENV', 'development'),
    'debug_tip' => !$mainOk
        ? 'Agregue FVD_APP_DEBUG=1 y FVD_HEALTH_KEY=clave en .env, luego abra api/health.php?key=clave para ver el error MySQL exacto.'
        : null,
    'database_primary' => [
        'status' => $mainOk ? 'connected' : 'failed',
        'host' => $portalSummary['host'],
        'name' => $portalSummary['database'],
        'user' => $portalSummary['username'],
        'config_source' => $portalSummary['config_source'],
        'error' => $debug && !$mainOk ? ConnectionManager::lastPortalError() : null,
    ],
    'database_persona' => [
        'probe_requested' => $probePersona,
        'disabled' => $personaDisabled,
        'configured' => $personaConfigured,
        'status' => !$probePersona
            ? 'not_probed'
            : ($personaDisabled
                ? 'disabled'
                : ($pdoPersona !== null ? 'connected' : 'failed')),
        'host' => $personasSummary['host'],
        'name' => $personasSummary['database'],
        'user' => $personasSummary['username'],
        'password_set' => $personasSummary['password_set'],
        'credentials_inherited' => $personasSummary['credentials_inherited'],
        'table' => $personasSummary['table'],
        'config_source' => $personasSummary['config_source'],
        'table_probe' => $probePersona && !$personaDisabled ? $personaProbe : null,
        'error' => $debug && $probePersona && !$personaDisabled && $pdoPersona === null
            ? ConnectionManager::lastPersonasError()
            : null,
        'hint' => !$probePersona
            ? 'Agregue ?probe_persona=1 para probar la BD remota de personas (lazy loading).'
            : null,
    ],
    'hint_same_hosting' => 'Misma cuenta cPanel: ambas BD suelen usar host=localhost con usuario MySQL distinto por BD.',
    'hint_remote_hosting' => 'Otra cuenta/servidor: DB_PERSONAS_URL o FVD_PERSONA_DB_HOST=laestaciondeldominohoy.com y MySQL remoto activo en cPanel.',
    'login_probe' => $loginProbe,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

/**
 * @return array<string, mixed>
 */
function loginHealthProbe(\PDO $pdo, string $identifier): array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return ['found' => false, 'message' => 'probe_user vacío'];
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, email, cedula, role, status,
                CASE WHEN password_hash LIKE \'$2y$%\' OR password_hash LIKE \'$2a$%\' THEN 1 ELSE 0 END AS bcrypt_ok
         FROM usuarios
         WHERE username = :id OR email = :id OR cedula = :id
         LIMIT 1'
    );
    $stmt->bindValue(':id', $identifier, \PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row === false) {
        return [
            'found' => false,
            'message' => 'No hay fila con ese username, email o cédula en esta BD.',
        ];
    }

    $st = (int) $row['status'];
    $canLogin = in_array($st, [1, 9], true);

    return [
        'found' => true,
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'email' => $row['email'],
        'role' => $row['role'],
        'status' => $st,
        'can_login' => $canLogin,
        'bcrypt_ok' => (bool) $row['bcrypt_ok'],
        'hint' => $canLogin
            ? ($row['bcrypt_ok'] ? 'Usuario OK; si falla el login, la contraseña no coincide.' : 'password_hash no es bcrypt; ejecute bootstrap o reset de contraseña.')
            : 'status debe ser 1 (aprobado) o 9 (pendiente/habilitado). Actual: ' . $st,
    ];
}
