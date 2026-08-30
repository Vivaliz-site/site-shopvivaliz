$ErrorActionPreference = "Stop"

$sshKey = if ($env:SHOPVIVALIZ_SSH_KEY) { $env:SHOPVIVALIZ_SSH_KEY } else { "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" }
$siteHost = if ($env:SHOPVIVALIZ_SITE_HOST) { $env:SHOPVIVALIZ_SITE_HOST } else { "shopvivaliz-a1-site" }
$server = "ubuntu@$siteHost"
$remoteDir = "/home/ubuntu/site-shopvivaliz"

Write-Host "=============================================" -ForegroundColor Cyan
Write-Host " INICIANDO DEPLOY DIRETO DA SUA MAQUINA" -ForegroundColor Cyan
Write-Host "=============================================" -ForegroundColor Cyan
Write-Host "Destino: $server" -ForegroundColor Cyan
Write-Host ""

# Este script e um fallback manual. O fluxo canonico de producao continua sendo CI/CD.
# O alias/host deve apontar para a VM A1 com papel SITE; nunca use endpoints aposentados.
Write-Host "[1/3] Preparando diretorio remoto no Oracle Cloud..." -ForegroundColor Yellow
ssh -i $sshKey -o StrictHostKeyChecking=no $server "sudo mkdir -p $remoteDir && sudo chown -R ubuntu:ubuntu /home/ubuntu/site-shopvivaliz"

Write-Host "[2/3] Copiando arquivos do projeto..." -ForegroundColor Yellow
scp -i $sshKey -o StrictHostKeyChecking=no -r * "$($server):$($remoteDir)"

Write-Host "[3/3] Aplicando configuracoes e reiniciando servicos..." -ForegroundColor Yellow
ssh -i $sshKey -o StrictHostKeyChecking=no $server "cd $remoteDir && sudo systemctl daemon-reload && sudo systemctl restart shopvivaliz-mcp.service"

Write-Host ""
Write-Host "=============================================" -ForegroundColor Green
Write-Host " DEPLOY CONCLUIDO" -ForegroundColor Green
Write-Host "=============================================" -ForegroundColor Green
