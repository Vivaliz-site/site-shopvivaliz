# Amazon Returns & SAFE-T Recovery — Design Specification

## Objetivo

Construir um subsistema permanente de recuperação financeira para Amazon Brasil capaz de acompanhar devoluções e reembolsos, identificar automaticamente casos elegíveis à SAFE-T, abrir a reivindicação no primeiro dia permitido, monitorar pedidos de informação e decisões, apresentar recurso quando cabível e encerrar o caso somente após conciliação financeira real.

A meta operacional é: **perda evitável causada por falha do sistema = R$ 0,00**.

## Princípios obrigatórios

1. **API oficial primeiro.** Usar Amazon SP-API para pedidos, finanças, notificações e relatórios sempre que houver endpoint oficial aplicável.
2. **Seller Central apenas como adaptador de exceção.** Automação web será isolada e usada apenas para SAFE-T e estados que a SP-API não disponibiliza.
3. **Gmail como sensor redundante e evidência, nunca como única autoridade financeira.**
4. **Controle por pedido, item e quantidade.** Nenhuma decisão será feita somente no nível do pedido se houver múltiplos itens/unidades.
5. **Event sourcing append-only.** Fatos observados não são sobrescritos; novos fatos são adicionados à timeline.
6. **Estado observado separado de estado calculado.** Ex.: Amazon pode mostrar `RETURNING_TO_SELLER` enquanto o recebimento físico interno já é `RECEIVED`.
7. **Nenhum erro técnico é estado terminal.** `FAILED`, `TIMEOUT`, `UNKNOWN`, `SESSION_EXPIRED` ou `CAPTCHA` mantêm o caso ativo.
8. **Idempotência obrigatória.** Nenhum retry pode gerar SAFE-T ou recurso duplicado.
9. **Toda ação de escrita é verificada depois.** Clicar em “Enviar” nunca é suficiente; é necessário confirmar no Seller Central e/ou por evidência posterior.
10. **IA nunca inventa fatos.** Texto de reivindicação/recurso só pode afirmar fatos apoiados por evidências existentes no dossiê.
11. **Políticas versionadas.** Prazos e elegibilidade nunca ficam espalhados como números mágicos pelo código.
12. **Feature flags e kill switch.** Escritas podem ser desligadas sem interromper ingestão, cálculo, conciliação e dashboard.
13. **Rollout progressivo.** Observe → backfill → shadow → execução manual → canary → automação total.
14. **Fluxo Git obrigatório.** Branch → validação real → commit → push → PR → merge; nenhuma mudança direta em `main`.

## Escopo funcional

O sistema cobre, no mínimo:

- autorização de devolução;
- rastreio de devolução;
- recebimento físico;
- quantidade parcial;
- item incorreto, usado, danificado, incompleto ou caixa vazia;
- reembolso ao comprador;
- identificação do iniciador do reembolso;
- débito ao vendedor;
- ressarcimento proativo Amazon;
- elegibilidade SAFE-T;
- abertura SAFE-T;
- pedidos adicionais de informação;
- aprovação/negação;
- recurso;
- crédito financeiro;
- crédito parcial;
- reversão posterior;
- conciliação e encerramento;
- backfill histórico;
- auditoria e exportação financeira.

## Fontes de dados e autoridade

### Amazon SP-API

Usar:

- Orders API v2026-01-01;
- Finances API v2024-06-19;
- Notifications (`TRANSACTION_UPDATE` e notificações aplicáveis);
- Reports para devoluções MFN/FBA e reembolsos;
- Sellers API para marketplace participations.

A atual classe `SvAmazonClient` deve ser extraída do acoplamento com `AmazonPublisher.php` e transformada em cliente comum reutilizável por catálogo e recovery.

### Gmail

O Gmail deve detectar e persistir mensagens Amazon como eventos normalizados, incluindo padrões já observados:

- `REFUND_ISSUED`;
- autorização de devolução;
- SAFE-T registrada;
- SAFE-T atualizada;
- mensagens do Seller Support associadas a pedido/SAFE-T;
- mudanças de política Amazon.

A correlação deve usar Amazon Order ID, SAFE-T ID, SKU/ASIN quando disponíveis e timestamps.

### Seller Central

O adapter deve:

- consultar elegibilidade SAFE-T;
- abrir reivindicação;
- anexar evidências;
- capturar SAFE-T ID;
- ler decisão completa;
- detectar pedido adicional de informação;
- apresentar resposta/recurso;
- confirmar resultado após escrita.

Não usar navegador local visível quando houver alternativa. Preferir host remoto/VM em segundo plano com sessão autorizada.

### Recebimento físico

O admin deve permitir registrar:

- Amazon Order ID;
- order item;
- SKU/ASIN;
- quantidade recebida;
- condição: íntegro, usado, danificado, incompleto, item incorreto, caixa vazia;
- fotos/evidências;
- timestamp;
- operador.

`carrier_delivered = true` nunca implica `physical_received = true`.

## Hierarquia de verdade

Quando houver divergência:

1. transação financeira SP-API para fatos financeiros;
2. estado confirmado no Seller Central para SAFE-T/elegibilidade;
3. Reports/Orders SP-API para devolução/fulfillment;
4. recebimento físico interno para posse material;
5. Gmail como confirmação/evento/evidência;
6. rastreio externo como evidência logística auxiliar.

A divergência deve ser preservada e virar evento, não apagada.

## Modelo de domínio

### `amazon_recovery_cases`

Representa um caso financeiro por pedido/item/motivo.

Campos principais:

- `id`;
- `amazon_order_id`;
- `amazon_order_item_id`;
- `seller_sku`;
- `asin`;
- `quantity_ordered`;
- `quantity_refunded`;
- `quantity_received`;
- `marketplace_id`;
- `fulfillment_channel`;
- `amazon_program`;
- `claim_reason`;
- `refund_at`;
- `refund_initiator`;
- `eligibility_at`;
- `policy_version_id`;
- `safe_t_id`;
- `case_state`;
- `risk_amount`;
- `expected_recovery`;
- `approved_recovery`;
- `received_recovery`;
- `confidence`;
- `requires_human_review`;
- timestamps.

Unique key lógica: pedido + item + evento de reembolso + motivo.

### `amazon_recovery_events`

Append-only:

- `case_id`;
- `event_type`;
- `source`;
- `source_id`;
- `observed_at`;
- `effective_at`;
- `payload_json`;
- `payload_hash`;
- `created_at`.

Unique por `source + source_id + event_type` quando a fonte fornecer ID estável.

### `amazon_recovery_ledger`

Transações financeiras normalizadas:

- customer refund;
- seller debit;
- proactive reimbursement;
- SAFE-T requested;
- SAFE-T approved;
- SAFE-T reimbursement;
- reversal;
- settlement.

Cada lançamento deve guardar IDs Amazon e moeda/valor.

### `amazon_recovery_policies`

Políticas versionadas e efetivas por:

- marketplace;
- programa;
- fulfillment;
- motivo;
- data de vigência;
- base temporal (`refund_at`, `order_date`, etc.);
- dias;
- dias úteis/corridos;
- fonte e referência documental;
- status (`candidate`, `active`, `retired`).

### `amazon_recovery_jobs`

Fila durável no banco principal, não dependente do fallback JSON da fila genérica.

Campos:

- job type;
- case id;
- idempotency key;
- priority;
- available_at;
- lease owner/until;
- attempts;
- status;
- last error;
- timestamps.

### `amazon_recovery_outbox`

A decisão e sua ação externa são persistidas na mesma transação de banco.

### `amazon_recovery_dlq`

Jobs esgotados/erros não transitórios ficam aqui com contexto integral e nunca desaparecem.

### `amazon_recovery_evidence`

Metadados e hash de:

- e-mails;
- snapshots do Seller Central;
- fotos;
- documentos;
- respostas SP-API relevantes;
- protocolos.

## Máquina de estados

Estados principais:

- `OBSERVING`;
- `RETURN_AUTHORIZED`;
- `REFUND_DETECTED`;
- `WAITING_RETURN`;
- `RETURN_RECEIVED`;
- `WAITING_ELIGIBILITY`;
- `SAFE_T_ELIGIBLE`;
- `SAFE_T_SUBMISSION_PENDING`;
- `SAFE_T_SUBMISSION_UNCERTAIN`;
- `SAFE_T_OPEN`;
- `SAFE_T_INFO_REQUIRED`;
- `SAFE_T_APPROVED_WAITING_CREDIT`;
- `SAFE_T_DENIED_APPEAL_PENDING`;
- `SAFE_T_APPEAL_OPEN`;
- `RECONCILIATION_PENDING`;
- `RETURN_RECEIVED_RECONCILED`;
- `AMAZON_PROACTIVE_REIMBURSED`;
- `SAFE_T_RECOVERED`;
- `APPEAL_RECOVERED`;
- `FINAL_LOSS_DOCUMENTED`.

Estados técnicos não terminais são representados como flags/incident events e não substituem o estado de negócio.

## Regras de prazo

### Regra operacional D+45

No D+45 do marco aplicável, todo caso ainda aberto deve passar por reconciliação obrigatória:

1. produto fisicamente recebido?
2. Amazon já ressarciu?
3. SAFE-T já existe?
4. qual programa/fulfillment?
5. qual política estava vigente?
6. Seller Central aceita a reivindicação?

Para fluxos normais elegíveis, D+45 deve resultar em protocolo imediato.

### Exceções

O Policy Engine deve suportar regras específicas, incluindo FBA Onsite/Delivery by Amazon com janela de 60 dias quando aplicável à política vigente desde 21/04/2026.

Mesmo nesses casos, D+45 continua sendo checkpoint obrigatório de reconciliação, porém não deve produzir submissão sabidamente inválida.

### Pedido adicional de informação e recurso

Prazo é calculado pelo Policy Engine com base na regra vigente e no texto/estado observado no Seller Central. Nunca hardcode um único prazo global.

## Pré-check obrigatório antes da SAFE-T

A submissão é bloqueada enquanto não forem resolvidos:

- produto retornou fisicamente;
- Amazon já ressarciu o vendedor;
- reembolso ao comprador não confirmado;
- débito ao vendedor não confirmado quando aplicável;
- SAFE-T já existente;
- motivo duplicado;
- política desconhecida;
- inconsistência material entre item/quantidade/valor.

Casos de baixa confiança vão para revisão humana, não para descarte.

## Decisão e execução separadas

O `DecisionEngine` pode produzir:

- `WAIT`;
- `OPEN_SAFE_T`;
- `RESPOND_INFO_REQUEST`;
- `APPEAL_SAFE_T`;
- `RECONCILE`;
- `REVIEW_REQUIRED`;
- `CLOSE_CASE`.

O `SellerCentralExecutor` apenas executa uma decisão persistida na outbox.

## Idempotência

Idempotency key recomendada:

`amazon_order_id + order_item_id + refund_event_id + claim_reason + action_type`.

Antes de qualquer retry de escrita:

1. consultar o Seller Central;
2. procurar efeito da operação anterior;
3. se confirmado, marcar como sucesso;
4. se incerto, entrar em `SUBMISSION_UNCERTAIN` e bloquear nova escrita automática;
5. só reenviar se houver prova de não submissão.

## Gmail ingestion

O parser básico deve ser determinístico por remetente/subject/communicationName. IA só é permitida para conteúdo não estruturado.

Todo e-mail processado ganha um `source_id = gmail_message_id` e hash do conteúdo relevante.

## Policy Watcher

Monitorar:

- e-mails Amazon;
- Seller Central announcements;
- documentação oficial/Help;
- SP-API changelog;
- respostas oficiais Amazon Seller Forums.

Uma mudança descoberta entra como `candidate`, nunca ativa automaticamente.

Antes da ativação:

1. simular sobre casos históricos;
2. listar decisões alteradas;
3. validar impacto;
4. ativar nova versão;
5. recalcular apenas casos não terminais afetados.

## IA para reivindicações e recursos

A IA recebe somente um `CaseDossier` estruturado e referências de política.

Toda afirmação factual precisa apontar para ao menos uma evidência. Se não houver suporte, a frase não pode ser afirmativa.

Saída deve conter:

- argumento;
- fatos usados;
- evidências referenciadas;
- política usada;
- confiança;
- lacunas documentais.

Casos de baixa confiança ficam em revisão.

## Priorização

Priority score combina:

- deadline restante;
- valor em risco;
- tipo de ação;
- confiança;
- histórico de falhas;
- pedido adicional da Amazon.

Recursos/pedidos adicionais próximos do prazo têm prioridade máxima.

## Observabilidade e saúde

Health separado para:

- SP-API auth;
- Orders;
- Finances;
- Reports;
- Notifications;
- Gmail;
- banco;
- worker;
- Seller Central session;
- executor web;
- DLQ;
- policy watcher.

Gates operacionais:

- casos sem classificação = 0;
- elegíveis sem ação = 0;
- deadline vencido sem tratamento = 0;
- crédito sem conciliação = 0;
- ações Amazon com resultado incerto = 0;
- DLQ não tratada = 0;
- eventos órfãos = 0;
- políticas desconhecidas ativas = 0.

## Segurança

- secrets somente por mecanismo já aprovado no projeto/host;
- nunca logar refresh token/client secret/access token;
- privilégios SP-API mínimos necessários;
- sessão Seller Central separada do navegador pessoal;
- MFA não pode ser contornado;
- snapshots não podem conter dados desnecessários;
- admin protegido pelo guard existente;
- trilha de auditoria por usuário/worker/versão do código.

## Dashboard

Visão principal:

- R$ em risco;
- R$ elegível agora;
- R$ aguardando devolução;
- R$ SAFE-T em análise;
- R$ em recurso;
- R$ aprovado aguardando crédito;
- R$ recuperado no mês;
- R$ perda definitiva;
- devoluções >45 dias;
- casos críticos <24h;
- gates de saúde.

Filtros por SKU, ASIN, pedido, programa, status, motivo e período.

## Backfill

Após ingestores confiáveis:

1. importar histórico de Gmail;
2. importar Orders/Finances/Reports históricos dentro da retenção disponível;
3. correlacionar SAFE-T existentes;
4. reconstruir timelines;
5. classificar casos ainda recuperáveis;
6. nunca abrir automaticamente um caso histórico sem passar pelo mesmo Policy Engine e pré-check.

## Rollout

### Estágio 0 — testes locais

Sem qualquer escrita Amazon.

### Estágio 1 — observe/backfill

Somente leitura e classificação.

### Estágio 2 — shadow decisions

Sistema registra o que faria e compara com Seller Central/operador.

### Estágio 3 — auto-decision/manual execution

Decisão automática; submissão humana.

### Estágio 4 — canary auto-submit

Poucos casos de alta confiança e valor controlado.

### Estágio 5 — full auto-submit

Com circuit breaker, verificação pós-ação e kill switch.

### Estágio 6 — auto-appeal

Somente após corpus histórico e golden cases comprovarem qualidade.

## Critérios de aceite global

O sistema só é considerado pronto quando:

1. backfill e ingestão incremental não geram duplicidade;
2. reembolso automático Amazon é detectado e impede SAFE-T indevida;
3. D+45 gera ação/reconciliação conforme política vigente;
4. regra de 60 dias é reconhecida para programas aplicáveis;
5. recebimento físico cancela o fluxo de “não recebido” e pode gerar motivo alternativo;
6. SAFE-T nunca é duplicada após timeout/retry;
7. negativa e pedido adicional geram deadline e ação prioritária;
8. recurso é factualmente sustentado por evidências;
9. aprovação só encerra após crédito reconciliado;
10. reversão de crédito reabre o caso;
11. queda do worker/VM por 24h não perde eventos nem prazos recuperáveis;
12. restore de backup é testado;
13. todos os gates operacionais podem ser medidos;
14. testes unitários, integração, smoke e E2E aplicáveis passam;
15. branch fica limpa, PR revisado e mergeado em `main` somente após validação real.
