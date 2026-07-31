# 🔄 Clonar Configuração para Outra Estação

**Última atualização:** 2026-07-16  
**Objetivo:** Replicar VS Code em outro computador com as mesmas configurações

---

## 3 Formas de Fazer

### ✨ Opção 1: Script Automático (RECOMENDADO)

A forma mais rápida e fácil - um script PowerShell faz tudo.

#### Passo 1: Copiar o script
- Localize: `Desktop\Sincronizar-VS-Code-Outra-Estacao.ps1`
- Copie para a outra estação (via pendrive, e-mail, OneDrive, etc)

#### Passo 2: Executar na outra estação
```powershell
PowerShell -ExecutionPolicy Bypass -File "C:\Users\seu-usuario\Desktop\Sincronizar-VS-Code-Outra-Estacao.ps1"
```

#### Passo 3: Aguarde
O script vai:
1. ✅ Verificar VS Code
2. ✅ Clonar/atualizar repositório
3. ✅ Instalar 26 extensões
4. ✅ Copiar configurações
5. ✅ Criar atalho no Desktop
6. ✅ Abrir VS Code

**Tempo estimado:** 5-10 minutos

---

### 📁 Opção 2: Clonar via Git (para devs)

Se você quer controle total.

#### Passo 1: Clone o repositório
```bash
git clone https://github.com/Vivaliz-site/site-shopvivaliz.git c:\site-shopvivaliz
cd c:\site-shopvivaliz
```

#### Passo 2: Instale as extensões manualmente
```powershell
code --install-extension esbenp.prettier-vscode
code --install-extension dbaeumer.vscode-eslint
code --install-extension bmewburn.vscode-intelephense-client
# ... (veja lista completa abaixo)
```

#### Passo 3: Abra VS Code
```powershell
code c:\site-shopvivaliz
```

#### Passo 4: Configure tema
- F1 → "Color Theme" → "Ayu Dark"

---

### 💾 Opção 3: Copiar Arquivos Manualmente

Se preferir fazer passo-a-passo.

#### Passo 1: Clone o repositório
```powershell
git clone https://github.com/Vivaliz-site/site-shopvivaliz.git c:\site-shopvivaliz
```

#### Passo 2: Copie pasta `.vscode`
- De: `c:\site-shopvivaliz\.vscode`
- Para: `c:\site-shopvivaliz\.vscode` (na outra máquina)

#### Passo 3: Copie arquivo workspace
- De: `c:\site-shopvivaliz\site-shopvivaliz.code-workspace`
- Para: `c:\site-shopvivaliz\site-shopvivaliz.code-workspace` (na outra máquina)

#### Passo 4: Instale extensões
Abra VS Code e pressione Ctrl+Shift+X, depois procure por cada uma da lista abaixo.

---

## 📋 Lista Completa de Extensões

Se precisar instalar manualmente, aqui está a lista de IDs:

```powershell
# Formatação
code --install-extension esbenp.prettier-vscode
code --install-extension dbaeumer.vscode-eslint
code --install-extension stylelint.vscode-stylelint

# Linguagens
code --install-extension bmewburn.vscode-intelephense-client
code --install-extension ms-python.python
code --install-extension ms-python.vscode-pylance
code --install-extension ms-python.debugpy

# Git
code --install-extension eamodio.gitlens
code --install-extension donjayamanne.githistory
code --install-extension mhutchie.git-graph

# DevOps
code --install-extension ms-azuretools.vscode-docker
code --install-extension ms-vscode-remote.remote-ssh

# Temas
code --install-extension teabyii.ayu
code --install-extension zhuangtongfa.material-icon-theme

# Utilitários
code --install-extension redhat.vscode-yaml
code --install-extension hashicorp.hcl
code --install-extension ms-vscode.makefile-tools
code --install-extension vadimcn.vscode-lldb
code --install-extension firsttris.vscode-jest-runner
code --install-extension bradlc.vscode-tailwindcss
code --install-extension formulahendry.auto-rename-tag
code --install-extension mechatroner.rainbow-csv
code --install-extension alefragnani.bookmarks
code --install-extension humao.rest-client
code --install-extension eriklynd.json-tools
code --install-extension streetsidesoftware.code-spell-checker
code --install-extension streetsidesoftware.code-spell-checker-portuguese-brazilian
```

---

## ✅ Verificação Pós-Instalação

Depois de usar qualquer opção, verifique:

### 1. Extensões instaladas
```
Ctrl+Shift+X → Verificar que todas estão em verde ✅
```

### 2. Tema
```
F1 → Color Theme → Ayu Dark (deve estar selecionado)
```

### 3. Formatação automática
```
Edite um arquivo .php ou .js
Pressione Ctrl+S
Observe a formatação automática
```

### 4. Workspace
```
File → Open Workspace → site-shopvivaliz.code-workspace
Deve carregar todas as configurações
```

### 5. Git
```
Abra terminal integrado (Ctrl+~)
Digite: git config --global user.name
Deve retornar o nome do usuário
```

---

## 🔧 Configurações de Usuário Diferentes

Se o usuário for **diferente** entre as estações:

### Opção A: Mesmo usuário Git
```powershell
git config --global user.name "Seu Nome"
git config --global user.email "seu@email.com"
```

### Opção B: Usuários diferentes
```powershell
# Configurar por repositório (não global)
cd c:\site-shopvivaliz
git config user.name "Outra Pessoa"
git config user.email "outra@email.com"
```

### Opção C: Credenciais diferentes
Windows armazenará credenciais diferentes por estação (gerenciador de credenciais).

---

## 🆘 Troubleshooting

### Script não executa
**Problema:** "PowerShell script execution policy"  
**Solução:**
```powershell
PowerShell -ExecutionPolicy Bypass -File ".\script.ps1"
```

### VS Code não abre
**Problema:** VS Code não instalado  
**Solução:** Instale de https://code.visualstudio.com/

### Extensões não instalam
**Problema:** Conexão lenta ou marketplace indisponível  
**Solução:** Tente manualmente via Ctrl+Shift+X

### Git não funciona
**Problema:** Git não instalado  
**Solução:** Instale de https://git-scm.com/

### Tema não aparece
**Problema:** Arquivo de tema corrompido  
**Solução:**
```powershell
F1 → Developer: Reload Window
F1 → Color Theme → Ayu Dark
```

---

## 📊 Comparação de Métodos

| Método | Tempo | Automático | Requer Git | Recomendado |
|--------|-------|------------|-----------|-------------|
| Script PowerShell | 5-10 min | ✅ Sim | ✅ Sim | ⭐⭐⭐ |
| Git Clone + Manual | 10-15 min | ❌ Parcial | ✅ Sim | ⭐⭐ |
| Copiar Arquivos | 15-20 min | ❌ Não | ✅ Sim | ⭐ |

---

## 🔄 Manter Sincronizado

Depois de configurar, para manter as estações sincronizadas:

### Na estação "principal"
```bash
# Depois de fazer mudanças
git add -A
git commit -m "chore: atualização configurações"
git push origin main
```

### Na outra estação
```bash
cd c:\site-shopvivaliz
git pull origin main
# Recarregar VS Code se necessário
```

Ou execute o script novamente para garantir tudo atualizado.

---

## 💡 Dicas

1. **Pendrive:** Copie o script para um pendrive e use em qualquer estação
2. **OneDrive:** Coloque o script em OneDrive/Cloud para acesso fácil
3. **Agendado:** Pode executar via tarefa agendada do Windows (cron)
4. **Equipe:** Distribua o script para toda a equipe de desenvolvimento
5. **CI/CD:** Use o script em pipelines para ambientes de desenvolvimento

---

## 📞 Suporte

Se algo não funcionar:

1. Verifique que VS Code está instalado: `code --version`
2. Verifique que Git está instalado: `git --version`
3. Leia os logs do script para erro específico
4. Tente executar opção 2 ou 3 manualmente

---

**Sistema pronto para sincronizar entre múltiplas estações! 🚀**
