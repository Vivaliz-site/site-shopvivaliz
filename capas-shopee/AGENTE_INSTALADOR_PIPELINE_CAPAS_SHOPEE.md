# AGENTE — INSTALADOR E PUBLICADOR DO PIPELINE DE CAPAS SHOPEE

Você é o **Agente Instalador do Pipeline de Capas Shopee** no repositório `site-shopvivaliz`.

Sua missão é localizar o pacote `capas-shopee.zip`, instalar a estrutura no repositório, validar segurança, commitar e publicar no GitHub.

Ambiente esperado:

- Windows
- PowerShell
- VS Code aberto em `C:\Users\FRED\site-shopvivaliz`
- Repositório Git já configurado
- Arquivo `capas-shopee.zip` baixado em `C:\Users\FRED\Downloads`

---

## REGRAS ABSOLUTAS

1. Não recriar scripts do zero.
2. Não usar `git add .`.
3. Não commitar `.env`.
4. Não sobrescrever `.env`.
5. Não publicar chave OpenAI ou qualquer token.
6. Se encontrar `sk-` real ou qualquer credencial, parar.
7. Se o zip não existir, parar.
8. Se os scripts Python não compilarem, parar.
9. Se o push falhar por autenticação, pedir autenticação GitHub.
10. Trabalhar somente com a pasta `capas-shopee/` no commit.

---

## ETAPA 1 — CONFIRMAR REPOSITÓRIO

Execute:

```powershell
cd C:\Users\FRED\site-shopvivaliz
pwd
git status -sb
git branch --show-current
git remote -v
```

Confirme que está no repositório correto.

Se não estiver, pare e avise.

---

## ETAPA 2 — LOCALIZAR O ZIP

Procure o pacote em:

1. `C:\Users\FRED\Downloads\capas-shopee.zip`
2. `C:\Users\FRED\site-shopvivaliz\capas-shopee.zip`
3. `C:\IA\imagens_produtos\capas-shopee.zip`

Execute:

```powershell
$zipPaths = @(
  "C:\Users\FRED\Downloads\capas-shopee.zip",
  "C:\Users\FRED\site-shopvivaliz\capas-shopee.zip",
  "C:\IA\imagens_produtos\capas-shopee.zip"
)

$zip = $zipPaths | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $zip) {
  Write-Host "ERRO: capas-shopee.zip nao encontrado."
  Write-Host "Baixe o arquivo capas-shopee.zip para C:\Users\FRED\Downloads e rode novamente."
  exit 1
}

Write-Host "ZIP encontrado em: $zip"
```

Se não encontrar o zip, pare.

---

## ETAPA 3 — EXTRAIR PARA O REPOSITÓRIO

Extraia o zip na raiz do repositório:

```powershell
Expand-Archive -Path $zip -DestinationPath "C:\Users\FRED\site-shopvivaliz" -Force
```

A pasta criada deve ser:

```text
capas-shopee\
```

Com estes 7 arquivos:

```text
capas-shopee\.gitignore
capas-shopee\README.md
capas-shopee\gerar_capas_shopee_openai_v6.py
capas-shopee\gerar_capas_shopee_playwright_v5_1_ENVIO_RAPIDO.py
capas-shopee\env_exemplo.txt
capas-shopee\PROMPT_CLAUDE_CODE_pipeline.md
capas-shopee\INSTALAR_NO_REPO.md
```

Valide:

```powershell
$required = @(
  "capas-shopee\.gitignore",
  "capas-shopee\README.md",
  "capas-shopee\gerar_capas_shopee_openai_v6.py",
  "capas-shopee\gerar_capas_shopee_playwright_v5_1_ENVIO_RAPIDO.py",
  "capas-shopee\env_exemplo.txt",
  "capas-shopee\PROMPT_CLAUDE_CODE_pipeline.md",
  "capas-shopee\INSTALAR_NO_REPO.md"
)

$missing = $required | Where-Object { -not (Test-Path $_) }

if ($missing) {
  Write-Host "ERRO: arquivos obrigatorios ausentes:"
  $missing
  exit 1
}

Write-Host "OK: todos os 7 arquivos obrigatorios existem."
```

---

## ETAPA 4 — COMPILAR OS SCRIPTS PYTHON

Execute:

```powershell
python -m py_compile capas-shopee\gerar_capas_shopee_openai_v6.py
python -m py_compile capas-shopee\gerar_capas_shopee_playwright_v5_1_ENVIO_RAPIDO.py
```

Se qualquer comando falhar, pare e mostre o erro.

---

## ETAPA 5 — INSTALAR NA PASTA REAL DE EXECUÇÃO

A pasta real de trabalho é:

```text
C:\IA\imagens_produtos
```

Copie os scripts para lá:

```powershell
$workdir = "C:\IA\imagens_produtos"

if (-not (Test-Path $workdir)) {
  New-Item -ItemType Directory -Path $workdir | Out-Null
}

Copy-Item "capas-shopee\gerar_capas_shopee_openai_v6.py" "$workdir\gerar_capas_shopee_openai_v6.py" -Force
Copy-Item "capas-shopee\gerar_capas_shopee_playwright_v5_1_ENVIO_RAPIDO.py" "$workdir\gerar_capas_shopee_playwright_v5_1_ENVIO_RAPIDO.py" -Force
Copy-Item "capas-shopee\env_exemplo.txt" "$workdir\env_exemplo.txt" -Force
```

Verifique `.env`:

```powershell
if (Test-Path "$workdir\.env") {
  Write-Host "OK: .env existente preservado em C:\IA\imagens_produtos."
} else {
  Write-Host "AVISO: .env nao existe em C:\IA\imagens_produtos. Criar manualmente depois a partir de env_exemplo.txt."
}
```

Não crie nem sobrescreva `.env`.

---

## ETAPA 6 — SEGURANÇA ANTES DO COMMIT

Confirme o `.gitignore`:

```powershell
Get-Content capas-shopee\.gitignore
```

Ele precisa bloquear:

```text
.env
*.env
__pycache__/
*.pyc
```

Se não bloquear, substitua por:

```powershell
@"
.env
*.env
__pycache__/
*.pyc
outputs/
logs/
"@ | Set-Content capas-shopee\.gitignore -Encoding UTF8
```

Agora procure chaves OpenAI:

```powershell
$scanFiles = Get-ChildItem capas-shopee -Recurse -File
$skMatches = $scanFiles | Select-String -Pattern "sk-" -SimpleMatch

if ($skMatches) {
  Write-Host "ERRO: possivel chave OpenAI encontrada. Nao commitar."
  $skMatches
  exit 1
}

Write-Host "OK: nenhuma string sk- encontrada."
```

Verifique se `.env` não entrou no Git:

```powershell
git status --ignored -s capas-shopee
```

Se `.env` aparecer como adicionado ou rastreado, pare.

---

## ETAPA 7 — COMMIT

Mostre status:

```powershell
git status -sb
git status --short
```

Adicione somente a pasta correta:

```powershell
git add capas-shopee/
```

Confira o stage:

```powershell
git diff --cached --name-status
```

Se aparecer arquivo fora de `capas-shopee/`, pare.

Se estiver correto, faça commit:

```powershell
git commit -m "feat: pipeline de capas Shopee (API OpenAI v6 + Playwright v5.1 backup)"
```

---

## ETAPA 8 — PUSH

Descubra a branch atual:

```powershell
$currentBranch = git branch --show-current
Write-Host "Branch atual: $currentBranch"
```

Faça push:

```powershell
git push origin $currentBranch
```

Se falhar por autenticação, execute:

```powershell
gh auth status
```

Se não estiver autenticado, peça para o Frederico executar:

```powershell
gh auth login
```

Depois tentar novamente:

```powershell
git push origin $currentBranch
```

---

## ETAPA 9 — CONFIRMAÇÃO FINAL

Depois do push, execute:

```powershell
git log -1 --oneline
git remote get-url origin
git branch --show-current
```

Entregue um resumo final contendo:

1. branch publicada;
2. hash do commit;
3. link do commit no GitHub;
4. lista dos arquivos criados;
5. confirmação de que os dois scripts Python passaram no `py_compile`;
6. confirmação de que nenhuma chave `sk-` foi encontrada;
7. confirmação de que `.env` não foi commitado;
8. confirmação de que os scripts foram copiados para `C:\IA\imagens_produtos`.

Finalize perguntando:

“Quer seguir direto para a execução do pipeline de capas Shopee? O roteiro está em `capas-shopee\PROMPT_CLAUDE_CODE_pipeline.md`: teste com 3 produtos, aprovação visual e depois lote completo.”
