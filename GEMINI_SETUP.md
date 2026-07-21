# Configurar GEMINI_API_KEY em Produção

## 🔒 Segurança

A chave Gemini API **NUNCA deve ser commitada no repo** (GitHub Secret Scanning impedirá push).

## ✅ Métodos Seguros de Configuração

### Método 1: GitHub Secrets (Recomendado para CI/CD)

1. Ir para: `https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions`
2. Adicionar secret: `GEMINI_API_KEY` = `***REMOVED***`
3. Usar em workflows `.github/workflows/*.yml`:
   ```yaml
   env:
     GEMINI_API_KEY: ${{ secrets.GEMINI_API_KEY }}
   ```

### Método 2: Variável de Ambiente na VM (Recomendado para servidor direto)

SSH para a VM:
```bash
ssh -i <chave-privada> ubuntu@137.131.156.17

# Adicionar ao .env da VM
cd /home/ubuntu/site-shopvivaliz
echo "" >> .env
echo "# === CREDENCIAIS IA ===" >> .env
echo "GEMINI_API_KEY=***REMOVED***" >> .env

# Verificar
grep GEMINI_API_KEY .env

# Recarregar Apache (ou reiniciar daemon)
sudo systemctl restart apache2
```

### Método 3: Script de Configuração via Web (Apenas em produção)

1. Coloque `/configure-gemini-key.php` no servidor
2. Certifique-se de que `/home/ubuntu/.gemini-key` existe com a chave
3. Acesse: `https://shopvivaliz.com.br/configure-gemini-key.php`
4. Response deve ser `{"ok": true}`

## 🧪 Testes

Depois de configurar a chave:

```bash
# Test squad-chat.php (Liz chatbot)
curl -X POST https://shopvivaliz.com.br/api/agent/squad-chat.php \
  -H "Content-Type: application/json" \
  -d '{"message":"Como faço para comprar?","session_id":"test"}'

# Deve retornar uma resposta do Gemini (não "Desculpe, não consegui responder")
```

## 📋 Checklist de Deploy

- [ ] Verificar que `.env` tem `GEMINI_API_KEY` configurada
- [ ] Testar `/api/agent/squad-chat.php` com uma pergunta real
- [ ] Verificar logs: `tail -f /logs/squad-chat-*.log`
- [ ] Testar widget Liz no site: `https://shopvivaliz.com.br` (chat no canto inferior direito)

## 🔄 Rotação de Chave (se comprometida)

1. **Gerar nova chave** no Google Cloud Console
2. **Desabilitar chave antiga**: `***REMOVED***`
3. **Atualizar** `/home/ubuntu/site-shopvivaliz/.env` com nova chave
4. **Verificar** logs para erros de autenticação
5. **Notificar** se a chave foi comprometida (GitHub secret scanning o detectará)

## 📚 Referências

- [Google AI Studio - Gemini API Keys](https://aistudio.google.com/app/apikey)
- [GitHub Secret Scanning Docs](https://docs.github.com/code-security/secret-scanning)
- Arquivo principal: `/api/agent/squad-chat.php`
