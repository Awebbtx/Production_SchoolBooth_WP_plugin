# Build a WordPress-installable plugin zip.
#
# IMPORTANT: PowerShell's built-in Compress-Archive writes ZIP entries with
# backslash path separators on Windows. WordPress's PclZip rejects those
# entries (it's a ZIP-spec violation), which produces the misleading
# "plugin does not exist" error during Plugins -> Add New -> Upload Plugin.
#
# This script uses [System.IO.Compression.ZipFile]::CreateFromDirectory
# instead, which writes spec-compliant forward slashes.

param(
    [string]$Version
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Push-Location $repoRoot
try {
    if (-not $Version) {
        $main = Get-Content 'schoolbooth-photo-manager.php' -Raw
        if ($main -match '^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)' -or
            $main -match '\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)') {
            $Version = $Matches[1]
        } else {
            throw 'Could not detect version from plugin header. Pass -Version <x.y.z>.'
        }
    }

    Write-Host "Packaging schoolbooth-photo-manager v$Version"

    if (-not (Test-Path 'dist')) { New-Item -ItemType Directory dist | Out-Null }

    $stage = Join-Path 'dist' 'schoolbooth-photo-manager'
    if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
    New-Item -ItemType Directory -Path $stage | Out-Null

    # Copy plugin files. Exclude dev-only directories.
    Get-ChildItem -Path . -Force -Exclude '.git', '.github', 'dist', 'node_modules', '.vscode', 'release_notes_*' |
        ForEach-Object {
            Copy-Item -Path $_.FullName -Destination $stage -Recurse -Force
        }

    # Strip release notes if any were copied via wildcard miss
    Get-ChildItem -Path $stage -Filter 'release_notes_*' -ErrorAction SilentlyContinue | Remove-Item -Force

    $zipPath = Join-Path 'dist' "schoolbooth-photo-manager-v$Version.zip"
    if (Test-Path $zipPath) { Remove-Item $zipPath }

    Add-Type -Assembly 'System.IO.Compression.FileSystem'
    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        (Resolve-Path $stage),
        (Join-Path (Get-Location) $zipPath),
        [System.IO.Compression.CompressionLevel]::Optimal,
        $true   # include base directory in zip ("schoolbooth-photo-manager/")
    )

    $info = Get-Item $zipPath
    Write-Host ("Built {0} ({1:N0} bytes)" -f $info.Name, $info.Length)
}
finally {
    Pop-Location
}
