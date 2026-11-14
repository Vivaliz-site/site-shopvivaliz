# Auditoria do fluxo final de negativas Amazon/SAFE-T — 14/11/2026

## Objetivo e escopo

Este registro documenta a verificação operacional das negativas de SAFE-T recebidas no arquivo auditado pelo operador. O recorte de negócio é sempre a janela móvel dos últimos 90 dias; o documento não amplia esse período nem reclassifica casos fora dele.

Foram considerados os oito registros cujo campo `SAFE-T claim state` no CSV estava como `Negado`. A etapa final exigida é uma comunicação externa verificável: revisão por e-mail quando aplicável ou a rota equivalente de Seller Support, seguida de acompanhamento até resposta/fechamento.

## Resultado

**Status do fluxo: APTO.**

Os oito casos não ficaram abandonados após a negativa:

- quatro possuem evento `SAFE_T_EMAIL_REVIEW_SENT` no Gmail, com `outbox_id`, thread e mensagem registrados;
- quatro seguiram a rota oficial de Seller Support, com abertura/atualização concluída e respostas/status da Amazon observados;
- após a execução do ciclo oficial do navegador Seller Central, a fila de outbox ficou sem itens `PENDING`;
- o serviço principal `amazon-returns-safet.service` permaneceu ativo e habilitado.

APTO significa que o fluxo executou sua última ação e registrou o desfecho. Não significa que toda negativa virou reembolso: uma negativa final pode permanecer como perda legítima depois de esgotada a via de contestação.

## Casos auditados

| Pedido | SAFE-T | Resultado da etapa final |
|---|---|---|
| `702-5349464-0245862` | `12472-25597-6629839` | `SAFE_T_EMAIL_REVIEW_SENT` comprovado |
| `702-4764960-2141837` | `12797-64249-3531034` | `SAFE_T_EMAIL_REVIEW_SENT` comprovado |
| `701-8088549-4857851` | `81342-77571-9071754` | `SAFE_T_EMAIL_REVIEW_SENT` comprovado |
| `702-4847212-7165801` | `45092-65513-7280005` | `SAFE_T_EMAIL_REVIEW_SENT` comprovado |
| `702-1808735-0608208` | `37495-60041-8543443` | Seller Support aberto, atualizado e acompanhado até resposta |
| `701-5943790-1901801` | `55570-50553-7163228` | Seller Support aberto e resposta observada |
| `702-9494128-8106647` | `86180-95811-5216439` | Seller Support aberto e resposta observada |
| `702-7802983-5785045` | `27845-46811-9805451` | Seller Support aberto e resposta observada |

## Regra de roteamento

O motor gera `SAFE_T_EMAIL_REVIEW` para a revisão detalhada de uma negativa final. O scheduler pode normalizar essa ação para `SELLER_SUPPORT_OPEN` ou `SELLER_SUPPORT_UPDATE`, porque a comunicação operacional com a Amazon é feita pelo Seller Central. Portanto, a ausência de um novo e-mail Gmail isolado não prova falha quando existe evidência equivalente de Seller Support.

### Invariante contra regressão

Uma decisão `APPEAL_DENIED_FINAL` **nunca pode voltar para `SAFE_T_APPEAL`**. Ela deve abrir ou atualizar a revisão detalhada por Seller Support, que é a rota operacional equivalente ao e-mail final. Essa regra é coberta por `tests/amazon-returns-safet-decision-test.php` e deve permanecer verde em qualquer alteração feita por outro agente.

O fluxo não deve:

- reabrir cegamente um SAFE-T já existente;
- duplicar uma contestação ou mensagem externa;
- marcar caso como concluído sem evidência de envio e leitura/resposta;
- desabilitar escrita enquanto existirem prazos de contestação;
- tratar `SUPERSEDED` como falha: esse status indica que uma decisão mais nova substituiu a ação anterior de forma idempotente.

## Validação de produção

Validações executadas em 14/11/2026, na Marina:

1. `amazon-returns-safet.service`: `LoadState=loaded`, `ActiveState=active`, `SubState=running`, `UnitFileState=enabled`.
2. Ciclo oficial `amazon-returns-seller-central-browser.service`: terminou com `Result=success`.
3. Antes do ciclo havia cinco itens vencidos `SELLER_SUPPORT_READ`; depois do ciclo:
   - `PENDING`: `0`;
   - `SUCCEEDED`: `17.434`;
   - `SUPERSEDED`: `622`.
4. As flags de escrita permaneceram habilitadas no ambiente de produção (`AMAZON_RETURNS_SAFE_T_WRITE=1` e `AMAZON_RETURNS_APPEAL_WRITE=1`); nenhum valor foi alterado por esta documentação.

## Operação contínua

O recorte de auditoria continua sendo os últimos 90 dias. Para cada nova negativa dentro desse período, o agente deve:

1. localizar o caso e confirmar que não existe uma ação externa equivalente já concluída;
2. verificar prazo e elegibilidade da contestação;
3. abrir ou atualizar a ação pelo scheduler/outbox oficial, sem escrita SQL manual;
4. confirmar o envio no Gmail ou no Seller Support;
5. acompanhar respostas, reprocessar somente ações idempotentes e fechar apenas com desfecho comprovado;
6. registrar ERP/financeiro quando houver reembolso ou devolução gerada.

## Limitações da evidência

Este documento é um snapshot operacional datado. Os identificadores de Gmail/Seller Central e os contadores de fila devem ser reconsultados em nova auditoria; não são uma garantia permanente de estado futuro. Nenhuma credencial, token, OTP ou conteúdo sensível foi incluído.
