param(
    [string]$Site = "http://test1.local",
    [string]$WordPressPath = "C:/wamp64/www/hosts/test1.local",
    [int]$IntervalMilliseconds = 500
)

$sec = (& wp --path=$WordPressPath --skip-themes --skip-plugins=persian-woocommerce option get mobo_core_security_code 2>$null | Select-Object -Last 1).Trim()
if ([string]::IsNullOrWhiteSpace($sec)) {
    throw "mobo_core_security_code was not found."
}

$headers = @{ "X-SEC" = $sec }
Write-Host "Monitoring the atomic upgrade status endpoint. Press Ctrl+C to stop."

while ($true) {
    try {
        $stamp = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
        $state = Invoke-RestMethod `
            -Method Get `
            -Uri "$Site/wp-json/mobo-core/v1/upgrade/status?_=$stamp" `
            -Headers $headers `
            -TimeoutSec 10

        $barrier = $state.upgradeBarrier
        $locks = @()
        if ($null -ne $barrier.blockingLocks) {
            if ($barrier.blockingLocks -is [System.Collections.IDictionary] -or $barrier.blockingLocks -is [PSCustomObject]) {
                $locks = @($barrier.blockingLocks.PSObject.Properties.Name)
            } elseif ($barrier.blockingLocks -is [System.Array]) {
                $locks = @($barrier.blockingLocks | ForEach-Object { [string]$_ })
            }
        }

        Write-Host ("{0} | Upgrade={1} | Version={2} | Barrier={3}/{4} | Drain={5} | Install={6} | Locks={7}" -f `
            (Get-Date -Format "HH:mm:ss.fff"),
            $state.status,
            $state.currentVersion,
            $barrier.active,
            $barrier.status,
            $barrier.drainCompletedAt,
            $barrier.installStartedAt,
            ($(if ($locks.Count) { $locks -join ',' } else { '-' })))
    }
    catch {
        Write-Host ("{0} | ERROR: {1}" -f (Get-Date -Format "HH:mm:ss.fff"), $_.Exception.Message) -ForegroundColor Red
    }

    Start-Sleep -Milliseconds $IntervalMilliseconds
}
