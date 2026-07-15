# Troubleshooting

## Regra principal

Identifique primeiro o erro observado, o método HTTP usado, a URL acessada e a resposta real. Só depois sugira uma correção.

## Erros HTTP e de rede

### 405 — Método não permitido

Causa provável: método HTTP incorreto. Confirme se o endpoint espera GET, POST ou outro método.

Verificação:

```bash
curl -i 'https://SEU-DOMINIO/api/agent/squad-chat.php?health=1'
curl -i -X POST 'https://SEU-DOMINIO/api/agent/squad-chat.php' \
  -H 'Content-Type: application/json' \
  --data '{"message":"teste"}'
```

### 404 — Não encontrado

Causa provável: caminho incorreto, arquivo ausente, regra de rewrite incorreta ou deploy incompleto. Verifique o arquivo no repositório, o destino do deploy e a URL final.

### 500 — Erro interno PHP

Causa provável: erro de sintaxe, exceção não tratada, include ausente, permissão incorreta, migration com falha ou configuração inválida. Consulte os logs do servidor e execute lint PHP antes de alterar o código.

```bash
php -l caminho/do/arquivo.php
```

### Load failed

Causa provável: CORS, bloqueio do navegador, mixed content, timeout, certificado, extensão de privacidade ou resposta interrompida. Reproduza com `curl -i` para separar problema do navegador de problema do servidor.

### DNS error

Causa provável: domínio sem resolução, registro incorreto, propagação pendente ou nameserver inválido. Verifique DNS antes de alterar a aplicação.

## Integrações

### Tiny/Olist

- `403`: validar versão da API, escopo, token e bloqueio do provedor.
- `invalid_grant` ou token inativo: renovar a autorização OAuth; não inventar token substituto.
- Produtos sem preço: validar credenciais, sincronização, fallback e fonte comercial antes de corrigir apenas a interface.
- Produtos sem imagem: validar URL de origem, download, permissão, formato e vínculo SKU-imagem.

## Deploy

- Commits com `[skip ci]` podem impedir validação e deploy.
- Mudanças em caminhos ignorados pelo workflow podem não disparar publicação.
- Confirme cache, permissões e presença do arquivo atualizado no servidor.
- Nunca declarar deploy concluído apenas porque o merge foi realizado.

## Segurança

- Nunca inserir credenciais em código, issue, commit, log ou documentação.
- GitHub Secrets são write-only.
- Não contornar bloqueios de navegador ou políticas de segurança.
- Não executar deleções FTP destrutivas sem autorização explícita.
