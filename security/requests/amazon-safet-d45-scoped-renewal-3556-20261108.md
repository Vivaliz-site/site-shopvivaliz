# P0 Amazon Returns: renovação do grant específico #3556 sem data de expiração (08/11/2026)

**NÃO AUTORIZA PRODUÇÃO POR SI SÓ.** Solicitação de revisão formal de segurança exclusivamente por `shopvivaliz-human`.

## 1. Causa comprovada e objetivo

O grant formal humano #3556 (`security/owner-approvals/3556.json`), aprovado por `shopvivaliz-human` e integrado na PR #3556, concedeu permissão restrita de uso único para a operação `activate_scoped_safe_t_opening` na Marina, cobrindo o canary de 8 casos do tenant 1 / conexão 1. Aquele grant possui expiração programada para **09 de novembro de 2026, às 23h59 UTC** (`expires_at_utc: 2026-11-09T23:59:00Z`).

Por determinação expressa do proprietário em 08/11/2026 ("Prazo crítico: o grant específico #3556 expira em 9 de novembro de 2026, às 23h59 UTC — faça renovação sem data de expiração"), esta solicitação reemite a autorização para o mesmo escopo restrito original, **sem data de expiração**.

O verificador canônico de concessões da ShopVivaliz (`scripts/validate-owner-pr-grant.py`, linha 318) suporta explicitamente concessões sem campo `expires_at_utc` aprovadas pelo proprietário:
> *"Owner-approved non-expiring grant. Revocation remains by later edit on main (immutable_grant_still_active) and by the human-only merge gate."*

## 2. Escopo exato e invariantes da renovação

- **Recurso:** `amazon-returns:safet:marina`
- **Principal técnico:** `amazon-returns-safet-service`
- **Host de execução:** `marina-a1-4c24g`
- **Repositório alvo:** `Vivaliz-site/amazon-returns-safet`
- **Tenant ID:** 1
- **Amazon Connection ID:** 1
- **Capacidades:** `["safe_t_submit"]`
- **Operação:** `activate_scoped_safe_t_opening`
- **Casos autorizados (canary fixo idêntico ao #3556):** `[1263, 1314, 8803, 13210, 51429, 101669, 101670, 101671]`
- **Ações de escrita autorizadas:** somente `["SAFE_T_SUBMIT"]` (as outras seis ações permanecem estritamente negadas)
- **Uso único:** `one_use: true`
- **Data de expiração:** NENHUMA (`expires_at_utc` omitido)
- **Guardrails obrigatórios mantidos integralmente:**
  - `owner_approved_head_and_merged_pr`
  - `exact_host_tenant_connection_and_case_allowlist`
  - `warehouse_physical_receipt_absent`
  - `refunded_by_amazon_confirmed`
  - `seller_financial_credit_unrecovered`
  - `official_seller_central_eligibility_confirmed`
  - `no_existing_safe_t_or_duplicate_support`
  - `external_claim_readback_and_idempotency`
  - `lease_fencing_and_rollback`

## 3. O que está explicitamente fora do escopo

- Sem ampliação de casos além dos 8 IDs originais.
- Sem alteração dos grants #3673 ou #3830 (mantêm seus escopos e vigências próprios).
- Sem concessão de credenciais, chaves, sudo, root ou bypass de kill switch.
- Não autoriza claims onde houver recebimento físico `WAREHOUSE PHYSICAL_RECEIVED` ou quando o reembolso não tiver sido iniciado pela Amazon.
- Casos marcados com iniciador `UNKNOWN` continuam exigindo confirmação externa confiável antes de qualquer submissão.
- Casos com claim preexistente (como #8803) continuam expressamente proibidos de sofrer duplicidade.

## 4. Requisitos de aprovação e processo formal

1. O documento de concessão `security/owner-approvals/<PR_NUMBER>.json` será anexado a esta PR após a atribuição do número pelo GitHub, mantendo vínculo estrito entre arquivo e PR.
2. A aprovação exige revisão `APPROVED` formal exclusivamente pela conta humana proprietária `@shopvivaliz-human` no HEAD final deste PR.
3. Nenhum agente pode aprovar, simular aprovação, acionar auto-merge não autorizado ou fazer merge administrativo desta autorização.
4. O merge da PR é condição necessária, mas não suficiente para envio: o operador no host de destino permanece obrigado a validar grant, escopo, RBAC, single-writer lease/fencing e readback de idempotência antes de qualquer efeito externo.
