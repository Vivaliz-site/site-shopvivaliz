# Configuração segura de providers e MCP

## Princípio

Nenhum provider deve usar uma chave padrão compartilhada, endpoint remoto sem TLS ou comando remoto arbitrário. Credenciais pertencem ao secret store e a autorização deve ser limitada por ferramenta e operação.

## Variáveis canônicas

```text
MCP_SERVER_URL=<ENDPOINT_HTTPS_APROVADO>
MCP_API_KEY=<SECRET_PROTEGIDO>
OPENAI_API_KEY=<SECRET_PROTEGIDO>
ANTHROPIC_API_KEY=<SECRET_PROTEGIDO>
GEMINI_API_KEY=<SECRET_PROTEGIDO>
```

## Cliente seguro

```python
import os
import httpx

response = httpx.get(
    os.environ["MCP_SERVER_URL"],
    headers={"X-API-Key": os.environ["MCP_API_KEY"]},
    timeout=15,
)
response.raise_for_status()
```

## Requisitos

- HTTPS com certificado válido;
- autenticação protegida;
- allowlist de ações e caminhos;
- sem shell arbitrário exposto;
- logs redigidos;
- rate limit e auditoria;
- artifact com request ID e resultado, nunca com a credencial.

Os endpoints e chaves padrão anteriormente publicados foram removidos. Caso tenham sido utilizados, devem ser revogados, rotacionados e removidos do histórico de forma coordenada.
