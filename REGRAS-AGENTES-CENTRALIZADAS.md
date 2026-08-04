# Regras centralizadas para agentes e automações

**Escopo:** agentes, bots, workflows, scripts, serviços, bridges, integrações e
qualquer rotina capaz de observar ou alterar o projeto.

## 1. Evidência antes de estado

Nenhuma rotina pode registrar `success`, `OK`, `completed`, `done`, `healthy`,
`alive` ou equivalente sem comprovar o efeito real. A evidência deve estar
vinculada à execução atual e não pode ser reutilizada de outro ciclo.

Evidência mínima:

- `run_id` ou identificador único do ciclo;
- `commit_sha` esperado e observado;
- comando/API e código de saída;
- teste ou verificação independente;
- artifact, relatório ou hash imutável;
- timestamp posterior ao início da execução;
- pull request e aprovação humana quando arquivos versionados mudarem.

## 2. Fluxo Git obrigatório

- Trabalhar em branch não protegida.
- Usar staging explícito por caminho.
- Abrir PR com diff e evidências.
- Todos os checks obrigatórios devem terminar verdes.
- É obrigatória ao menos uma revisão de conta humana independente com estado
  `APPROVED`.
- O autor do PR, o agente que gerou a alteração, bots, GitHub Actions, Copilot e
  revisores automáticos não contam como aprovação independente.
- O merge deve ser concluído por mantenedor humano diferente do autor/agente.
- Auto-merge e merge pelo próprio agente são proibidos.

É proibido:

```text
git push origin main
git push ... --force
git add .
git add -A
gh pr merge --auto
git reset --hard
git clean -fd
```

## 3. Falhar fechado

Scripts shell devem usar:

```bash
set -Eeuo pipefail
```

Erros não podem ser ocultados com `|| true`, `|| log`, `continue-on-error`,
`@exec`, retorno zero artificial ou mensagem de sucesso posterior. Uma falha em
etapa obrigatória deve encerrar a rotina com código diferente de zero.

## 4. Fila e trabalho real

A fila só pode mudar de estado depois que o trabalho real ocorreu e foi
verificado. Selecionar, atribuir, imprimir um comando, produzir heartbeat ou
montar uma resposta textual não constitui execução.

- `pending` pode ser observado sem mutação;
- `running` exige executor iniciado e identificador do ciclo;
- `completed_verified` exige commit/teste/artifact/read-back;
- `failed` preserva código e erro;
- `blocked` preserva o motivo e não pode ser exibido como saudável.

Filas legadas aposentadas devem permanecer vazias e somente leitura.

## 5. Deploy e produção

Deploy só pode usar release imutável vinculada a SHA aprovado. O monitor deve
comparar explicitamente:

```text
expected_release_sha == observed_release_sha
```

Endpoints web não podem executar Git, baixar código de uma branch mutável,
sobrescrever PHP ou limpar OPcache após escrita parcial. Operações de deploy
exigem pipeline revisado, release separada, rollback e smoke test independente.

## 6. Secrets e dados privados

- Nunca versionar ou imprimir tokens, senhas, chaves ou dados pessoais.
- Secrets são administrados por stores protegidos, app/conector ou gerenciador
  de segredos.
- O grupo do servidor web não pode criar, substituir ou excluir arquivos no
  diretório que contém tokens.
- Rotina agendada de health deve ser somente leitura; refresh OAuth exige ação
  administrativa autenticada e auditável.

## 7. Health e monitoramento

Processo `active`, arquivo existente, `idle`, `no_pending`, heartbeat ou saída
zero não comprovam saúde. Health operacional exige:

- execução recente dentro da janela definida;
- conclusão verdadeira de todas as etapas;
- artifact não expirado;
- SHA correto;
- evidência mínima por agente/componente;
- ausência de erro mascarado.

O health estrutural do repositório deve declarar explicitamente que não prova o
runtime de produção.

## 8. Resultado permitido

Toda comunicação e automação deve terminar em um destes estados:

- **COMPROVADO** — evidência independente e verificável;
- **FALHOU** — erro confirmado e preservado;
- **INCONCLUSIVO** — acesso ou evidência insuficiente.

Não existem os estados “provavelmente”, “parece funcionar” ou “sucesso sem
artifact”.

## 9. Auditoria recorrente

A auditoria deve cobrir workflows, hooks, systemd, `agent-bridge`, `ai-system`,
admin PHP, scripts PowerShell raiz, filas, permissões privadas e executores
referenciados. O watchdog de schedule deve verificar também seus artifacts e
ser monitorado de forma cruzada por outro workflow.

Qualquer regressão crítica ou alta bloqueia o run e o merge.
