# DEPLOY-ORACLE.md — Guia Completo de Deployment na VM Oracle

**Data:** 2026-07-25  
**Status:** 🚀 Ativo em Produção  
**Responsável:** DevOps/SRE, Agentes IA  

---

## 📋 Índice

1. [Visão Geral](#visão-geral)
2. [Arquitetura](#arquitetura)
3. [Primeiro Deploy](#primeiro-deploy)
4. [Deploy Automático (Cron)](#deploy-automático-cron)
5. [Releases Imutáveis](#releases-imutáveis)
6. [Shared Data](#shared-data)
7. [Health Check](#health-check)
8. [Rollback](#rollback)
9. [Permissões](#permissões)
10. [Troubleshooting](#troubleshooting)
11. [Procedimento de Emergência](#procedimento-de-emergência)
12. [Reversão Completa](#reversão-completa)

---

## 📖 Visão Geral

**ShopVivaliz** roda em:
- **Host:** 137.131.156.17 (VM Oracle Cloud)
- **SO:** Ubuntu 20.04+ (com kernel recente)
- **Usuário Deploy:** `ubuntu` (membro de sudo)
- **Web Server:** Apache 2.4 + PHP 8.1+
- **Usuário Web:** `www-data`

**Migração (2026-07-25):**
- De: `git merge --ff-only` na árvore viva (com problemas)
- Para: Releases imutáveis com `current` symlink

**Vantagem:** Rollback atômico, auditoria limpa, sem dependência de working tree sujo.

---

## 🏗️ Arquitetura

### Estrutura Pós-Migração

```
/home/ubuntu/shopvivaliz-deploy/

├── repo/                                # Clone Git limpo
│   ├── .git/
│   ├── scripts/
│   │   ├── deploy-production.sh         ← Cron executa isto
│   │   ├── rollback-production.sh
│   │   ├── validate-release.sh
│   │   └── health-check.sh
│   ├── index.php
│   ├── config/
│   ├── public/
│   └── (código-fonte completo)
│
├── releases/                            # Releases imutáveis
│   ├── 20260725-143500-2489d8d9/       ← Release-1 (ativo)
│   │   ├── index.php
│   │   ├── config/
│   │   ├── public/
│   │   └── deploy-time-symlinks/
│   │       ├── .env → ../../shared/.env
│   │       ├── uploads → ../../shared/uploads
│   │       └── logs → ../../shared/logs
│   │
│   ├── 20260725-130000-14fd5ed5/       ← Release-2 (anterior)
│   │   └── ...
│   │
│   └── (máx 5 releases, > 5 = remove)
│
├── shared/                              # Dados persistentes
│   ├── .env                             (600, ubuntu:ubuntu)
│   ├── .env.local (opcional)
│   ├── uploads/                         (usuário uploads)
│   ├── logs/                            (gerado por app)
│   ├── cache/                           (regenerável)
│   ├── sessions/                        (PHP session data)
│   ├── storage/                         (Olist/Tiny/MercadoPago)
│   └── (qualquer outra dado persistente)
│
└── current                              # Symlink atômico
    └── → releases/20260725-143500-2489d8d9/
```

### Apache Configuração

**DocumentRoot aponta para:**
```apache
DocumentRoot /home/ubuntu/shopvivaliz-deploy/current/public
# OU, se sem /public/:
DocumentRoot /home/ubuntu/shopvivaliz-deploy/current/
```

(Verificar `/etc/apache2/sites-available/*.conf`)

---

## 🚀 Primeiro Deploy

Executado **manualmente** via SSH, primeira vez apenas:

```bash
ssh -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" ubuntu@137.131.156.17

# 1. Criar estrutura
mkdir -p /home/ubuntu/shopvivaliz-deploy/{repo,releases,shared}

# 2. Clone limpo
cd /home/ubuntu/shopvivaliz-deploy/repo
git clone https://github.com/Vivaliz-site/site-shopvivaliz .git
git fetch origin main
git checkout main

# 3. Criar release-1
cd /home/ubuntu/shopvivaliz-deploy/releases
RELEASE="20260725-143500-$(git -C ../repo rev-parse --short origin/main)"
mkdir -p "$RELEASE"
(cd ../repo && git archive origin/main) | tar -xf - -C "$RELEASE/"

# 4. Symlinks para shared
cd "/home/ubuntu/shopvivaliz-deploy/releases/$RELEASE"
ln -sf ../../shared/.env .env
ln -sf ../../shared/uploads uploads
ln -sf ../../shared/logs logs
ln -sf ../../shared/cache cache
ln -sf ../../shared/sessions sessions
ln -sf ../../shared/storage storage
# (ajustar conforme estrutura real)

# 5. Ativar
cd /home/ubuntu/shopvivaliz-deploy
ln -sfn "releases/$RELEASE" current.tmp
mv -Tf current.tmp current

# 6. Validar
php -l current/index.php
curl -I http://127.0.0.1

# 7. Apache
sudo apache2ctl configtest
sudo systemctl reload apache2

# 8. Health check
curl https://shopvivaliz.com.br

echo "✅ Primeiro deploy OK"
```

---

## 🔄 Deploy Automático (Cron)

### Crontab

```bash
*/2 * * * * flock -n /var/lock/shopvivaliz-deploy.lock \
  /home/ubuntu/shopvivaliz-deploy/repo/scripts/deploy-production.sh \
  >> /var/log/shopvivaliz-deploy.log 2>&1
```

**Frequência:** A cada 2 minutos  
**Lock:** `flock` impede execução simultânea  
**Log:** `/var/log/shopvivaliz-deploy.log` (rotacionado)

### O Que o Script Faz

1. **Adquire lock (falha rápido se já rodando)**
2. **Fetch:**
   ```bash
   git fetch --prune origin main
   ```
3. **Compara SHA:**
   ```bash
   REMOTE_SHA=$(git rev-parse origin/main)
   ACTIVE_SHA=$(readlink -f current | xargs basename | cut -d- -f3-)
   ```
4. **Se idêntico:** Sai com sucesso (idempotente, sem ação)
5. **Se diferente:**
   - Cria nova release (via `git archive`)
   - Instala symlinks para `shared/`
   - Valida PHP syntax
   - Executa health check local
   - Troca symlink atomicamente:
     ```bash
     ln -sfn "releases/$NEW_RELEASE" current.tmp
     mv -Tf current.tmp current
     ```
   - Executa health check pós-deploy
   - Se OK: mantém release, logra sucesso
   - Se FALHA: restaura release anterior, logra erro
6. **Limpeza:** Remove releases com > 5 (mantém últimas 5)
7. **Libera lock**

---

## 🔒 Releases Imutáveis

### Propriedades

- **Imutável:** Nenhum processo pode modificar `/releases/<ativa>/`
- **Rastreável:** SHA visível em `basename current`
- **Recuperável:** Últimas 5 releases mantidas
- **Auditável:** Cada release tem timestamp + SHA

### Convenção de Nomes

```
20260725-143500-2489d8d9
│         │       │
│         │       └─ SHA curto (7 chars)
│         └───────── Timestamp HHmmss
└─────────────────── Data YYYYMMDD
```

### Criação de Release

```bash
# Opção 1: git worktree (fast, mas deixa worktree)
git -C /home/ubuntu/shopvivaliz-deploy/repo \
  worktree add --detach \
  "/home/ubuntu/shopvivaliz-deploy/releases/$RELEASE" \
  origin/main

# Opção 2: git archive (clean, sem worktree)
(cd /home/ubuntu/shopvivaliz-deploy/repo && \
  git archive origin/main) | \
  tar -xf - -C "/home/ubuntu/shopvivaliz-deploy/releases/$RELEASE"

# Opção 3: Clone local (lento, mas muito isolado)
git -C /home/ubuntu/shopvivaliz-deploy/repo \
  clone --detach . \
  /home/ubuntu/shopvivaliz-deploy/releases/$RELEASE
```

**Script usa: Option 2 (git archive)**

---

## 📦 Shared Data

Dados **persistentes** que não devem estar na release.

### Localização

```
/home/ubuntu/shopvivaliz-deploy/shared/
```

### Conteúdo

| Arquivo/Dir | Descrição | Gerado? | Crítico? |
|-------------|-----------|---------|----------|
| `.env` | Credenciais | Não | SIM |
| `uploads/` | Arquivos usuário | Sim (web) | SIM |
| `logs/` | Histórico app | Sim (app) | Não |
| `cache/` | Cache tempo-real | Sim (app) | Não |
| `sessions/` | Sessões PHP | Sim (app) | Não |
| `storage/` | Dados Olist/Tiny | Sim (daemon) | SIM |
| `.env.local` | Overrides locais | Não | Não |

### Symlinks na Release

Cada release cria symlinks para `shared`:

```bash
cd /home/ubuntu/shopvivaliz-deploy/releases/$RELEASE

ln -sf ../../shared/.env .env
ln -sf ../../shared/uploads uploads
ln -sf ../../shared/logs logs
ln -sf ../../shared/cache cache
ln -sf ../../shared/sessions sessions
ln -sf ../../shared/storage storage
```

### Permissões

```bash
# .env (apenas ubuntu, seguro)
chmod 600 /home/ubuntu/shopvivaliz-deploy/shared/.env
chown ubuntu:ubuntu /home/ubuntu/shopvivaliz-deploy/shared/.env

# uploads (www-data escreve, ubuntu lê)
chmod 755 /home/ubuntu/shopvivaliz-deploy/shared/uploads
chown www-data:ubuntu /home/ubuntu/shopvivaliz-deploy/shared/uploads

# logs (www-data escreve)
chmod 755 /home/ubuntu/shopvivaliz-deploy/shared/logs
chown www-data:ubuntu /home/ubuntu/shopvivaliz-deploy/shared/logs

# sessions (www-data escreve)
chmod 755 /home/ubuntu/shopvivaliz-deploy/shared/sessions
chown www-data:ubuntu /home/ubuntu/shopvivaliz-deploy/shared/sessions
```

---

## ✅ Health Check

Executado **após** cada deploy.

### Local (pré-swap)

```bash
php -l /home/ubuntu/shopvivaliz-deploy/releases/$NEW_RELEASE/index.php
curl -I http://127.0.0.1/admin/  # Endpoint crítico
```

### Pós-Swap

```bash
curl -I https://shopvivaliz.com.br/
curl https://shopvivaliz.com.br/api/status
```

### Se Falhar

```
1. Restaura release anterior (symlink)
2. Recarrega Apache
3. Logra erro completo
4. **NÃO remove** release problemática (para investigação)
5. Retorna código de erro (1)
```

---

## 🔙 Rollback

### Manual (Por SRE/DevOps)

```bash
ssh -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" ubuntu@137.131.156.17

sudo /home/ubuntu/shopvivaliz-deploy/repo/scripts/rollback-production.sh

# Se quiser release específica:
sudo /home/ubuntu/shopvivaliz-deploy/repo/scripts/rollback-production.sh 20260725-130000-14fd5ed5

# Script faz:
# 1. Lista releases
# 2. Valida destino
# 3. Troca symlink atomicamente
# 4. Executa health check
# 5. Se falha: restaura anterior
# 6. Logra resultado
```

### Automático (Deploy Falha)

Se health check pós-deploy falha, script automático:
1. Detecta falha
2. Restaura release anterior
3. Retorna erro

Você perceberá no log.

---

## 🔐 Permissões

### Usuários

```
ubuntu          Deploy owner, repo admin
www-data        Apache + PHP executa
root            Sistema (sudo necessário para algunas ações)
```

### Diretórios

```
repo/           755 ubuntu:ubuntu  (readable by www-data)
releases/       755 ubuntu:ubuntu
current         777 (symlink, permissions ignoradas)
shared/         755 ubuntu:ubuntu
shared/.env     600 ubuntu:ubuntu  (SEGURO)
shared/uploads/ 755 www-data:ubuntu
```

### Comandos

```bash
# Verificar permissões
ls -la /home/ubuntu/shopvivaliz-deploy/
ls -la /home/ubuntu/shopvivaliz-deploy/shared/

# Corrigir (se necessário)
sudo chown -R ubuntu:ubuntu /home/ubuntu/shopvivaliz-deploy/repo/
sudo chown -R ubuntu:ubuntu /home/ubuntu/shopvivaliz-deploy/releases/
sudo chown www-data:ubuntu /home/ubuntu/shopvivaliz-deploy/shared/uploads/
sudo chmod 755 /home/ubuntu/shopvivaliz-deploy/shared/uploads/
```

---

## 🆘 Troubleshooting

### Deploy Bloqueado por Lock

**Sintoma:** Log mostra "lock is being held"

```bash
# Verificar
ps aux | grep deploy-production.sh

# Se processo morto, remover lock (CUIDADO)
sudo rm /var/lock/shopvivaliz-deploy.lock

# Rodar deploy manualmente
/home/ubuntu/shopvivaliz-deploy/repo/scripts/deploy-production.sh
```

### Health Check Falha

**Sintoma:** Rollback automático ocorreu

```bash
# Ver log
tail -100 /var/log/shopvivaliz-deploy.log

# Investigar release problemática
ls -la /home/ubuntu/shopvivaliz-deploy/releases/
php -l /home/ubuntu/shopvivaliz-deploy/releases/<problema>/index.php

# Fixar problema em VS Code + novo commit
# Deploy automático pegará em ~2 min
```

### Arquivo Modificado Bloqueia Deploy

**Sintoma:** "working tree contem alteracoes"

Antigo (pré-migração). Não deve ocorrer mais.

Se ocorrer: clone limpo não tem working tree sujo.

### Symlink current Quebrado

```bash
# Verificar
readlink -f /home/ubuntu/shopvivaliz-deploy/current

# Se mostra nonexistent:
ls -la /home/ubuntu/shopvivaliz-deploy/releases/
# Identifique última release válida

# Restaurar manualmente
ln -sfn releases/<válida> /home/ubuntu/shopvivaliz-deploy/current.tmp
sudo mv -Tf /home/ubuntu/shopvivaliz-deploy/current.tmp \
  /home/ubuntu/shopvivaliz-deploy/current

# Recarregar
sudo systemctl reload apache2
```

---

## 🚨 Procedimento de Emergência

Se produção está **down** e não se consegue contato SSH:

1. **Reboot última vez (Oracle console):**
   - Acesse: https://cloud.oracle.com
   - Encontre instância `137.131.156.17`
   - Reboot de emergência
   - Aguarde SSH responder

2. **Rollback via console:**
   - Se SSH disponível:
     ```bash
     sudo /home/ubuntu/shopvivaliz-deploy/repo/scripts/rollback-production.sh <old-release>
     ```

3. **Restaurar de backup:**
   - Se pior: `/home/ubuntu/backups/site-shopvivaliz-pre-release-migration-<timestamp>/`

---

## 🔄 Reversão Completa

Se precisar voltar à arquitetura **anterior** (com `git merge` vivo):

```bash
# 1. Backup da nova estrutura
tar czf /home/ubuntu/backups/shopvivaliz-deploy-current-$(date +%s).tar.gz \
  /home/ubuntu/shopvivaliz-deploy/

# 2. Restaurar diretório antigo
tar xzf /home/ubuntu/backups/site-shopvivaliz-pre-release-migration-<timestamp>.tar.gz

# 3. Repoint Apache
# Edite: /etc/apache2/sites-available/*.conf
# Mude DocumentRoot para: /home/ubuntu/site-shopvivaliz/

# 4. Reativar cron antigo
crontab -e
# Descomente a linha antiga de git-auto-sync.py

# 5. Recarregar
sudo apache2ctl configtest
sudo systemctl reload apache2

# 6. Validar
curl https://shopvivaliz.com.br

echo "✅ Reversão para arquitetura antiga concluída"
```

**NOTA:** Reverse é **não-trivial**. Evitar se possível.

---

## 📞 Suporte

- **Logs:** `/var/log/shopvivaliz-deploy.log`
- **Estrutura:** `/home/ubuntu/shopvivaliz-deploy/`
- **SSH:** `ubuntu@137.131.156.17` (chave em `C:\Users\FRED\Downloads\`)
- **Documentação:** Este arquivo + `AGENTS.md` + `CLAUDE.md`

---

**Versão:** 1.0  
**Data:** 2026-07-25  
**Status:** ✅ Ativo em Produção  
**Responsável:** Cloud DevOps + Agentes IA
