# 📊 Monitor Web - Configuração

Seu monitor web está em: **https://shopvivaliz.com.br/admin/monitor/**

---

## ✨ Recursos

- ✅ Dashboard em tempo real
- ✅ Chat interativo com agentes
- ✅ Notificações por email
- ✅ Controle de execução (pausar/retomar)
- ✅ Adicionar tarefas via interface
- ✅ Monitoramento de progresso

---

## 🚀 Acessar o Monitor

Abra no navegador:
```
https://shopvivaliz.com.br/admin/monitor/
```

Você verá:
- **Status em tempo real** - Ativo/Pausado
- **Estatísticas** - Total, pendentes, completas, taxa
- **Próximas tarefas** - Fila de trabalho
- **Chat** - Enviar comandos aos agentes

---

## 📧 Configurar Notificações por Email

Para receber emails quando houver problemas, siga estes passos:

### 1. Criar Conta Gmail com 2FA (Recomendado)

Você pode usar sua conta pessoal ou criar uma conta específica para o sistema.

### 2. Gerar Senha de App Google

1. Vá a: https://myaccount.google.com/apppasswords
2. Selecione:
   - Aplicativo: **Mail**
   - Dispositivo: **Windows Computer** (ou seu SO)
3. Clique em **Gerar**
4. Google criará uma senha de 16 caracteres
5. **Copie essa senha** (será usada no GitHub)

⚠️ **Nota:** Essa senha é diferente da sua senha de login do Gmail!

### 3. Adicionar Secrets no GitHub

1. Vá a: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions
2. Clique em **New repository secret**
3. Crie 2 secrets:

#### Secret 1: EMAIL_USER
- **Name:** `EMAIL_USER`
- **Value:** Seu email Gmail (ex: `fredmourao@gmail.com`)
- Clique **Add secret**

#### Secret 2: EMAIL_PASSWORD
- **Name:** `EMAIL_PASSWORD`
- **Value:** A senha de app gerada no passo 2 (16 caracteres, sem espaços)
- Clique **Add secret**

### 4. Testar Notificação

1. Vá a: GitHub → Actions → Trio IA - Executor Autônomo
2. Clique **Run workflow** → **Run workflow**
3. Aguarde a execução
4. Você deve receber 2 emails:
   - 1 durante a execução
   - 1 ao final (sucesso ou erro)

---

## 💬 Usar o Chat do Monitor

O chat permite enviar instruções direto aos agentes IA:

### Comandos Disponíveis

| Comando | Descrição | Exemplo |
|---------|-----------|---------|
| **execute-now** | Executa a próxima tarefa imediatamente | "execute-now" |
| **pause** | Pausa o executor autônomo | "pause" |
| **resume** | Retoma após pausar | "resume" |
| **message** | Envia mensagem aos agentes | "Foque em performance" |

### Exemplos de Uso

```
Chat: "execute-now"
→ Sistema: "Executor acionado! Próxima tarefa será executada em breve."

Chat: "pause"
→ Sistema: "Executor pausado. Retome com o comando 'resume'."

Chat: "Aumentar prioridade da tarefa 003"
→ Sistema: "Mensagem registrada e processada pelos agentes."
```

---

## 🎮 Botões de Ação Rápida

### ▶️ Executar Agora
Aciona o executor imediatamente, sem esperar 30 minutos.

### ➕ Adicionar Tarefa
Abre modal para adicionar nova tarefa à fila. Preencha:
- **Título** (obrigatório)
- **Descrição** (obrigatório)
- **Prioridade** (baixa, média, alta)

### ⏸️ Pausar / ▶️ Retomar
Pausa ou retoma as execuções automáticas do executor.

### 🔄 Atualizar
Força atualização dos dados (normalmente faz auto-refresh a cada 5s).

---

## 📊 Interpretar o Dashboard

### Stats
- **Total de Tarefas:** Número total de tarefas na fila
- **Pendentes:** Aguardando execução
- **Completas:** Já foram executadas
- **Taxa Conclusão:** Percentual de tarefas concluídas

### Progresso Geral
Barra que mostra percentual de conclusão. Cor muda à medida que aumenta.

### Próximas Tarefas
Lista as tarefas `pending` ordenadas por prioridade. Mostra:
- ID da tarefa (ex: `task-001`)
- Título
- Prioridade (ALTA, MÉDIA, BAIXA)

### Tarefas Completas
Histórico de tarefas que já foram executadas.

---

## 🔍 Debugar Problemas

### Emails não chegando?

1. **Verifique os secrets:**
   - Settings → Secrets and variables → Actions
   - Confirme que `EMAIL_USER` e `EMAIL_PASSWORD` estão configurados

2. **Verifique os logs:**
   - Actions → Últimas execuções
   - Procure por erros nas etapas de notificação

3. **Teste manualmente:**
   - Execute o workflow
   - Verifique se há erro na etapa "Notificar em caso de falha"

### Monitor não carrega?

1. Verifique se `/api/monitor/api.php` está acessível:
   ```
   https://shopvivaliz.com.br/api/monitor/api.php?action=status
   ```

2. Se receber JSON, a API está funcionando
3. Se erro 404, verifique permissões do arquivo

### Chat não funciona?

1. Abra Developer Tools (F12) → Console
2. Verifique se há erro de CORS
3. Confirme que a API está respondendo

---

## 📈 Monitoramento 24/7

O sistema roda **24 horas por dia**:
- Executor: A cada 30 minutos
- Auto-refresh do dashboard: A cada 5 segundos
- Notificações: Em tempo real (quando há problemas)

Você pode:
- Deixar o monitor aberto em um segundo monitor
- Receber emails em tempo real
- Intervir via chat quando necessário

---

## 🎯 Fluxo Típico

1. **Acesso Monitor** → https://shopvivaliz.com.br/admin/monitor/
2. **Visualiza Status** → Dashboard mostra tudo em tempo real
3. **Recebe Email** → Se houver erro ou impedimento
4. **Intervém se Necessário** → Chat ou GitHub Issues
5. **Sistema Continua** → 24/7 sem intervenção

---

## 🛑 Pausar o Monitor

Se precisar pausar o sistema:

**Via Monitor:**
- Clique botão "⏸️ Pausar"

**Via GitHub:**
- Actions → Trio IA - Executor Autônomo
- ... → Disable workflow

**Via Edição de Arquivo:**
- Edite `.github/workflows/ai-autonomous-executor.yml`
- Comente a seção `schedule`

---

## ✅ Checklist de Configuração

- [ ] Abrir https://shopvivaliz.com.br/admin/monitor/
- [ ] Gerar senha de app Google
- [ ] Adicionar `EMAIL_USER` secret
- [ ] Adicionar `EMAIL_PASSWORD` secret
- [ ] Testar workflow manualmente
- [ ] Receber email de teste
- [ ] Explorar chat de comandos
- [ ] Sistema operacional e pronto!

---

## 📞 Suporte

Se algo não funcionar:
1. Verifique os logs do workflow
2. Leia os erros com atenção
3. Adicione uma issue no GitHub com `[MONITOR]` no título

---

**Seu monitor está pronto para 24/7 de monitoramento! 🎉**
