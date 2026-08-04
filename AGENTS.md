# Guia obrigatório para agentes

**Fonte normativa:** [`REGRAS-AGENTES-CENTRALIZADAS.md`](REGRAS-AGENTES-CENTRALIZADAS.md)

Este arquivo resume os limites aplicáveis a qualquer agente, bot, workflow,
script, daemon ou integração que opere neste repositório.

## Fluxo obrigatório

1. Trabalhar sempre em branch não protegida.
2. Produzir alteração real e diff limitado ao escopo.
3. Executar testes aplicáveis e guardar os códigos de saída.
4. Abrir pull request com commit SHA, testes, riscos e artefatos.
5. Aguardar todos os checks obrigatórios.
6. Obter ao menos uma revisão **humana, independente e `APPROVED`**.
7. Somente um mantenedor humano diferente do autor/agente pode concluir o merge.
8. Validar o deploy por SHA, logs e leitura posterior independente.

## Proibições

- agente, bot ou autor do PR aprovar o próprio trabalho;
- auto-merge, merge por bot, merge antes da aprovação humana ou bypass de proteção;
- push direto em `main`/`master`, force-push ou refspec protegido;
- `git reset --hard`, `git clean -fd`, exclusão destrutiva ou staging amplo;
- mudar fila para `in_progress`, `completed` ou equivalente antes do trabalho real;
- declarar execução, sucesso, health ou conclusão sem evidência verificável;
- mascarar erro com `|| true`, `|| log`, `continue-on-error` ou saída zero artificial;
- expor ou versionar secrets, tokens, chaves, dados pessoais ou credenciais;
- executar deploy, Git ou sobrescrita de código por endpoint web;
- alterar preço, estoque, campanha, orçamento, pagamento ou produção fora de fluxo aprovado.

## Estados permitidos

Todo resultado deve ser um destes:

- **COMPROVADO:** evidência independente vinculada ao SHA e ao run;
- **FALHOU:** erro ou código diferente de zero preservado;
- **INCONCLUSIVO:** não foi possível obter a evidência necessária.

`idle`, processo ativo, arquivo existente, heartbeat ou mensagem textual não são
prova de funcionamento.

## Evidência mínima

Uma conclusão precisa conter, conforme o tipo de trabalho:

- origem e identificador da tarefa;
- commit SHA e pull request;
- diff ou lista exata de arquivos;
- comandos e códigos de saída;
- testes e resultados;
- artifact ou relatório imutável ligado ao run;
- verificação independente/read-back;
- aprovação humana quando houver mudança versionada;
- SHA esperado e observado quando houver deploy.

Na ausência de qualquer evidência obrigatória, a rotina deve falhar fechada e
não pode alterar fila, publicar código ou reportar sucesso.
