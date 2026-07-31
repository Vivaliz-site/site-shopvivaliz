# Pull Request: ShopVivaliz

## 📋 Descrição

Descreva brevemente o que esta PR faz:

<!-- Exemplo:
Migra deploy de git-merge vivo para releases imutáveis com symlink current.
Adiciona scripts deploy-production.sh e rollback-production.sh.
Implementa lock e health check automáticos.
-->

---

## 🎯 Tipo de Mudança

- [ ] 🐛 Bug fix (correção de bug)
- [ ] ✨ Feature (nova funcionalidade)
- [ ] 📚 Docs (documentação)
- [ ] ♻️ Refactor (refatoração sem mudança de comportamento)
- [ ] 🔧 Chore (manutenção, dependências)
- [ ] 🚀 Deploy (scripts de deploy/infra)

---

## 🔒 Segurança Obrigatória

- [ ] ✅ Nenhum arquivo `.env` com valores reais
- [ ] ✅ Nenhuma chave SSH privada
- [ ] ✅ Nenhum token API/GitHub/Anthropic/etc.
- [ ] ✅ Nenhuma credencial FTP/DB/Stripe/etc.
- [ ] ✅ Rodei `grep -r "password\|secret\|token\|key" .` e validei

---

## ✅ Checklist Técnico

- [ ] ✅ Lint PHP rodou sem erros (`php -l *.php`)
- [ ] ✅ Testes existentes passaram (se houver)
- [ ] ✅ Scripts shell validados (`shellcheck *.sh`)
- [ ] ✅ Python compilado (`python -m py_compile *.py`)
- [ ] ✅ Reviei `git diff` completo
- [ ] ✅ Commits têm mensagens claras e atômicas

---

## 📦 Deploy

**Afeta produção?**
- [ ] Não
- [ ] Sim (descrever abaixo)

**Se SIM:**
- [ ] ✅ Documentado em `docs/DEPLOY-ORACLE.md`
- [ ] ✅ AGENTS.md atualizado
- [ ] ✅ Rollback testado (descrever como)
- [ ] ✅ Health check validado
- [ ] ✅ Backup de dados considerado (se aplicável)

**Comando de rollback (se deploy):**
<!-- Exemplo:
```bash
sudo /home/ubuntu/shopvivaliz-deploy/repo/scripts/rollback-production.sh
```
-->

---

## 🗄️ Banco de Dados

**Afeta schema/dados?**
- [ ] Não
- [ ] Sim (descrever abaixo)

**Se SIM:**
- [ ] ✅ Migration criada
- [ ] ✅ Reversão testada
- [ ] ✅ Backup executado antes

---

## 🧪 Testes

**Que testes rodar?**
<!-- Exemplo:
1. Push para main
2. Aguardar cron de 2 min
3. SSH e validar /current/ aponta para nova release
4. Testar https://shopvivaliz.com.br
5. Testar rollback manual
-->

---

## 🔗 Links Relacionados

- [ ] CLAUDE.md atualizado (se aplicável)
- [ ] AGENTS.md atualizado (se aplicável)
- [ ] KNOWN_ISSUES.md atualizado (se novo problema descoberto)
- [ ] Link para issue (se houver): #___

---

## 📝 Notas Adicionais

<!-- Qualquer coisa que não encaixe acima -->

---

**Assinado por:** @<!-- seu-user -->  
**Timestamp:** <!-- será preenchido na criação -->

---

> 🤖 Gerado para ShopVivaliz Autonomous Development  
> Revisor: Human Lead / Senior DevOps  
> Critério: Zero Tolerância para Secrets + Deploy Seguro
