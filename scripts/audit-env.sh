#!/usr/bin/env bash
set -euo pipefail

ROOT="/home/ubuntu/site-shopvivaliz"

echo "== ShopVivaliz Oracle Environment Audit =="
date -Is

echo "\n== Sistema =="
(lsb_release -a 2>/dev/null || cat /etc/os-release || true)

echo "\n== Usuario e diretorio =="
whoami
pwd

if [ -d "$ROOT" ]; then
  echo "\n== Repositorio =="
  cd "$ROOT"
  git status --short || true
  git branch --show-current || true
else
  echo "\nERRO: diretorio do projeto nao encontrado: $ROOT"
fi

echo "\n== Versoes =="
for cmd in git gh php composer node npm python3; do
  if command -v "$cmd" >/dev/null 2>&1; then
    echo "-- $cmd"
    "$cmd" --version 2>&1 | head -n 3 || true
  else
    echo "-- $cmd: NAO INSTALADO"
  fi
done

echo "\n== Recursos =="
free -h || true
df -h / || true
uptime || true

echo "\n== SSH falhas recentes =="
if [ -r /var/log/auth.log ]; then
  grep -i "failed password\|invalid user\|authentication failure" /var/log/auth.log | tail -n 20 || true
else
  echo "auth.log indisponivel ou sem permissao de leitura"
fi

echo "\n== Fim da auditoria =="
