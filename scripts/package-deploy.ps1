# Empaqueta archivos para subir a producción (sin .env, .git, tools locales).
# Uso: powershell -ExecutionPolicy Bypass -File scripts/package-deploy.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$outZip = Join-Path $root 'deploy-fvd-admin.zip'

if (Test-Path $outZip) { Remove-Item $outZip -Force }

$excludeDirs = @('.git', 'node_modules', 'vendor', '.cursor', 'terminals')
$excludeFiles = @('.env', 'deploy-fvd-admin.zip')

$paths = @(
    'api',
    'app',
    'dist',
    'img',
    'sql',
    'src',
    'uploads',
    'docs',
    '.htaccess',
    '.env.production.example',
    '.env.example',
    'Database.php',
    'index.php',
    'panel.html',
    'afiliar_atleta.html',
    'rutas.php',
    'login.php'
)

$temp = Join-Path $env:TEMP ("fvd-deploy-" + [guid]::NewGuid().ToString())
New-Item -ItemType Directory -Path $temp | Out-Null

try {
    foreach ($rel in $paths) {
        $src = Join-Path $root $rel
        if (-not (Test-Path $src)) { continue }
        $dest = Join-Path $temp $rel
        if (Test-Path $src -PathType Container) {
            Copy-Item -Path $src -Destination $dest -Recurse -Force
        } else {
            $parent = Split-Path $dest -Parent
            if (-not (Test-Path $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }
            Copy-Item -Path $src -Destination $dest -Force
        }
    }

    # HTML raíz y APIs sueltas
    Get-ChildItem -Path $root -Filter '*.html' -File | ForEach-Object {
        Copy-Item $_.FullName (Join-Path $temp $_.Name) -Force
    }
    Get-ChildItem -Path (Join-Path $root 'api') -Filter '*.php' -File -ErrorAction SilentlyContinue | ForEach-Object {
        $d = Join-Path $temp 'api'
        if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null }
        Copy-Item $_.FullName (Join-Path $d $_.Name) -Force
    }

    if (Test-Path (Join-Path $temp '.env')) { Remove-Item (Join-Path $temp '.env') -Force }

    Compress-Archive -Path (Join-Path $temp '*') -DestinationPath $outZip -Force
    Write-Host "Paquete creado: $outZip"
    Write-Host "Suba el contenido a public_html/admin_fvd/ y configure .env en el servidor (no incluido en el zip)."
} finally {
    Remove-Item -Path $temp -Recurse -Force -ErrorAction SilentlyContinue
}
