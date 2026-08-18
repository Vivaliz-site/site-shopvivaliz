# ShopVivaliz - E-commerce e automações auditáveis

Código-fonte do e-commerce ShopVivaliz e de suas integrações, rotinas de qualidade e agentes assistidos por IA.

## Direção de plataforma

O projeto está evoluindo para uma base pronta com **MedusaJS** como backend principal, mantendo o stack PHP atual durante a transição.

### Arquitetura alvo

- **Backend:** MedusaJS
- **Frontend:** Next.js Commerce
- **Banco:** PostgreSQL
- **Cache/fila:** Redis
- **Integrações:** Olist, Tiny e marketplaces

Veja o plano em [`docs/medusa-migracao-roadmap.md`](docs/medusa-migracao-roadmap.md).

## Agentes e automações

Gemini, Claude e ChatGPT podem apoiar análise, implementação e revisão. Nenhum agente está autorizado a concluir tarefas, fazer push no branch protegido ou promover deploy apenas por alterar um estado interno.

A fonte de verdade para automações ativas é o conteúdo atual de [`.github/workflows/`](.github/workflows/). Documentos históricos não substituem runs, logs e artifacts do GitHub Actions.

### Fila canônica

`tasks-queue.json` é um registro operacional em schema 2. A fila legada de execução autônoma foi aposentada e não existe executor horário autorizado que consuma esse arquivo para implementar, commitar ou publicar código automaticamente.

O CLI é somente leitura:

```bash
python3 scripts/manage-tasks-queue.py list
python3 scripts/manage-tasks-queue.py list --status failed
python3 scripts/manage-tasks-queue.py stats
```

Os comandos `add`, `remove`, `mark` e `priority` permanecem reconhecidos apenas para falhar de modo explícito, evitando que integrações antigas alterem a fila silenciosamente.

Mudanças na fila devem ocorrer por pull request revisado. O estado `completed_verified` só é válido com evidência persistida de run, commit, PR, testes, read-back e digest de artifact. O estado simples `completed` não pertence ao schema.

Consulte [`AUTONOMOUS_TRIO_GUIDE.md`](AUTONOMOUS_TRIO_GUIDE.md) para o contrato operacional seguro.

## Estrutura principal

```text
├── tasks-queue.json                 # Registro canônico em schema 2
├── AUTONOMOUS_TRIO_GUIDE.md         # Contrato seguro da fila e dos agentes
├── .github/workflows/               # Workflows ativos e revisáveis
├── scripts/
│   ├── manage-tasks-queue.py        # Inspeção somente leitura
│   ├── task_queue_lib.py            # Validação e escrita atômica do schema
│   └── audit-agents-real-work.py    # Auditoria de agentes/automações
├── api/                             # APIs do e-commerce
├── agents/                          # Componentes de agentes
└── docs/knowledge/                  # Base de conhecimento operacional
```

## Operação segura

- Toda mudança de código ou configuração deve passar por PR e checks.
- Push direto, auto-merge e deploy sem revisão não são evidência de conclusão.
- Runs devem produzir logs e artifacts vinculados ao SHA executado.
- Falha, bloqueio ou ausência de trabalho não deve gerar commit de progresso.
- Credenciais devem vir do ambiente autorizado ou de gerenciador de segredos; nunca de valores fictícios ou arquivos versionados.

## Stack

- **Backend atual:** PHP 8.3 e MySQL
- **IA:** Anthropic, OpenAI e Google Gemini quando configurados
- **Automação:** GitHub Actions e serviços explicitamente documentados
- **Qualidade:** lint, testes de regressão, auditoria de secrets e evidência

## Backend Medusa em desenvolvimento

Há um backend headless MedusaJS + storefront Next.js em `claude/medusa/apps/`, integrado ao site PHP legado via webhook (`claude/api/medusa-webhook.php`) e à sincronização Olist/Tiny ERP. Ainda não está em produção.

Leia [`claude/medusa/README.md`](claude/medusa/README.md) e [`claude/medusa/DEPLOY-CHECKLIST.md`](claude/medusa/DEPLOY-CHECKLIST.md).

## 🧠 Knowledge Base

A documentação completa do sistema está em:
/docs/knowledge/

Utilizada por agentes IA e desenvolvedores para diagnóstico e operação.
