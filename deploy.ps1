$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$envFile = Join-Path $projectRoot '.env'
$localFile = Join-Path $projectRoot 'literaki.php'

if (-not (Test-Path -LiteralPath $envFile -PathType Leaf)) {
    throw "Environment file not found: $envFile"
}

if (-not (Test-Path -LiteralPath $localFile -PathType Leaf)) {
    throw "File to deploy not found: $localFile"
}

$config = @{}
foreach ($line in Get-Content -LiteralPath $envFile) {
    if ($line -match '^\s*(?:#|$)') {
        continue
    }

    if ($line -notmatch '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$') {
        throw 'Invalid line in .env. Expected KEY=value.'
    }

    $name = $matches[1]
    $value = $matches[2]
    if ($value.Length -ge 2 -and
        (($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'")))) {
        $value = $value.Substring(1, $value.Length - 2)
    }

    $config[$name] = $value
}

$requiredVariables = @(
    'VM_USER',
    'VM_HOST',
    'VM_LITERAKI_PHP_FILE_PATH'
)

foreach ($name in $requiredVariables) {
    if (-not $config.ContainsKey($name) -or [string]::IsNullOrWhiteSpace($config[$name])) {
        throw "Missing required .env variable: $name"
    }
}

if ($config['VM_USER'] -match '[\s@]' -or $config['VM_HOST'] -match '[\s@:]') {
    throw 'VM_USER or VM_HOST contains unsupported characters.'
}

$scp = Get-Command 'scp.exe' -ErrorAction SilentlyContinue
if (-not $scp) {
    throw 'OpenSSH scp.exe was not found. Install the Windows OpenSSH Client.'
}

$destination = '{0}@{1}:{2}' -f $config['VM_USER'], $config['VM_HOST'], $config['VM_LITERAKI_PHP_FILE_PATH']
& $scp.Source -- $localFile $destination
if ($LASTEXITCODE -ne 0) {
    throw "scp failed with exit code $LASTEXITCODE."
}

Write-Host 'Deployment completed.'