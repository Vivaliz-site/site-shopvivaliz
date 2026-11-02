# Pedido P0 — habilitação restrita de abertura SAFE-T no D+45 (Amazon Returns)

**Data UTC:** 2026-11-02. **Host:** `marina-a1-4c24g`. **Repositório alvo:** `Vivaliz-site/amazon-returns-safet`. **Tenant/conexão:** 1/1 (ShopVivaliz).

## Intenção do proprietário e regra temporal

O proprietário reiterou em 02/11/2026: **abrir a primeira tentativa SAFE-T no D+45 contado do reembolso ao comprador**, não no D+60. A política interna atual `RETURN_NOT_RECEIVED_D45_REFUND_V2` e o `SafeTExternalWindow.php` do release em produção já aplicam D+45. A elegibilidade efetiva da Amazon é verificada na UI oficial e eventual recusa/prazo informado é respeitado e registrado; **não** modificar UI, prazo ou resposta da Amazon para forçar apresentação.

Desconsiderar casos com mais de 90 dias do reembolso e preservar encerramentos já realizados. Verificar antes de cada abertura: reembolso real iniciado pela Amazon (não pelo vendedor), ausência de recebimento físico oficial `PHYSICAL_RECEIVED` da fonte `WAREHOUSE`, saldo financeiro reconciliado, inexistência de SAFE-T/suporte equivalente aberto, prazo válido, programa correto e readback do identificador oficial. Não duplicar.

## Evidência de produção na data

Release ativo da Marina: `811ac95874e18fdd89bbf087e764bbe6ba15cd04`. Consulta SQL de produção identificou **8 casos sem ID SAFE-T**, idade 45 a 69 dias, sem `WAREHOUSE PHYSICAL_RECEIVED`, sem crédito vendedor registrado, soma nominal ainda não recuperada **R$ 717,87**.

| Case ID | Pedido | Saldo R$ | Última situação / pré-condição |
|---:|---|---:|---|
| 13210 | 702-4837920-7734605 | 20,46 | iniciador UNKNOWN; há protocolos Seller Support 22487298051 e 22515611011; reconciliar e confirmar iniciador ANTES de reivindicar |
| 1314 | 702-9188918-3998665 | 70,46 | iniciador UNKNOWN; Gmail registra reembolso sem identificar quem o iniciou; revisão manual/API obrigatória |
| 8803 | 701-4874743-0697843 | 408,35 | AMAZON_CUSTOMER_SERVICE, 6 unidades; SAFE_T_SUBMIT PENDING, erro anterior UI_DRIFT SAFE_T_SUBREASON_OPTION_MISSING |
| 1263 | 702-2491487-8294621 | 36,38 | AMAZON_INITIATED, DELIVERY_BY_AMAZON; SAFE_T_SUBMIT PENDING, erro anterior UI_DRIFT SAFE_T_ITEM_SELECTION_NOT_ACCEPTED |
| 51429 | 701-5332015-4639469 | 43,10 | iniciador UNKNOWN; elegibilidade e emissor a confirmar, sem SAFE-T |
| 101669 | 701-6883818-0162606 | 50,94 | AMAZON_CUSTOMER_SERVICE, D+45 atingido 02/11, sem SAFE-T |
| 101670 | 702-8677593-5822614 | 67,30 | AMAZON_CUSTOMER_SERVICE, D+45 atingido 02/11, sem SAFE-T |
| 101671 | 702-0022195-8465072 | 20,88 | AMAZON_CUSTOMER_SERVICE, D+45 atingido 02/11, sem SAFE-T |

**Dado crítico:** atualmente `AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH=1` e `deploy/write-profile.json` autoriza todos os canais quando o kill switch é retirado; `AMAZON_RETURNS_WRITE_CANARY_CASE_IDS` está vazio. **É proibido apenas desativar o kill switch**: isso ativaria todos os sete canais e todos os pedidos. Os sete write flags efetivos foram observados OFF; a autenticação Seller Central por leitura está OK, mas nenhuma submissão ocorreu nesta tarefa.

## Autorização solicitada neste PR (somente após aprovação humana formal e merge)

Conceder uma operação de manutenção **temporária** ao operador protegido de Amazon Returns na Marina para ativar **exclusivamente SAFE_T_SUBMIT**, só para os oito IDs acima e com período máximo até 09/11/2026 23:59 UTC, respeitando todas as verificações específicas. As três situações com iniciador UNKNOWN **não** estão automaticamente autorizadas a enviar sem confirmação confiável da origem. Reenvios de casos #8803/#1263 devem reusar a mesma entrada de outbox/idempotency, apenas após verificar que nenhuma claim foi efetivamente registrada pela Amazon.

Escopo técnico de ativação: (1) gerar perfil versionado temporário com `SAFE_T_SUBMIT=true`, **todos os demais canais false**; (2) fixar `AMAZON_RETURNS_WRITE_PROFILE_FILE` no perfil restrito validado; (3) definir explicitamente `AMAZON_RETURNS_WRITE_CANARY_CASE_IDS` com os oito IDs; (4) somente após conferir as duas condições e grant validado, desligar o kill switch; (5) reler flags efetivas como `www-data`: apenas SAFE_T_SUBMIT=true; (6) executar no bridge serial com ownership/lock, limite de prazo e idempotência; (7) readback real do ID SAFE-T/resultado ou bloqueio oficial para cada caso, e conciliar Finances; (8) restaurar kill switch ON após o ciclo, sem esperar sete dias.

O grant **não** executa uma reivindicação e **não** muda as permissões da Amazon ou da VM. O executor necessita autorização efetiva, lease/fencing e conferência humana nos casos de ambiguidade material. Um botão de Amazon indisponível/401/403/MFA/inelegibilidade deve ser tratado como bloqueio do provedor, não contornado.

## Requisitos de aceitação e rollback

- A conta exclusiva `shopvivaliz-human` deve revisar `APPROVED` no SHA exato do PR, merge pessoal ou auto-merge aprovado conforme política vigente.
- Before-write: confirmar live refund timestamp D+45 a D+89, mesma conexão/tenant, saldo >0 na Finances, `WAREHOUSE PHYSICAL_RECEIVED=0`, nenhum SAFE-T anterior e case selecionado na allowlist.
- CI/regressões devem provar kill switch/flag/canary; dry-run do perfil restrito deve emitir seis canais false e SAFE_T_SUBMIT true, com negativa para caso fora da allowlist.
- Priorizar #8803 (R$408,35), depois #1263 (R$36,38) e #101669/#101670/#101671; abrir apenas quando a Amazon de fato permitir e confirmar o número.
- Se qualquer pré-condição, aprovação ou readback falhar: não enviar, restaurar kill switch ON; registrar categoria da falha, evidência e nova ação segura.
- Não modificar dados financeiros, credenciais, políticas de prazo externas, claims anteriores, outros canais, ERP nem a VM excluída.
- Comprovar pós-ativação: ID SAFE-T e status oficial por pedido, controles de escrita OFF ao encerrar e crédito real reconciliado separadamente.
