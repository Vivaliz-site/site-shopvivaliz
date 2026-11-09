# Arquitetura Canônica de Paridade Total: MCPs e Quatro Hosts

**Data de formalização:** 2026-11-08  
**Diretriz do Proprietário:** Paridade integral entre todos os MCPs e os quatro hosts da infraestrutura ShopVivaliz.

---

## 1. Princípio Fundamental

Todos os MCPs operacionais da ShopVivaliz devem disponibilizar o mesmo catálogo funcional e capacidade administrativa autorizada nos quatro hosts ativos, respeitando os adaptadores específicos de cada sistema operacional (Linux vs. Windows), sem funções exclusivas por máquina ou restrições baseadas no papel do equipamento.

Nenhum dos quatro hosts deve ser limitado pelo papel que exerce na operação: produção, backend ou estação de trabalho são responsabilidades operacionais, não razões para retirar funções do MCP.

---

## 2. Topologia dos Quatro Hosts Ativos

| Host | Sistema Operacional | Superfície GUI Desktop | Rota de Execução Administrativa | Papel Operacional |
| :--- | :--- | :--- | :--- | :--- |
| **Backend OCI** (`always-free-arm-1787907847-26`) | Linux (Ubuntu ARM) | Xvfb display isolado `:97` (1440x900) | Local shell / `sudo` com auditoria | Controller 24x7, relay, browser Dev/Atendimento |
| **Marina OCI** (`marina-a1-4c24g`) | Linux (Ubuntu ARM) | Xvfb display isolado `:99` (1280x800) | Private SSH / Root broker com JWT | Produção web, deploys, banco de dados, workers |
| **Fred-Win** | Windows 11 | Bridge nativo interativo (PowerShell / Win32 API) | Reverse SSH canônico / PowerShell | Estação de trabalho do operador humano |
| **KOCEPSV** | Windows 10/11 | RustDesk target / bridge nativo | Reverse SSH / RustDesk / PowerShell | Estação de suporte e automação |

> **Nota:** A VM legada `shopvivaliz-free-a1` permanece terminalmente excluída/aposentada e nunca é destino, rota ou fallback.

---

## 3. Catálogo Universal de Capacidades

Todos os MCPs oferecem suporte às capacidades universais autorizadas:

1. **Shell / PowerShell / Root:** Execução síncrona e tarefas duráveis em background (`admin_command_run`, `task_submit`, `task_status`).
2. **Desktop GUI Completo:** Mouse, teclado, captura gráfica e verificação de saúde da interface (`desktop_health`, `desktop_open`, `desktop_screenshot`, `desktop_click`, `desktop_type`).
3. **Browser / Login / MFA:** Navegação isolada, controle de abas, preenchimento seguro de MFA/TOTP via stdin protegido (`browser_open`, `browser_auth_action`, `browser_auth_status`).
4. **Arquivos / Upload / Download:** Leitura e listagem de diretórios com proteção contra vazamento de credenciais (`file_read`, `file_list`, `logs_tail`).
5. **Serviços / Docker / Sistema:** Inspeção e gestão de unidades systemd e containers Docker (`service_status`, `service_action`, `processes_list`).
6. **Bancos / APIs / Integrações:** Acesso seguro a APIs autorizadas (Tiny ERP, Mercado Livre, Google Ads, GA4) com sanitização de segredos.
7. **GitHub / CI / Deploy:** Pipelines de validação, merge queue e governança proporcional.
8. **OCI / Cloudflare / Redes:** Gestão de túneis Cloudflare, firewalls e conectividade segura.
9. **Jobs / Automação / Recuperação:** Handoff durável em background, autorreconciliação de tarefas indeterminadas.
10. **Auditoria V5 / Logs:** Registro imutável de ações em auditoria local e sanitização estrita de dados sensíveis (`audit_recent`).
11. **Administração dos MCPs:** Reconciliação, promoção de release e saúde dos adaptadores.

---

## 4. Matriz de Paridade Obrigatória

Todos os pontos de entrada devem conseguir administrar todos os quatro hosts:

| MCP / Módulo | Backend (`always-free-arm-1787907847-26`) | Marina (`marina-a1-4c24g`) | Fred-Win | KOCEPSV |
| :--- | :---: | :---: | :---: | :---: |
| **Remote Control Central** (`remote-control-mcp`) | ✓ | ✓ | ✓ | ✓ |
| **Orquestrador** | ✓ | ✓ | ✓ | ✓ |
| **Browser Control** (`remote-control-browser-mcp`) | ✓ | ✓ | ✓ | ✓ |
| **Admin Operations** | ✓ | ✓ | ✓ | ✓ |
| **Independente Marina** (`marina-direct-mcp`) | ✓ | ✓ | ✓ | ✓ |

*Legenda: `✓` = capacidade implementada e suportada pela malha multi-host.*

---

## 5. Implementação Técnica

1. **`remote-control-mcp/server.py`:**
   - `DESKTOP_HOSTS = ("Fred-Win", "KOCEPSV", "always-free-arm-1787907847-26", "marina-a1-4c24g")`
   - Ferramentas `desktop_health`, `desktop_open`, `desktop_screenshot`, `desktop_click` e `desktop_type` aceitam os quatro hosts em seu enum de schema.
   - Marina classificada com papel `production web/deploy/database/workers/admin-gui`.

2. **`remote-control-browser-mcp/server.py`:**
   - `LINUX_GUI_HOSTS` contém tanto o Backend (`:97`, 1440x900) quanto a Marina (`:99`, 1280x800).
   - Roteamento transparente de cliques, digitação e capturas gráficas via display Xvfb isolado em Linux, e bridges nativos/RustDesk em Windows.

3. **Governança e Segurança:**
   - Preservadas integralmente todas as checagens de autenticação (Bearer tokens, JWTs de Cloudflare Access), sanitização de segredos em logs e contratos de aprovação formal.
