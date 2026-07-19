# 🤖 Como Ajudar a IA a Entregar o Projeto

Quando você está trabalhando com IA para construir projetos complexos, há coisas que você pode fazer para **acelerar drasticamente o progresso**:

## 1️⃣ **Forneça Feedback Específico**

❌ **NÃO FAÇA:**
```
"Isso não funcionou"
"Tem erro"
"Tenta de novo"
```

✅ **FAÇA:**
```
"O npm install está falhando com erro XYZ na linha 42"
"O Node.js não está no PATH, instalei em C:\Program Files\nodejs"
"Quando rodo npm start, recebo erro de conexão com banco de dados"
```

**Por quê:** A IA precisa de contexto específico para ajustar a abordagem. Um erro genérico = tentativa genérica. Um erro específico = solução direcionada.

---

## 2️⃣ **Relatar o Estado Atual**

Após cada etapa, diga:

✅ **Mostre o que funcionou:**
```
"Node.js está instalado: v24.18.0"
"npm install completou com sucesso"
"package.json criado na pasta backend"
```

❌ **NÃO deixe ambíguo:**
```
"Tá quase pronto"
"Falta só instalar"
```

---

## 3️⃣ **Provide Early Input on Blockers**

Se houver um problema que **você sabe** que é difícil de resolver remotamente:

✅ **Informe cedo:**
```
"O servidor HostGator só suporta PHP, não Node.js nativamente"
"Não temos acesso SSH root, apenas FTP"
"O banco de dados é MySQL, não PostgreSQL"
```

**Por quê:** Permite pivotear para soluções alternativas (Node.js em Heroku/Render, Node.js em container Docker, backend em PHP, etc.)

---

## 4️⃣ **Test Things Locally First**

Antes de pedir para colocar em produção:

✅ **Teste localmente:**
```bash
cd claude/medusa/backend
npm run start  # Funciona?
npm run build  # Compila?
npm test       # Testes passam?
```

**Resultado:** "npm start rodou sem erros, servidor respondendo em localhost:9000"

---

## 5️⃣ **Envie Screenshots/Logs**

Se houver erro visual ou comportamento estranho:

✅ **Faça:**
```
[screenshot da tela de erro]
[log completo do erro]
[resultado do comando que foi rodado]
```

---

## 6️⃣ **Comunique Restrições da Infraestrutura**

No início do projeto, deixe claro:

```
Hosting: HostGator (PHP + FTP + MySQL)
Node.js: Sim, instalado localmente
Banco: Tenho acesso a PostgreSQL? SIM/NÃO
SSH: Tenho acesso root? SIM/NÃO
Docker: Servidor suporta? SIM/NÃO
Domínio: shopvivaliz.com.br
```

---

## 7️⃣ **Use os Recursos Fornecidos**

Toda vez que EU fornecer:
- 📋 Checklist de configuração → Use para verificar o que falta
- 📝 Documentação → Leia para entender os próximos passos
- 🔧 Scripts → Execute para validar

---

## 8️⃣ **Feedback em Loop Rápido**

Para trabalho eficiente:

```
1. IA propõe solução
2. VOCÊ testa localmente (5 min)
3. VOCÊ relata específico (1 min)
4. IA ajusta (5 min)
5. Repetir até funcionar
```

**vs. ciclo lento:**
```
1. IA faz algo
2. Você espera...
3. IA adivinha o que deu errado
4. Demora mais tempo
```

---

## 9️⃣ **Para Esta Tarefa Específica**

Enquanto npm install está rodando, você pode:

✅ **Fazer:**
```bash
# Verificar se Node.js está no PATH permanentemente
node --version

# Testar npm
npm --version

# Verificar PostgreSQL (se houver)
psql --version

# Verificar espaço em disco
df -h
```

❌ **NÃO fazer:**
- Aguardar silenciosamente
- Deixar o processo morrer
- Fechar a terminal

---

## 🔟 **Template de Feedback**

Use este template para feedback claro:

```
📌 Etapa: [nome da etapa]
✅ Status: [✅ Funciona / ⚠️ Parcial / ❌ Falhou]
🔍 Detalhes: [o que você vê]
📊 Erro/Output: [copie a mensagem de erro completa]
🎯 Próximo passo: [o que você acha que deveria ser feito]
```

**Exemplo:**
```
📌 Etapa: npm install
✅ Status: ✅ Funciona
🔍 Detalhes: Completou em 3 minutos
📊 Output: "added 542 packages in 3m"
🎯 Próximo passo: Criar configuração do banco de dados
```

---

## 💡 **Princípio Geral**

> **"Quanto mais específico você for com o feedback, mais rápido a IA resolve."**

Pense em você dando instrução a um colega de trabalho que está vendo o código pela primeira vez. Quanto de contexto ele precisa para agir?

---

## 📋 Checklist para Agora

Enquanto npm instala:

- [ ] Node.js está no PATH?
- [ ] npm version é 8+?
- [ ] Pasta `claude/medusa/backend/` existe?
- [ ] `package.json` foi criado?
- [ ] npm install está rodando? (monitore o progresso)

Quando npm terminar:

- [ ] Avisou IA que completou?
- [ ] Houve erros ou warnings?
- [ ] `node_modules/` foi criado?
- [ ] Pronto para próxima etapa?

