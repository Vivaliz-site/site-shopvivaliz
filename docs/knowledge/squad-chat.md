# Squad Chat

Endpoint: /api/agent/squad-chat.php

## Health check
?health=1

## Método
POST

## Exemplo OK
{ "ok": true, "response": "message received" }

## Exemplo erro
{ "ok": false, "error": "invalid_request" }

## Providers
configured: openai, claude, gemini

## Secrets necessários
- ANTHROPIC_API_KEY
- OPENAI_API_KEY
- GEMINI_API_KEY
