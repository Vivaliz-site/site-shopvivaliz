param(
    [switch]$InstallVSCodeTask
)

$ErrorActionPreference = "Stop"

function Fail($Message) {
    Write-Host "ERRO: $Message" -ForegroundColor Red
    exit 1
}

$repo = git rev-parse --show-toplevel 2>$null
if (-not $repo) {
    Fail "Execute dentro do repositorio."
}

Set-Location $repo

if (-not (Test-Path ".githooks")) {
    New-Item -ItemType Directory -Path ".githooks" | Out-Null
}

git config core.hooksPath .githooks
Write-Host "Hooks instalados: core.hooksPath=.githooks"

if ($InstallVSCodeTask) {
    if (-not (Test-Path ".vscode")) {
        New-Item -ItemType Directory -Path ".vscode" | Out-Null
    }

    $task = @'
{
  "version": "2.0.0",
  "tasks": [
    {
      "label": "Git Auto Sync Safe Pull",
      "type": "shell",
      "command": "powershell",
      "args": [
        "-ExecutionPolicy",
        "Bypass",
        "-File",
        "${workspaceFolder}/scripts/git-auto-sync.ps1",
        "-Apply"
      ],
      "problemMatcher": []
    }
  ]
}
'@
    Set-Content -Path ".vscode/tasks.json" -Value $task -Encoding UTF8
    Write-Host "VS Code task criada em .vscode/tasks.json"
}

Write-Host "Instalacao concluida."
