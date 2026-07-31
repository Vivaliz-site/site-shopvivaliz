# ⚙️ Configuração Otimizada do VS Code - ShopVivaliz

> **Última atualização:** 2026-07-16  
> **Status:** ✅ Otimizado para produção  
> **Idioma:** Português (Brasil)

---

## 🎯 O que foi configurado

✅ **Extensões Essenciais Instaladas** (22 extensões)
✅ **Idioma em Português (Brasil)**
✅ **Folder padrão:** `c:\site-shopvivaliz`
✅ **Automação completa sem confirmações**
✅ **Formatação automática de código**
✅ **Integração Git otimizada**
✅ **Temas e ícones modernos**

---

## 🚀 Atalhos Rápidos Recomendados

| Atalho | Ação |
|--------|------|
| `Ctrl+Shift+X` | Abrir extensões |
| `Ctrl+Shift+P` | Paleta de comandos |
| `Ctrl+K Ctrl+S` | Ver todos os atalhos |
| `F1` | Comandos do VS Code |
| `Ctrl+~` | Abrir terminal integrado |
| `Ctrl+B` | Toggle sidebar |
| `Ctrl+Alt+L` | Formatar documento |

---

## 📦 Extensões Instaladas

### 🎨 Tema e Aparência
- **Material Icon Theme** - Ícones profissionais
- **Ayu** - Tema moderno e limpo
- **One Dark Pro** - Tema escuro padrão

### 🔧 Desenvolvimento PHP
- **Intelephense** - Autocomplete e type-checking PHP profissional
- **PHP Debug** - Debugar código PHP
- **PHP IntelliSense** - Sugestões inteligentes

### 🐍 Python
- **Python** - Suporte completo Python
- **Pylance** - Type checker
- **Debugpy** - Debugger Python

### 📝 Formatação e Linting
- **Prettier** - Formatador JS/TS/JSON
- **ESLint** - Lint JavaScript
- **StyleLint** - Lint CSS

### 🔀 Git e Versionamento
- **GitLens** - Superpoderes Git (blame, history, etc)
- **Git Graph** - Visualizar histórico Git
- **Git History** - Ver histórico de arquivos

### 🐳 DevOps
- **Docker** - Suporte Docker
- **Remote SSH** - Desenvolvimento remoto via SSH

### 🤖 IA e Inteligência
- **GitHub Copilot** - Autocompletar com IA
- **Copilot Chat** - Chat com IA

### 📚 Utilitários
- **YAML Support** - Validação YAML
- **REST Client** - Testar APIs sem Postman
- **Rainbow CSV** - Cores em CSV
- **Bookmarks** - Marcar linhas importantes
- **Auto Rename Tag** - Renomear tags automático
- **Code Spell Checker** - Verificador ortográfico

---

## 🔐 Configurações de Segurança

As configurações garantem:
- ✅ Auto-commit e push automático após edições
- ✅ Sem confirmações para agentes
- ✅ Permissões amplas para CLI tools
- ✅ Workspace trust desabilitado (confiança total)

---

## 📂 Estrutura de Pastas Recomendada

```
c:\site-shopvivaliz\
├── .vscode/
│   ├── settings.json           ← Configurações otimizadas
│   ├── extensions.json         ← Extensões recomendadas
│   └── launch.json             ← Configurações de debug
├── .claude/
│   ├── settings.json           ← Automação Claude Code
│   └── memory/                 ← Memória de contexto
├── api/                        ← APIs PHP
├── includes/                   ← Classes e funções
├── public/                     ← HTML/CSS/JS público
├── tests/                      ← Testes unitários
├── venv/                       ← Ambiente Python
├── .git/                       ← Repositório Git
└── CLAUDE.md                   ← Instruções do projeto
```

---

## 🔄 Fluxo de Trabalho Automático

### 1️⃣ Editar Arquivo
```
Ctrl+S → Arquivo salvo automaticamente
```

### 2️⃣ Formatar Código
```
Shift+Alt+F → Prettier formata automaticamente
```

### 3️⃣ Commit e Push Automático
- Hook automático ao salvar → Git add + commit + push
- Status: 📤 Auto-push... (assíncrono)

### 4️⃣ GitHub Actions
- QA Lint valida código (5 min)
- Auto-validator detecta issues (30 min)
- Deploy automático para VM Oracle

---

## ⌨️ Atalhos Customizados (Vim Mode)

Se você usa Vim:
```json
"editor.keyMap": "vim"
"vim.enable": true
```

Remaps recomendados (Insert mode):
- `jj` → `<Esc>` - Sair do insert rápido

---

## 🎯 Dicas de Produtividade

### GitLens
- Hover em linha → Ver autor e data
- `Ctrl+Shift+G` → Abrir GitLens
- Blame inline automático

### Prettier + Eslint
- Auto-format ao salvar ✅
- Auto-fix ESLint ✅
- Trailing commas, semicolons automáticos ✅

### Copilot AI
- `Ctrl+I` → Aceitar sugestão Copilot
- `Tab` → Próxima sugestão
- `Shift+Tab` → Sugestão anterior

### Debug (F5)
- Breakpoints: Clicar na linha
- Step over: F10
- Step into: F11
- Continue: F5

---

## 🚨 Troubleshooting

### Extensões não aparecem
```bash
code --list-extensions  # Ver instaladas
code --install-extension ID  # Reinstalar
```

### VS Code muito lento
1. Abrir Activity Monitor
2. Verificar extensões ativas
3. Desativar extensões não usadas

### Git não funciona
```bash
git --version  # Verificar instalação
git config --global user.name "Seu Nome"
git config --global user.email "seu@email.com"
```

### Prettier conflitando com ESLint
- Desabilitar ESLint em settings.json se precisar
- Ou configurar ESLint para usar Prettier

---

## 📖 Recursos Úteis

- **VS Code Docs:** https://code.visualstudio.com/docs
- **GitLens:** https://www.gitlens.dev/
- **Prettier:** https://prettier.io/docs
- **ESLint:** https://eslint.org/docs
- **Copilot:** https://github.com/features/copilot

---

## ✨ Próximos Passos

1. **Reiniciar VS Code** para aplicar todas as mudanças
2. **Verificar tema** - F1 > "Color Theme"
3. **Configurar Git** se não tiver feito
4. **Testar Copilot** - Abrir novo arquivo e começar a digitar
5. **Explorar extensões** - Ctrl+Shift+X para ver todas instaladas

---

**Sistema otimizado e pronto para produção! 🚀**
