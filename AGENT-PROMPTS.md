# Prompts seguros para agentes

## Regra obrigatória

Agentes não recebem chaves, senhas ou tokens em prompts, arquivos, URLs ou exemplos versionados. A autenticação deve ocorrer por secret protegido e o valor nunca pode aparecer em logs ou artifacts.

## Chamada autenticada

Use apenas variáveis de ambiente no processo responsável pela integração:

```python
import os
import requests

endpoint = os.environ["AGENT_API_URL"]
api_key = os.environ["AGENT_API_KEY"]
response = requests.get(
    endpoint,
    headers={"X-API-Key": api_key},
    timeout=15,
)
response.raise_for_status()
```

```javascript
const endpoint = process.env.AGENT_API_URL;
const apiKey = process.env.AGENT_API_KEY;

const response = await fetch(endpoint, {
  headers: { "X-API-Key": apiKey },
});

if (!response.ok) throw new Error(`HTTP ${response.status}`);
```

## Evidência

Uma execução só pode ser marcada como concluída quando existirem:

- exit code real;
- identificador de requisição redigido;
- artifact ligado ao run;
- commit SHA ou PR quando houver alteração de arquivo;
- read-back quando houver operação externa.

Exemplos antigos com endpoint público e chave padrão foram removidos. Qualquer valor anteriormente utilizado deve ser revogado e rotacionado.
