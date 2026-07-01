# Agent Rules

- usar sempre /docs/knowledge como base
- nunca assumir resposta sem evidência
- validar health:
  - ok=true
  - endpoint=squad-chat
  - providers presentes
- sempre buscar credenciais nos GitHub Secrets antes de hardcodar
- GitHub Secrets são write-only — nunca tentar lê-los de volta
- não alterar campos de preço/estoque/logística em automações Shopee/TikTok
- pular produtos sem estoque (out-of-stock/sold-out)
- não tentar bypassar restrições de segurança do Chrome CDP
- não fazer deleções FTP destrutivas sem autorização explícita
- sempre decidir autonomamente dentro do escopo autorizado — não parar para perguntar
