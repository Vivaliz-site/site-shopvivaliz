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
