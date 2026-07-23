param(
    [Parameter(Mandatory = $false)]
    [string]$PluginDir = (Join-Path $PSScriptRoot 'mobo-core'),

    [Parameter(Mandatory = $false)]
    [string]$Version
)

$ErrorActionPreference = 'Stop'
$pluginRoot = (Resolve-Path -LiteralPath $PluginDir).Path
$mainFile = Join-Path $pluginRoot 'mobo-core.php'
$manifestFile = Join-Path $pluginRoot 'mobo-core-manifest.json'

if (-not (Test-Path -LiteralPath $mainFile -PathType Leaf)) {
    throw "mobo-core.php was not found in: $pluginRoot"
}

if ([string]::IsNullOrWhiteSpace($Version)) {
    $mainContents = [System.IO.File]::ReadAllText($mainFile)
    $match = [regex]::Match(
        $mainContents,
        '(?mi)^\s*\*\s*Version:\s*([^\r\n]+)'
    )

    if (-not $match.Success) {
        throw 'Could not read the plugin Version header from mobo-core.php.'
    }

    $Version = $match.Groups[1].Value.Trim()
}

$files = [ordered]@{}
$allFiles = Get-ChildItem -LiteralPath $pluginRoot -Recurse -File |
    Where-Object { $_.FullName -ne $manifestFile } |
    Sort-Object FullName

foreach ($file in $allFiles) {
    $relative = $file.FullName.Substring($pluginRoot.Length).TrimStart('\', '/')
    $relative = $relative.Replace('\', '/')
    $files[$relative] = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
}

$manifest = [ordered]@{
    version     = $Version
    algorithm   = 'sha256'
    generatedAt = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    files       = $files
}

$json = $manifest | ConvertTo-Json -Depth 10
$utf8WithoutBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($manifestFile, $json + [Environment]::NewLine, $utf8WithoutBom)

Write-Host "Manifest generated: $manifestFile"
Write-Host "Version: $Version"
Write-Host "Tracked files: $($files.Count)"
