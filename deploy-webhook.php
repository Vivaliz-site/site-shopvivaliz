<?php
// Desativado em 2026-08-18 (Rodada 2 de melhoria contínua): segundo caminho
// de deploy, paralelo ao oficial (cron `deploy-production.sh` na VM Oracle,
// releases imutáveis + troca de symlink `current` — ver CLAUDE.md). Confirmado
// ao vivo que estava inerte (falha fechada com 503 "DEPLOY_SECRET não
// configurado" antes de qualquer exec()/escrita), mas o risco latente era
// sério: se DEPLOY_SECRET fosse configurado um dia, o script faria `git pull`
// via exec() com GITHUB_TOKEN embutido na URL (vaza pro git config e pro log),
// ou copiaria arquivo por arquivo por cima do webroot — escrevendo direto
// dentro do release imutável servido pelo symlink `current`, o que quebra o
// modelo de releases do deploy-production.sh (o release passa a divergir do
// SHA que declara). Ver relatório da Rodada 2 de melhoria contínua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Gone.\n";
