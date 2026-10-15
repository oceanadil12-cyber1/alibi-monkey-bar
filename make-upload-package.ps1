# Builds the hand-off folder for the web developer: exactly the files that go into
# public_html on the host, as a folder and as a ZIP, plus the go-live guide.
# Secrets and local data never go in: api/config.php, api/data/ and api/cache/ are
# git-ignored, and only the website's own folders are taken.
$ErrorActionPreference = 'Stop'
$repo = $PSScriptRoot
$out  = Join-Path (Split-Path $repo -Parent) 'Alibi Website - For Developer'
$web  = Join-Path $out 'public_html'
$zip  = Join-Path $out 'alibi-website-upload.zip'

# What belongs on the web server: root pages + these folders.
$folders = @('admin/', 'api/', 'css/', 'data/', 'dine-in/', 'fonts/', 'images/', 'js/', 'kds/')
$rootFiles = '^(?:[^/]+\.html|\.htaccess|robots\.txt)$'
$never = '(^|/)(config\.php|Thumbs\.db|desktop\.ini|\.DS_Store)$|\.(bak|log|tmp)$|^api/(data|cache|vendor)/'

Push-Location $repo
try {
    # Tracked files plus new ones not yet committed; git-ignored files (secrets, data) are left out.
    $files = @(git -c core.quotepath=off ls-files --cached --others --exclude-standard | Where-Object {
        $f = $_
        ($f -match $rootFiles) -or (@($folders | Where-Object { $f.StartsWith($_) }).Count -gt 0)
    } | Where-Object { $_ -notmatch $never -and (Test-Path -LiteralPath $_ -PathType Leaf) } | Sort-Object)
    if (-not ($files -contains 'api/setup.sql') -or -not ($files -contains '.htaccess')) { throw 'setup.sql or .htaccess missing from the package list.' }

    $commit  = (git rev-parse --short HEAD).Trim()
    $version = (git describe --tags --always 2>$null)
    $dirty   = [bool](git status --porcelain)
} finally { Pop-Location }

# Fresh output folder.
if (Test-Path $web) { Remove-Item -Recurse -Force $web }
if (Test-Path $zip) { Remove-Item -Force $zip }
New-Item -ItemType Directory -Force $web | Out-Null

foreach ($f in $files) {
    $dest = Join-Path $web ($f -replace '/', '\')
    New-Item -ItemType Directory -Force (Split-Path $dest -Parent) | Out-Null
    Copy-Item -LiteralPath (Join-Path $repo $f) -Destination $dest
}

# ZIP with forward-slash paths (what Linux hosts and cPanel's Extract expect).
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::Open($zip, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($f in $files) {
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, (Join-Path $repo $f), $f, [System.IO.Compression.CompressionLevel]::Optimal)
    }
} finally { $archive.Dispose() }

Copy-Item -LiteralPath (Join-Path $repo 'docs\GO-LIVE.md') -Destination (Join-Path $out 'HOW-TO-GO-LIVE.md') -Force
$stamp = Get-Date -Format 'yyyy-MM-dd HH:mm'
@(
    "Alibi Monkey Bar website - upload package",
    "Built:   $stamp",
    "Version: $version (commit $commit)" + $(if ($dirty) { ' + changes not yet saved to GitHub' } else { '' }),
    "Files:   $($files.Count)",
    "",
    "Upload the CONTENTS of public_html (or alibi-website-upload.zip) into the",
    "domain's public_html folder, then follow HOW-TO-GO-LIVE.md."
) | Set-Content -Path (Join-Path $out 'VERSION.txt') -Encoding ascii

$mb = [math]::Round((Get-Item $zip).Length / 1MB, 1)
Write-Host ''
Write-Host " Package ready: $out" -ForegroundColor Green
Write-Host "   public_html\              $($files.Count) files (for FTP)"
Write-Host "   alibi-website-upload.zip  $mb MB (for cPanel File Manager -> Upload -> Extract)"
Write-Host '   HOW-TO-GO-LIVE.md         step-by-step guide for the developer'
Write-Host "   VERSION.txt               $version, commit $commit"
Write-Host ''
