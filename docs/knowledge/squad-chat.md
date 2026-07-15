# Squad Chat

## Endpoint

`/api/agent/squad-chat.php`

## Health check

Use uma requisição GET:

```bash
curl -fsS 'https://SEU-DOMINIO/api/agent/squad-chat.php?health=1'
```

O health check só deve ser considerado válido quando a resposta contiver simultaneamente:

- `ok=true`
- `endpoint=squad-chat`
- campo `providers` presente

Exemplo esperado:

```json
{
  "ok": true,
  "endpoint": "squad-chat",
  "providers": {
    "openai": { "configured": true },
    "anthropic": { "configured": false },
    "gemini": { "configured": true }
  }
}
```

A presença de HTTP 200, isoladamente, não comprova que o endpoint está operacional.

## Chat por POST

Exemplo de requisição:

```bash
curl -fsS -X POST 'https://SEU-DOMINIO/api/agent/squad-chat.php' \
  -H 'Content-Type: application/json' \
  --data '{"message":"Verifique o status do catálogo"}'
```

O payload deve ser JSON e conter a mensagem ou os campos exigidos pela versão atual do endpoint.

## Exemplo de resposta OK

```json
{
  "ok": true,
  "response": "Catálogo analisado com sucesso.",
  "provider": "openai"
}
```

## Exemplo de erro

```json
{
  "ok": false,
  "error": "invalid_request",
  "message": "Campo message é obrigatório."
}
```

Erros devem ser interpretados pelo código e pela mensagem retornados, sem presumir que todo erro é falta de credencial.

## Providers e status `configured`

O campo `configured` informa se a configuração mínima do provider foi encontrada pelo endpoint. Ele não comprova, sozinho, que a credencial está válida ou que o serviço externo respondeu.

Providers esperados podem incluir:

- OpenAI
- Anthropic/Claude
- Google Gemini

Nunca registrar, devolver ou expor os valores das chaves de API. GitHub Secrets são somente gravação e não podem ser recuperados em texto após o cadastro.
