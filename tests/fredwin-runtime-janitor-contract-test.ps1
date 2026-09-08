$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$script = Join-Path $root 'scripts\fredwin-runtime-janitor.ps1'
$fixture = Join-Path $env:TEMP 'fredwin-runtime-janitor-fixture.txt'
@(
'100|1|node.exe|2026-09-07T18:00:00|"C:\Program Files\nodejs\node.exe" C:\x\desktop-commander\dist\index.js',
'200|100|powershell.exe|2026-09-07T18:10:00|powershell.exe -Command "ssh host"',
'201|100|powershell.exe|2026-09-07T19:55:00|powershell.exe -Command "echo fresh"',
'202|100|powershell.exe|2026-09-07T19:49:00|powershell.exe -Command "stale after ten minutes"',
'203|100|powershell.exe|2026-09-07T19:51:00|powershell.exe -Command "still inside grace"',
'300|99999|chrome.exe|2026-09-07T19:55:00|chrome.exe --headless=new --user-data-dir=C:\Temp\dc-auth-profile',
'400|401|opera.exe|2026-09-07T19:55:00|opera.exe --headless=new --user-data-dir=C:\ShopVivaliz\amazon-returns-bridge\profile',
'401|1|node.exe|2026-09-07T19:54:00|node.exe seller-central-safe-t-read-worker.mjs',
'500|501|chrome.exe|2026-09-07T19:30:00|chrome.exe --headless=new --user-data-dir=C:\Temp\probe-profile',
'501|1|powershell.exe|2026-09-07T19:29:00|powershell.exe -Command "probe"',
'600|100|powershell.exe|2026-09-07T18:00:00|powershell.exe -File C:\site-shopvivaliz\scripts\ssh-tunnel-service-managed.ps1',
'700|99999|bash.exe|2026-09-07T19:40:00|bash.exe -lc "ssh old-job"',
'701|99998|powershell.exe|2026-09-07T19:55:00|powershell.exe -Command "fresh orphan"',
'800|100|opera.exe|2026-09-07T19:30:00|opera.exe --headless=new --remote-debugging-port=9333 --user-data-dir=C:\ShopVivaliz\github-bootstrap-profile-20260907'
) | Set-Content -LiteralPath $fixture -Encoding utf8
if (-not (Test-Path -LiteralPath $script)) { throw 'runtime janitor script is missing' }
$out = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script -DryRun -FixturePath $fixture -Now '2026-09-07T20:00:00')
$joined = $out -join "`n"
if ($joined -notmatch 'WOULD_KILL_STALE_DC_SESSION.*pid=200') { throw 'stale DC session was not selected' }
if ($joined -match 'pid=201') { throw 'fresh DC session was incorrectly selected' }
if ($joined -notmatch 'WOULD_KILL_STALE_DC_SESSION.*pid=202') { throw '11-minute DC session was not selected' }
if ($joined -match 'pid=203') { throw '9-minute DC session was incorrectly selected' }
if ($joined -notmatch 'WOULD_KILL_ORPHAN_HEADLESS.*pid=300') { throw 'orphan headless browser was not selected' }
if ($joined -match 'pid=400') { throw 'live Amazon headless browser was incorrectly selected' }
if ($joined -notmatch 'WOULD_KILL_TEMP_HEADLESS.*pid=500') { throw 'stale temp headless browser was not selected' }
if ($joined -match 'pid=600') { throw 'managed tunnel was incorrectly selected' }
if ($joined -notmatch 'WOULD_KILL_ORPHAN_SHELL.*pid=700') { throw 'stale orphan shell was not selected' }
if ($joined -match 'pid=701') { throw 'fresh orphan shell was incorrectly selected' }
if ($joined -notmatch 'WOULD_KILL_TRANSIENT_HEADLESS.*pid=800') { throw 'stale ShopVivaliz transient headless browser was not selected' }
Write-Output 'RUNTIME_JANITOR_CONTRACT_PASS=true'
