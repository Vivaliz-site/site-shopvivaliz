# Proposta de autorizacao humana: acesso corporativo ChatGPT Atendimento e RDC 2

**Estado: AGUARDANDO REVISAO EXCLUSIVA DE `shopvivaliz-human` NESTA PR DRAFT E CONSENTIMENTO OFICIAL DO PROVEDOR.** Nenhum conector, credencial, RBAC, MFA, sessao ou host e alterado por este documento. O merge da proposta, isoladamente, NAO instala plugin nem autentica conta.

## Evidencia viva sem secrets - 2026-11-01

- No contexto ChatGPT Atendimento, Plugin Management: `Remote Desktop Commander` e `Remote Desktop Commander 2` = `not_installed`; `ShopVivaliz Remote Control` = `found`.
- Perfil dedicado Atendimento CDP 9556: pagina oficial na etapa `/log-in/password`, endpoint de sessao HTTP200 mas `user_present=false`, `identity_match=false`, `NOT_AUTHENTICATED`. Perfil Dev CDP9559 autentica corretamente, mas nao substitui o Atendimento.
- Preflight oficial metadata-only do backend: entrada de credencial Atendimento salva existe, bus do keyring presente, `may_request_password=false`. Nao acessou senha, token, cookie ou TOTP. Nao pedir nova senha ao usuario.
- Servico backend `shopvivaliz-desktop-commander.service` tem historico de exit20 com `AUTH_REQUIRED=true reason=provider_device_flow` ou `provider_device_flow_waiting`; `systemctl active` temporario nao prova concessao OAuth nem operacao RDC efetiva.
- A integracao e continuidade dependem de prova de identidade da conta e de concessao independente pelo provedor. Referencia: issue #2841 e documentacao canonicamente versionada em main.

## Pedido exato (escopo minimo)

**Principal:** conta ChatGPT corporativa `atendimento@shopvivaliz.com.br`, nao o perfil Dev ou a identidade `shopvivaliz-human` como executor.

**Recurso:** conector oficial Remote Desktop Commander 2 / conta de provider autenticada separadamente para Atendimento; perfil Chromium Atendimento isolado no backend.

**Capacidades solicitadas nesta etapa:** instalar e conectar a integracao autorizada da conta Atendimento, consultar `who_am_i` e `list_devices` e executar diagnostico remoto benigno nos dispositivos oficialmente permitidos. Nao pedir nem conceder sudo, comandos destrutivos, administracao irrestrita ou uso de credenciais de Dev. Qualquer elevacao de privilegio exige autorizacao separada conforme o grant GitHub/host.

**Janela de solicitacao:** revisao pretendida ate 2026-11-08; qualquer sessao ou token efetivo respeita TTL e revogacao do respectivo provedor, e nao e criado por esta PR.

### Acao humana/provedor ainda requerida (na propria interface oficial)

1. `shopvivaliz-human` revisar o diff/escopo e validar pessoalmente esta PR Draft; para PR de autorizacao de seguranca, liberar e fazer merge pessoalmente quando gates e protecoes permitirem.
2. Na conta ChatGPT Atendimento, instalar/conectar o **Remote Desktop Commander 2** pelas configuracoes oficiais de Plugins, conferir permissao e consentir exclusivamente via provedor oficial, se solicitado. PR GitHub NAO concede OAuth automaticamente.
3. No perfil corporativo do ChatGPT Atendimento, concluir autenticacao first-party pelo fluxo protegido existente; se a plataforma exigir verificacao/MFA/interacao humana, preencher apenas na pagina oficial, nunca entregar OTP/senha no chat ou ao agente.
4. Preservar perfis, sessoes, cookies e permissao Dev; nao importar/copiá-los para Atendimento.

## Validacao obrigatoria apos concessao

- `browser_auth_status(atendimento)`: `AUTHENTICATED` com identidade exata; Dev continua autenticado independentemente.
- Na propria conta Atendimento, plugin RDC2 instalado/conectado; `who_am_i` e `list_devices` respondem com identity/grant corretos (nao inferir de links Dev).
- Comparar capacidades MCP/RDC 2 e permissoes efetivas em ambas as contas; validar fluxo grafico/controle remoto inofensivo somente no host permitido e sob ownership/lease.
- Continuity `continuity_ready=true` e recuperacao `PROGRESS_CONFIRMED` de conversas reais apenas apos vinculos confiaveis e E2E por sessao; NAO considerar login isolado como conclusao.
- Se provedor negar explicitamente consentimento ou RBAC, respeitar recusa e registrar bloqueio; nao trocar canal para contornar.

## Diff, testes, risco, rollback

**Diff documental somente**; preflight metadata-only, status MCP e auth status reproduziveis, sem alterar runtime/ERP/host. **Risco operacional imediato: nenhum.** Risco futuro: acesso indevido se consentir escopo excessivo; conferir permissoes oficiais. Rollback desta PR: revert do documento. Revogacao de grant externo exige desconectar/revogar nas interfaces do ChatGPT/provedor, e nao e efetuada pelo revert. Nenhuma automacao pode tratar simples merge como acesso tecnico concedido.
