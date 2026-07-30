# Configuração segura de webhooks Olist

Este documento substitui uma versão histórica que continha credencial em texto puro.

## Regras

- Armazene token/assinatura somente em GitHub Secrets ou ambiente protegido.
- Use nome canônico documentado no mapa de integrações.
- Nunca coloque token, assinatura ou payload autenticado em Markdown, logs ou exemplos.
- Valide assinatura no endpoint antes de processar o evento.
- Registre apenas identificadores não sensíveis e códigos de resposta.

## Resposta ao incidente

A credencial anteriormente exposta deve ser considerada comprometida e rotacionada no provedor. O valor não é reproduzido neste documento.
