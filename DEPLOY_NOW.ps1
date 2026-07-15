$ErrorActionPreference = "Stop"

$sshKey = "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key"
$server = "ubuntu@137.131.156.17"
$remoteDir = "/home/ubuntu/site-shopvivaliz"

Write-Host "=============================================" -ForegroundColor Cyan
Write-Host " INICIANDO DEPLOY DIRETO DA SUA MAQUINA" -ForegroundColor Cyan
Write-Host "=============================================" -ForegroundColor Cyan
Write-Host ""

# 1. Cria a pasta no servidor caso não exista
Write-Host "[1/3] Preparando diretorio remoto no Oracle Cloud..." -ForegroundColor Yellow
ssh -i $sshKey -o StrictHostKeyChecking=no $server "sudo mkdir -p $remoteDir && sudo chown -R ubuntu:ubuntu /home/ubuntu/site-shopvivaliz"

# 2. Copia todos os arquivos do site
Write-Host "[2/3] Copiando arquivos do projeto (isso pode levar alguns minutos)..." -ForegroundColor Yellow
# Usando scp. O "-r" copia recursivamente.
scp -i $sshKey -o StrictHostKeyChecking=no -r * "$($server):$($remoteDir)"

# 3. Reinicia os serviços remotamente
Write-Host "[3/3] Aplicando configuracoes e reiniciando servicos..." -ForegroundColor Yellow
ssh -i $sshKey -o StrictHostKeyChecking=no $server "cd $remoteDir && sudo systemctl daemon-reload && sudo systemctl restart shopvivaliz-mcp.service"

Write-Host ""
Write-Host "=============================================" -ForegroundColor Green
Write-Host " DEPLOY CONCLUIDO COM SUCESSO! 24/7 ONLINE!" -ForegroundColor Green
Write-Host "=============================================" -ForegroundColor Green
