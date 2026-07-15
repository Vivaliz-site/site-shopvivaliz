# Oracle Dev Environment - ShopVivaliz

Documento central de infraestrutura para validacao e operacao segura do ambiente Oracle/Ubuntu do projeto ShopVivaliz.

## Objetivo

Preparar e documentar o servidor Oracle/Ubuntu para desenvolvimento remoto, auditoria continua, monitoramento de logs e operacao segura dos agentes.

## Escopo validado

- Projeto esperado: `/home/ubuntu/site-shopvivaliz`
- Usuario operacional esperado: `ubuntu`
- Branch principal: `main`
- Repositorio: `fredmourao-ai/site-shopvivaliz`
- Acesso recomendado: VS Code Remote SSH
- Code-server: nao recomendado por padrao; instalar somente se houver necessidade real de acesso via navegador/celular, com senha forte, HTTPS e firewall.

## Checklist de validacao do sistema

Executar no Oracle/Ubuntu:

```bash
lsb_release -a || cat /etc/os-release
whoami
pwd
cd /home/ubuntu/site-shopvivaliz
git status
git branch --show-current
git pull origin main
```

## Checklist da stack

```bash
git --version
gh --version
php -v
composer --version
node -v
npm -v
python3 --version
```

## Dependencias recomendadas

Instalar somente itens ausentes:

```bash
sudo apt update
sudo apt install -y git curl unzip zip php-cli php-curl php-mbstring php-xml php-mysql composer nodejs npm python3 python3-pip
```

## VS Code Remote SSH

Recomendacao principal:

1. Instalar VS Code no computador local.
2. Instalar extensao Remote SSH.
3. Conectar ao servidor Oracle via chave SSH.
4. Abrir a pasta `/home/ubuntu/site-shopvivaliz`.

Diretrizes de seguranca:

- usar chaves SSH;
- evitar senha simples;
- nao copiar chaves privadas para o repositorio;
- nao registrar IPs, senhas ou tokens em arquivos versionados;
- manter portas de desenvolvimento fechadas externamente.

## code-server

Nao instalar por padrao.

So instalar se Frederico confirmar necessidade de acesso via navegador ou celular.

Se instalado:

- usar senha forte;
- usar HTTPS;
- restringir firewall/IP quando possivel;
- criar servico systemd;
- guardar senha fora do repositorio;
- nao expor `.env` nem credenciais.

## Logs operacionais

Comandos uteis:

```bash
tail -f /var/log/apache2/error.log
tail -f /home/ubuntu/site-shopvivaliz/logs/watchdog.log
tail -f /home/ubuntu/site-shopvivaliz/logs/dev-agent.log
```

## Scripts auxiliares

- `scripts/audit-env.sh`: auditoria rapida de ambiente, recursos e seguranca basica.
- `scripts/tail-logs.sh`: menu simples para acompanhar logs comuns.

## Guardrails

- Nao expor `.env`, tokens, cookies, chaves privadas ou credenciais.
- Nao alterar precos.
- Nao publicar campanhas.
- Nao aumentar orcamento.
- Nao apagar dados de producao.
- Nao fazer deploy destrutivo.
- Nao sobrescrever arquivos sem backup ou commit.

## Resultado esperado

O ambiente deve permitir que Frederico e os agentes:

- abram o projeto via VS Code Remote SSH;
- acompanhem logs em tempo real;
- rodem auditorias locais;
- validem dependencias essenciais;
- criem branches e PRs seguros;
- mantenham o Oracle pronto para desenvolvimento autonomo auditavel.
