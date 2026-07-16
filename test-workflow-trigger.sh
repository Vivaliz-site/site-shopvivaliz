#!/bin/bash

echo "============================================"
echo "Diagnostico: Por que workflows nao disparam"
echo "============================================"
echo ""

echo "1. Verificar se .github/workflows existe:"
ls -la .github/workflows/ | head -5
echo ""

echo "2. Listar workflows disponiveis:"
find .github/workflows -name "*.yml" -type f | wc -l
echo "workflows encontrados"
echo ""

echo "3. Verificar last commit:"
git log --oneline -1
echo ""

echo "4. Verificar status do repo:"
git status --short | head -5
echo ""

echo "5. Verificar se ha branches:"
git branch -a
echo ""

echo "CONCLUSAO:"
echo "- Workflows devem disparar automaticamente em push"
echo "- Precisamos verificar GitHub Actions configuracoes"
echo "- Ou disparar workflow manualmente"

