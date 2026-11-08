# P2 — autorização humana para ativar abertura automática SAFE-T D+45

**Data:** 2026-11-08. **Estado:** SOLICITAÇÃO PARA REVISÃO HUMANA; **NÃO ATIVADA**.
**Repositório de operação:** `Vivaliz-site/amazon-returns-safet`.
**Host:** `marina-a1-4c24g`; **principal:** `amazon-returns-safet-service`; **tenant/conexão:** 1/1.

## Decisão solicitada ao proprietário

Autorizar, **uma única vez**, o provisionamento e a ativação controlados do executor de abertura automática de SAFE-T no D+45 para a ShopVivaliz, com operação futura `activate_d45_safe_t_automation`. A automação pode tentar **consultar a elegibilidade a partir do D+45** (45 dias completos do `refund_at`), **nunca substituir internamente D+45 por D+60**. A abertura efetiva na Amazon ocorre **somente** se a tela oficial da Seller Central confirmar a elegibilidade naquele pedido, na data indicada pelo provedor, e com os controles financeiros e de duplicidade abaixo. Se a Amazon exigir aguardar até data posterior, registrar o bloqueio oficial, aguardar e retomar na data solicitada.

**Efeito de aprovar:** autorização P2 para preparar/implantar o executor seguro, sua identidade, o ledger e o mecanismo de rollback e, uma vez certificado, permitir abertura automática **somente** de SAFE-T nos pedidos elegíveis do tenant/conexão especificados durante o prazo do grant. A aprovação **não equivale** a um envio, instalação de papel ou desligamento imediato do kill switch. O adaptador produtivo da nova operação ainda precisa ser implementado, revisado, integrado e validado antes de ativação. Não interpretar o grant como aplicável ao core atual `activateScopedSafeTOpening` de #3556/#3673 sem mudança de código certificada.

**Novo privilégio, distinto dos existentes:** os grants humanos **#3556** (vence 09/11/2026 23:59 UTC) e **#3673** (vence 30/11/2026 23:59 UTC) mantêm suas listas fixas e uso único. Esta solicitação **não renova, altera, mistura, consome nem reabre** nenhum dos dois. A nova permissão temporária cobre casos futuros do mesmo tenant que alcancem D+45 dentro da validade, sem exigir uma aprovação humana por pedido, desde que todos os controles sejam comprovados. Não reutilizar grants já consumidos, expirados ou em quarentena.

## Limites expressos do novo grant

- **Validade exclusiva:** até **2026-11-30 23:59 UTC**; revogável pela retirada do documento do `main`. **Ativação uma única vez** por este grant; no máximo **20 reivindicações externas novas** dentro da validade e **5 por dia UTC**, com contador durável independente do processo. Sem reativação silenciosa após crash.
- **Programa permitido:** apenas `FBA_ONSITE` (Seller Flex) ou `DELIVERY_BY_AMAZON` quando o canal SAFE-T oficial estiver disponível; outros programas/danos ou discrepância física exigem revisão/rota apropriada.
- **Faixa interna:** D+45 a D+89, a partir do reembolso ao comprador; D+90 encerra primeira abertura. O primeiro **check** ocorre no D+45 mesmo que Amazon ainda não aceite o protocolo.
- **Ação externa única:** `SAFE_T_SUBMIT`. Os outros seis canais permanecem OFF: `SAFE_T_APPEAL`, `SAFE_T_EMAIL_REVIEW`, `SAFE_T_EMAIL_REPLY`, `SELLER_SUPPORT_OPEN`, `SELLER_SUPPORT_UPDATE`, `ERP_SALES_RETURN_CREATE`. Nunca desligar apenas o kill switch diante do perfil padrão amplo.
- **Escopo:** exclusivamente ShopVivaliz tenant 1/conexão 1 na Marina, pelo principal restrito. Seleção dinâmica de casos futuros passa pela mesma consulta transacional e filtragem; não autoriza nenhum outro tenant, canal ou vendedor.
- **Proibições absolutas:** #8803 tem reivindicação externa existente `64204-51742-6364222`; não submeter de novo. Casos com protocolo SAFE-T ou suporte ativo não podem ganhar segunda reivindicação. #13210 tem histórico de suporte e depende de reconciliação oficial. Casos com iniciador `UNKNOWN` não podem ser enviados sem confirmação independente do reembolso iniciado pela Amazon.
- **Critérios antes de cada envio:** prova autenticada de pedido e item exatos, `refund_at`, Amazon iniciadora, programa, saldo de débito ainda não compensado pelas Finances, nenhum `PHYSICAL_RECEIVED` da fonte `WAREHOUSE`, inexistência de reivindicação/suporte duplicado na Seller Central (nunca inferida de lista incompleta), elegibilidade oficial e quantidade correta. Crédito parcial não substitui conferência do saldo.
- **Serialização e persistência:** usar **somente** o worker/browser gerenciado existente; FLOCK/lease único compartilhado, fencing monotônico e idempotência durável por pedido/devolução; não criar navegador, timer, runner ou outbox concorrente. Consumir autorização de ativação uma vez e registrar cada tentativa de claim antes do clique irreversível. Resultado ambíguo entra em quarentena, sem retry automático.
- **Proteção de escrita:** kill switch `ON` em repouso; durante a tentativa autorizada, perfil restrito somente `SAFE_T_SUBMIT=true`; restaurar `ON` mesmo em falha. Instalar watchdog de crash/SIGKILL/reboot antes de qualquer write; não liberar o lease se rollback não puder ser confirmado.
- **Readback:** cada submissão só conta como aceita após ID externo oficial confirmado de forma independente; reconciliação monetária é separada, nunca presumida. Manter auditoria por caso, sem PII e sem segredos.

## Pré-condições técnicas obrigatórias e teste de aceitação

1. Verificar PR `APPROVED` por **`shopvivaliz-human` no HEAD exato**, merge autorizado, arquivo de grant imutável sob `security/owner-approvals/<NUMERO>.json`, vigência, principal e SHA do deploy; nenhuma aprovação em chat substitui o evento GitHub.
2. Criar em **PR técnica separada** o adaptador real de `activate_d45_safe_t_automation`, integrado ao serviço canônico e ao `ScopedSafeTGrantConsumption`/ledger MySQL ou storage transacional equivalente sob migração com backup e teste de restore. Não adaptar por flags falsamente reportadas como autorização.
3. Provisionar por operador de sistema legitimamente autorizado a identidade efetiva `amazon-returns-safet-service`, RBAC mínimo, credencial de verificação GitHub somente leitura, lease/fencing e rollback crash-safe. A recusa atual ao `systemctl start amazon-returns-seller-central-browser.service` (`Interactive authentication required`) **não** é anulada por esta PR; usar somente caminho oficial que aceite o grant.
4. Executar RED/GREEN, testes de ausência de claim, janela D+45/D+90, D+60 negado pelo provedor, casos `UNKNOWN`, suporte existente, #8803, múltiplas instâncias, consumo único, rollback `SIGKILL`/reboot, limite diário/total, expiração e UI E2E Seller Central sem compra/pagamento.
5. Depois do merge/CI do código, deploy **imutável** pelo timer canônico, readback do SHA, checklist por caso e primeiro canário real elegível; prova do ID SAFE-T pela Seller Central. Se algum gate falhar, `NAO_APTO`, sem envio e com motivo no incidente [#532](https://github.com/Vivaliz-site/amazon-returns-safet/issues/532).

## Evidências e risco

- A release de produção `2b3782ce946b12c8c09dd25fa2eeb50fc9b16730` contém a correção contra duplicação #613, mas **não** tem adaptador produtivo/certificado de abertura automática.
- Em 08/11, health público `DEGRADED`, 17 blockers e sete write gates OFF; serviço Amazon sob `www-data` e principal dedicado ainda sem vínculo efetivo. A tentativa de `start` do browser foi recusada por autenticação administrativa; não houve envio SAFE-T.
- **Risco P2:** ações comerciais irreversíveis perante a Amazon e privilégio persistente por prazo. O proprietário está optando expressamente pela autorização temporária de casos **futuros** que passarem nos gates; a automação não pode interpretar D+45 como permissão para contrariar o provedor.
- **Rollback:** desativar operação no grant, parar somente o executor autorizado por sua rota oficial (sem derrubar workers alheios), manter kill switch ON, preservar ledger/tentativas e reconciliar qualquer envio de resultado incerto antes de reativar.

## Revisão exclusiva

Esta PR é **Ready for review**, com solicitação nominal a `shopvivaliz-human`; só essa conta deve registrar `APPROVED` após conferir o diff final. Somente o proprietário pode realizar o merge administrativo ou habilitar o auto-merge nativo quando autorizado por regras vigentes. O agente **não** aprova, não habilita auto-merge e não faz merge desta PR. A criação ou a mera aprovação sem merge não ativa operações nem altera direitos de terceiros.
