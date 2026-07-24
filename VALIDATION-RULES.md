# 🔍 Regras de Validação Obrigatória

> **CRÍTICO:** Estas regras DEVEM ser seguidas ANTES de qualquer afirmação sobre o status do site.

## ✅ Regra #1: SEMPRE validar no navegador ANTES de responder

**Quando:**
- Fizeste mudanças de CSS/styling
- Dizendo que algo está "pronto"
- Claimando que deploy funcionou
- Afirmando que interface está correta

**Como validar:**
```bash
# Opção 1: Testar via curl (conteúdo)
curl -s https://shopvivaliz.com.br/ | grep "navbar\|#0b4f88"

# Opção 2: Testar direto no servidor (sem cache)
ssh -i key.pem ubuntu@IP "curl -s http://localhost/ | head -100"

# Opção 3: Captura de screenshot (melhor!)
# Enviar screenshot atual do navegador do usuário
```

**Validar:**
- ✅ Navbar tem cor azul (#0b4f88)
- ✅ Página não está branca
- ✅ CSS está carregando (inspecionar Network tab)
- ✅ Sem erros de console (F12)

---

## ✅ Regra #2: Cache é INIMIGO

**Problemas comuns:**
- CloudFlare cacheando versão antiga por 30 dias
- Query string desatualizado (`?v=2026-07-18` vs `?v=2026-07-24`)
- Browser cache
- Service Worker

**Solução:**
1. Sempre usar **cache-buster** (mudar `?v=YYYY-MM-DD`)
2. Verificar `Cache-Control` headers
3. Fazer hard refresh (`Ctrl+Shift+Delete`)
4. Testar em modo anônimo

---

## ✅ Regra #3: Nunca confiar em "sucesso" de commit

**ERRADO:**
```
✅ Deploy completo!
```

**CORRETO:**
```
✅ Deploy completo!
   Verificado: curl http://localhost/css/file.css | grep "#0b4f88" ✓
   Screenshot: Navbar azul confirmada
```

---

## ✅ Regra #4: Validar em cadeia

**Passos obrigatórios:**
1. Editar arquivo local
2. Fazer commit & push
3. Deploy na VM Oracle
4. **Verificar arquivo no servidor** (`ssh ... cat arquivo`)
5. **Testar no navegador** (screenshot ou curl)
6. **Confirmar ao usuário com evidência**

---

## ✅ Regra #5: Se o usuário vê diferente, ele está certo

**Nunca dizer:**
- "Segundo meu teste, está funcionando"
- "O arquivo está correto"
- "Deploy foi bem-sucedido"

**Investigar:**
- O que o usuário VÊ no navegador?
- Fazer a MESMA ação que ele fez
- Validar localmente + remotamente
- Testar com screenshot, não "achismo"

---

## 🔧 Checklist de Validação

Antes de responder "PRONTO":

- [ ] Arquivo editado localmente
- [ ] Git commit feito
- [ ] Push para GitHub OK
- [ ] Deploy para VM Oracle executado
- [ ] **SSH: arquivo no servidor verificado** (`md5sum`, `grep`)
- [ ] **Navegador: screenshot mostrando mudança**
- [ ] **Sem cache:** teste em modo anônimo
- [ ] **Logs: verificar Apache/PHP errors**

---

## 📸 Template de Resposta com Validação

```markdown
✅ [Mudança descrição]

**Validado em:**
- Arquivo local: ✓
- Git push: commit ABC123
- Servidor: md5sum ABC... ✓
- Navegador: [screenshot mostrando resultado] ✓
- Status: 100% LIVE
```

---

**NÃO aceitar respostas sem validação do navegador.**
