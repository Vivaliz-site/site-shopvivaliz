# OCI Marina / ShopVivaliz — Auditoria Extrema V5 e gate de preservação

> Checkpoint operacional **NÃO APTO — migração ainda não concluída**. Evidências colhidas em 2026-10-17 (UTC). Este documento **não é autorização de corte, exclusão, faturamento ou promoção**; revisão/dados frescos obrigatórios.

## Regras e fonte de verdade

- Base `main`: `b45dfa8eee1bdc1b72c540f12e81b1bfbf9ecb89`.
- Leituras documentais realizadas: `docs/knowledge/atendimento-chatgpt-bootstrap.md` (blob `5ae4e46`), `docs/knowledge/host-access.md` (`4961630`), `docs/knowledge/README.md` (`21ba97d`), `docs/knowledge/agent-rules.md` (`fe1afe1`), `AGENTS.md` (`0ea10a4`), `AUDIT_POLICY.md` (`db4e334`), `docs/knowledge/browser-sessions.md` (`e4d5623`), `docs/knowledge/shopvivaliz-graphical-session-recovery.md` (`26e7a9b`) e `docs/ops/oci-dual-account-access.md` (`151727f`).
- Operação: **não terminar/deletar `shopvivaliz-free-a1`, sua boot volume, backups ou qualquer outro recurso** sem aprovação específica e gate completo. Não duplicar workers, runners ou movimentações comerciais.
- Controle de persistência: `/home/ubuntu/migration-marina-a1/STATUS.md` e `rules_loaded_20261017.txt` na controladora; cópia de checkpoint de migração em `/home/ubuntu/migration-checks/STATUS.md` na Marina. Confirmar atualização antes do corte.
- Oracle oficial, [Always Free](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm), atualizado 2026-06-12: 1.500 OCPU-horas e 9.000 GB-horas mensais para Ampere A1, aproximadamente 2 OCPUs + 12 GB contínuos por tenancy. **Não presumir 4/24 gratuito.** Validar modalidade/consumo/faturamento/quotas em cada tenancy antes de aumentar recursos.

## Inventário por OCI CLI (2026-10-17 UTC)

| Conta | Instância | Shape | OCPUs | RAM | Disco (raiz) | Papel atual |
| --- | --- | --- | ---: | ---: | ---: | --- |
| PRINCIPAL | `always-free-arm-1787907847-26` | A1.Flex | 2 | 16 GB | 96 GB | Controladora: MCP, navegadores corporativos, relay, rotinas backend |
| PRINCIPAL | `shopvivaliz-free-a1` | A1.Flex | 2 | 8 GB | 96 GB | **Produção de origem: manter** |
| ESPOSA | `marina-a1-4c24g` | A1.Flex | 4 | 24 GB | 193 GB utilizáveis | Destino pré-corte, ainda sem workers produtivos |

Todas as três instâncias estavam `RUNNING` no OCI, `sa-saopaulo-1`. O destino possui sistema ARM64 e disco livre de aproximadamente 178 GB. A controladora antiga apresentava aproximadamente **91% de disco**, swap 6/6 GB ocupado e dois serviços failed (`mei-mg-email-worker`, `shopvivaliz-login-integrity`), pendentes de causa raiz/remediação.

## Provas já executadas — não equivalem a APTO

1. Cópias preliminares de `shopvivaliz-deploy`, Amazon Returns, ML Pricing e MLRR efetuadas. Symlink imutável `current` na origem e no destino apontava para `releases/20261016-022648-9896bf9b` (SHA `9896bf9baf85766ea30708c95983da5f640c3312`); Amazon Returns `20261016T110242Z-4eb2111fba18`. Deploy **não é automaticamente igual** ao `main` atual; rastrear versões antes de aprovar.
2. SHA-256 dos backups transferidos compatíveis (MySQL nativo, SQL de Pricing, dumps físicos Supabase, MLRR e MEI). Conferência estrutural inicial MySQL nativo: 102 tabelas `shopvivaliz`, 94 `shopvivaliz_baseline_20260829`, 16 `amazon_returns_safet`, 8 `amazon_returns_safet_preverify_39_20260905`, coincidentes nas duas VMs.
3. Restaurações PostgreSQL isoladas iniciaram e responderam: Supabase 103 tabelas, MLRR 19, MEI 8; contagens coincidentes com origem. Containers de validação parados para evitar writes duplicados. Backup SQL ML Pricing contém 27 definições de tabelas da aplicação; o MySQL isolado inicia, porém **root restaurado rejeita credencial temporária** (1045): consulta às tabelas físicas e consistência de transações ainda não concluídas. Não contornar recusa de ferramenta ao modificar credenciais.
4. Após reparos de ACL, PHP-FPM e configuração de staging, rotas locais da Marina responderam HTTP 200 para loja, login de Amazon Returns e `/v1/health` do ML Pricing. HTTP 200 não prova transações, webhooks, autenticação ou fornecedores.
5. Na interface **pública da origem**, navegador real abriu loja, adicionou produto ao carrinho e navegou ao checkout **sem pedido/pagamento**, além de abrir login do Returns. Ainda **não** há teste UI E2E do destino, de carrinho/checkout completo ou de Seller Central.
6. RustDesk 1.4.9 e RDC 0.2.52 instalados no destino com identidades distintas. RDC novo aguarda autorização `AUTH_REQUIRED`; RDC 2 ainda precisa de vínculo elegível; Tailscale instalado `NeedsLogin`. SSH/controladora → Marina provado com host key verificada. Chave existente em `Downloads/ssh-key-2026-07-04.key` no laptop possui fingerprint compatível com chave pública autorizada (nenhuma chave privada foi copiada).
7. ShopVivaliz Remote Control MCP central possui cadastro servidor do novo host, mas o conector ChatGPT listava somente quatro hosts: discovery/cache/refresh requer teste. Controladora reportou `continuity_ready=false`, `degraded=true` e release ativo `3f47ea0...` divergente do `main` `b45dfa8...`; **não atualizar sem respeitar lease/ownership da controladora**.
8. Definições systemd ausentes foram staged e registradas **inativas** na Marina. Binários oficiais GitHub Actions Runner 2.337.0 ARM64 instalados em dois diretórios, **sem** reutilizar identidades `.runner`. Runners ainda não registrados, workers não habilitados. Um setup de agente de continuidade depende de `gemini.env` separado/seguro.
9. GitHub CI: runs recentes do site, Amazon Returns, pipeline e MEI tiveram sucessos pontuais. MLRR apresentou falha em último workflow observado no `main` de 2026-09-29 (run `36511773369`, erro no setup do runner `governed-mlrr-ci`); requer revalidação fresca e reparo de runner/checks. Alguns jobs Solange foram skipped; investigar elegibilidade antes de inferir defeito.

## Gates obrigatórios para mudança de tráfego

- [ ] Comprovar modalidade OCI, quotas e ausência de custos não autorizados; **não redimensionar**.
- [ ] Tratar falhas e saturação de disco/swap no backend com preservação de dados, red/green e regressão.
- [ ] Reconciliar dados recentes por banco, fila, idempotency key e evento; validar backup/restauração e integridade referencial.
- [ ] Provar contas/perfis Dev e Atendimento no CDP correto, autenticação oficial, MFA e GUI, sem importar sessões.
- [ ] Tailscale/RDC/RDC2/RustDesk/MCP funcional, verificado após recuperação, sem identidade duplicada.
- [ ] Executar `scripts/production-functional-audit.sh` no release alvo com credenciais já provisionadas de modo protegido; validação real de UI em browser do backend.
- [ ] Validar SAFE-T primeiro (FBA=Onsite/Seller Flex), MLRR, ML Pricing, OKX somente leitura financeira, e outros sistemas aplicáveis, evitando efeito duplicado.
- [ ] Regenerar certificados HTTPS restantes na Marina e validar renovações, Cloudflare tunnels, webhooks, DNS, callbacks e acesso externo.
- [ ] Validar GitHub checks, runners, releases, provas de deploy e merges conforme branch protections.
- [ ] Realizar pré-sincronização final, congelar escritores na origem com reversão, transferir ownership e revalidar tudo após cutover e reboot controlado.
- [ ] Confirmar ausência de consumidores da origem e ensaiar rollback. **Nunca deletar a origem sem autorização explícita posterior**, mesmo que gate APTO passe.

**Veredito provisório:** MIGRAÇÃO=**NÃO APTO**; UI/E2E destino, automações, MCP+RDC, sincronização final e custos=**NÃO VALIDADOS**. O trabalho está em curso; não registrar como concluído.
