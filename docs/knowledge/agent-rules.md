# Regras para Agentes

## Fonte de conhecimento

- Sempre usar `/docs/knowledge/` como base inicial para diagnóstico e operação.
- Confirmar o comportamento no código, workflow, log ou resposta real quando a documentação não for suficiente.
- Nunca assumir uma resposta sem evidência.
- Informar claramente quando a evidência estiver incompleta, ambígua ou desatualizada.

## Diagnóstico

- Identificar o erro antes de sugerir a solução.
- Registrar método HTTP, URL, status, corpo da resposta e etapa do fluxo afetada.
- Não tratar 404, 405, 500, CORS e DNS como o mesmo problema.
- Não declarar que produção, deploy, banco, preço, imagem ou integração estão corretos sem teste verificável.

## AUDITORIA_FUNCIONAL_PRODUCAO

- HTTP 200 nao prova funcionamento. Arquivo presente, endpoint existente, secret configurado, schema valido ou pagina renderizada sao apenas evidencias estruturais.
- Auditoria local, lint, unit test e smoke estrutural nunca podem declarar a loja funcional em producao.
- Para declarar producao funcional, executar `scripts/production-functional-audit.sh` contra `https://shopvivaliz.com.br` e exigir `PRODUCTION_FUNCTIONAL_AUDIT=PASS`.
- A auditoria obrigatoria deve usar produto real disponivel e percorrer, no minimo: catalogo, carrinho, cotacao real de frete, checkout, health de pedidos e integracoes criticas.
- Melhor Envio, Olist e Mercado Pago so podem ser considerados saudaveis quando o provider real aceitar a credencial e responder ao probe funcional previsto.
- `configured=true`, token presente ou variavel de ambiente preenchida nao provam autenticacao nem funcionamento.
- qualquer falha critica deve produzir FAIL. Nao converter falha critica em `attention`, `warning`, sucesso parcial ou nota percentual capaz de resultar em status saudavel.
- Se a auditoria funcional nao puder ser executada por falta de credencial, conectividade ou ambiente, o resultado e INCONCLUSIVO/FAIL, nunca PASS.
- Relatorios devem separar explicitamente `STRUCTURAL`, `INTEGRATION`, `FUNCTIONAL` e `TRANSACTIONAL`.
- Nenhum agente, workflow, monitor, Claude, Codex ou automacao pode substituir o gate funcional por verificacao superficial.

## Validação do Squad Chat

Considerar o health válido somente quando todos os requisitos forem atendidos:

- `ok=true`
- `endpoint=squad-chat`
- campo `providers` presente

O campo `configured` indica configuração detectada, mas não prova que a credencial foi aceita pelo provider.

## Credenciais e segurança

- Sempre usar variáveis de ambiente ou GitHub Secrets.
- GitHub Secrets são write-only; nunca tentar recuperá-los em texto.
- Nunca hardcodar, registrar ou exibir senhas, tokens, chaves de API ou dados bancários.
- Não contornar políticas de segurança do navegador, CORS, autenticação ou controles de acesso.
- Não executar deleções destrutivas em FTP ou banco sem autorização explícita e backup.

## Catálogo e integrações

- Não inventar preço, estoque, frete, imagem ou disponibilidade.
- Não alterar campos comerciais em automações de anúncios sem evidência da fonte oficial.
- Ignorar ou sinalizar produtos sem estoque conforme a regra do canal.
- Vincular imagens por identificador confiável, preferencialmente SKU ou ID da origem.
- Distinguir falha de interface de falha de sincronização ou ausência de dados.

## Atualizações

- Produzir atualizações cumulativas para permitir pular versões intermediárias.
- Incluir automaticamente SQLs, migrations e reparos de vínculo necessários.
- Tornar migrations idempotentes e registrar as que foram executadas ou ignoradas.
- Executar preflight, backup, cópia, migrations, reparos e testes na mesma atualização.
- Não exigir abertura manual de links para concluir a instalação.
- Fazer merge apenas quando as alterações estiverem consistentes e validadas.

## Autonomia

Tomar decisões autônomas dentro do escopo autorizado, mas interromper ações destrutivas, irreversíveis ou sem evidência suficiente. Autonomia não substitui validação.
