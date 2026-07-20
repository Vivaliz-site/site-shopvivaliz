# 🔧 GUIA DE RESOLUÇÃO DE ERROS NO DEPLOY

## ❌ Problema: Deploy Falhando

### 🔍 Diagnóstico Rápido

**Secrets Obrigatórios Necessários:**

```
✓ FTP_SERVER
✓ FTP_USERNAME
✓ FTP_PASSWORD
✓ FTP_PORT
✓ FTP_REMOTE_DIR
✓ SQUAD_TOKEN
```

---

## 📋 CHECKLIST DE VERIFICAÇÃO

### 1️⃣ Verificar GitHub Secrets

Acesse: https://github.com/seu-repo/settings/secrets/actions

- [ ] `FTP_SERVER` configurado
- [ ] `FTP_USERNAME` configurado
- [ ] `FTP_PASSWORD` configurado
- [ ] `FTP_PORT` configurado (21 ou 22)
- [ ] `FTP_REMOTE_DIR` = `/`
- [ ] `SQUAD_TOKEN` configurado

### 2️⃣ Valores Corretos para HostGator

**FTP_SERVER:** 
```
ftp.shopvivaliz.com.br
(ou o IP do seu servidor)
```

**FTP_USERNAME:**
```
dev@dev.shopvivaliz.com.br
```

**FTP_PASSWORD:**
```
(sua senha de FTP)
```

**FTP_PORT:**
```
21  (FTP padrão)
22  (se usar SFTP)
```

**FTP_REMOTE_DIR:**
```
/
(raiz da conta FTP)
```

**SQUAD_TOKEN:**
```
(seu token de API)
```

---

## 🛠️ SOLUÇÃO PASSO A PASSO

### Se o erro é "Connection refused" ou "Cannot connect":

1. **Verifique FTP_SERVER:**
   ```bash
   ping ftp.shopvivaliz.com.br
   ```
   Se falhar → servidor incorreto

2. **Verifique FTP_PORT:**
   ```bash
   telnet ftp.shopvivaliz.com.br 21
   ```
   Se não conectar → porta errada

3. **Teste credentials localmente:**
   ```bash
   ftp ftp.shopvivaliz.com.br
   # Digite username e password
   ```

### Se o erro é "Authentication failed":

1. **Verifique FTP_USERNAME:** Deve ser exatamente `dev@dev.shopvivaliz.com.br`
2. **Verifique FTP_PASSWORD:** Certifique-se que não tem espaços ou caracteres especiais
3. **Resete a senha FTP** via cPanel se necessário

### Se o erro é "Permission denied" ou "Cannot write":

1. **Verifique FTP_REMOTE_DIR:** Deve ser `/` (raiz)
2. **Verifique permissões da pasta:** Deve ter 755 ou 777

### Se o erro é "server-dir should be a folder":

- Mudar `FTP_REMOTE_DIR` para `/` (com barra final)

---

## 📝 Configuração Correta Passo a Passo

### No GitHub Settings:

1. Vá para: `https://github.com/seu-repo/settings/secrets/actions`
2. Clique em "New repository secret"
3. Adicione cada secret:

```
Name: FTP_SERVER
Value: ftp.shopvivaliz.com.br

Name: FTP_USERNAME
Value: dev@dev.shopvivaliz.com.br

Name: FTP_PASSWORD
Value: (sua senha)

Name: FTP_PORT
Value: 21

Name: FTP_REMOTE_DIR
Value: /

Name: SQUAD_TOKEN
Value: (seu token)
```

---

## 🔄 Depois de Corrigir

1. Faça um commit vazio:
   ```bash
   git commit --allow-empty -m "trigger: retry deploy"
   git push
   ```

2. Verifique se o deploy passou em:
   ```
   https://github.com/seu-repo/actions
   ```

3. Teste o acesso:
   ```
   https://shopvivaliz.com.br/
   ```

---

## 🆘 Se Ainda Falhar

Colete essas informações:

1. **Mensagem de erro exata** do GitHub Actions
2. **Nome do step** que falhou (ex: "Enviar via FTP Deploy Action")
3. **Confirmação:**
   - [ ] FTP conecta manualmente?
   - [ ] Pasta remota é acessível?
   - [ ] Permissões da pasta estão corretas?

---

## 📞 Contato para Suporte

Se não conseguir resolver:

1. Verifique logs do cPanel FTP
2. Contate suporte HostGator
3. Confirme credenciais FTP estão corretas

---

*Última atualização: 2026-06-27*
