# Marina — recuperação pontual do ML Pricing (Issue #3038)

Status em 2026-10-19 UTC: **PENDENTE / NO_CUT**. Este procedimento é revisão operacional, **não** autorização para executar pela automação, pelo RDC com elevação bloqueada, por GitHub Actions ou por outro caminho que contorne uma recusa explícita da plataforma.

## Causa raiz validada

- O worker `ml-pricing-worker.service` está `inactive/disabled` na Marina. Ao iniciá-lo, `DoctrineReceiver` retorna SQLSTATE 2002 e o systemd tenta reiniciar continuamente; a operação foi interrompida e o serviço ficou desligado.
- O arquivo protegido `ml-pricing-api-deploy/shared/runtime.env` aponta para MySQL `127.0.0.1:13312`, mas a porta não está aberta na Marina. O MySQL local em `127.0.0.1:3306` contém 27 tabelas e 16 registros de migração no schema `ml_pricing_prod`.
- As credenciais já provisionadas não autenticam nesse MySQL (SQLSTATE 1045), pois a conta configurada não está criada nessa instância. A origem possui o banco próprio na `13312`, com seu worker desligado e o fence root ativado.
- A instância do MCP/RDC de longa duração roda no cgroup `shopvivaliz-desktop-commander.service` com `NoNewPrivileges=true`. **`blockedCommands=[]` não concede root** e não muda esse isolamento. Não reconfigurar o RDC para permitir elevação irrestrita nem usar outro executor/runner para repetir uma operação recusada.

## Pré-requisitos de liberação administrativa

Um administrador habilitado **pelos mecanismos oficiais de Oracle Cloud** deve abrir um login interativo no host `marina-a1-4c24g` (por SSH autorizado ou console da instância), com autenticação e `sudo` previstos pela política do host. O agente não deverá executar o reparo por um transporte alternativo para contornar um bloqueio anterior.

Confirme na fonte de verdade do controlador que `/etc/shopvivaliz/migration/origin-workers-fenced` está presente na **origem** e que o worker `ml-pricing-worker.service` nela permanece `inactive/disabled`. Verifique o estado atual de backups, versão e ausência de writers concorrentes. Recolha, na mesma janela de 10 minutos, o hash SHA256 do JSON das contagens de **todas as 27 tabelas** em ordem lexical da origem; compare com a Marina. A igualdade das contagens é necessária, mas não substitui reconciliação de conteúdo/transações para concluir a migração.

## Reparação assistida, uma única vez

O script do PR correspondente, `scripts/ops/marina_pricing_repair.php`, é **inerte até sua execução manual**. Deve ser revisado, transferido pelo canal oficialmente aprovado e conferido com o SHA do código antes do uso. Nenhum workflow de CI nem deploy o invoca automaticamente.

O operador, de dentro de uma sessão **interativa root autorizada**, executa primeiro:

```bash
php /caminho/aprovado/marina_pricing_repair.php --check
```

Se o preflight de isolamento, integridade do banco e credenciais seguras estiver formalmente aprovado, executar no mesmo terminal autenticado:

```bash
sudo php /caminho/aprovado/marina_pricing_repair.php --apply \
  --confirm=MARINA_PRICING_ONCE --source-fence-verified \
  --source-fingerprint=<SHA256_FRESCO_DAS_CONTAGENS_ORIGEM> \
  --source-checked-at=<UTC_RECENTE_ISO8601>
```

**Nunca** substituir placeholders por senhas ou tokens. Somente informações não secretas vão nos argumentos. O programa lê o usuário e a senha de `/home/ubuntu/ml-pricing-api-deploy/shared/runtime.env` **sem imprimi-los**, verifica igualdade das contagens e desativa a execução se o comprovante da origem tiver mais de 10 minutos. O script exige root + TTY interativo, aborta em host errado, backup inseguro, drift de arquivo ou logging geral do MySQL ativo.

No MySQL local, se a conta ainda não existir, cria exclusivamente `usuario_do_runtime@127.0.0.1` com a senha **já provisionada**. Concede `SELECT, INSERT, UPDATE, DELETE` somente em `ml_pricing_prod.*` (sem SUPER ou GRANT OPTION). Depois valida o login real na porta 3306, faz cópia do `runtime.env` com modo 0600 e substitui atomicamente **apenas `:13312` por `:3306`**. Finalmente, inicia **somente** `ml-pricing-worker.service`, testa que permaneceu ativo e deixa o enable-on-boot inalterado. Em caso de falha após a alteração, para o worker e restaura a configuração anterior quando o hash confirma ausência de mudança concorrente.

O script **não** copia bancos entre VMs, não altera outro serviço, não envia mensagens comerciais, não habilita `amazon-returns-safet` para reboot, não remove a origem, não faz DNS/cutover e não transfere segredos. Uma conta criada e restrita pode permanecer em caso de falha; não apagá-la sem conciliação/revisão.

## Validações pós-operação

1. Exigir `PRICING_WORKER_ACTIVE=PASS` no terminal, `systemctl is-active ml-pricing-worker.service == active` novamente após 2–5 minutos e sem erros novos do Doctrine. Confirmar que o backup protegido e o `runtime.env` preservaram proprietário/mode.
2. Consultar o endpoint de Pricing com o **caminho que verifica o banco e o Messenger**, e verificar uma tarefa **somente leitura** na fila, se permitida, com idempotência. HTTP 200 genérico de `/v1/health` isoladamente não valida o worker.
3. Revalidar que a origem permaneceu fenced e desabilitada. Não habilitar os serviços no boot sem gate/rollback específico e sem testar não duplicação após reboot.
4. Registrar hashes, data, comprovante da conta autorizada e evidência de testes no `STATUS.md` e no gate matrix. Manter **NO_CUT/NAO_APTO** até reconciliação transacional MLRR/Supabase, demais E2E, integridade e rollback.

## Mudanca concorrente observada as 00:38-00:41 UTC de 19/10 (gate vinculante)

**NÃO EXECUTAR `--apply` enquanto houver listener em `127.0.0.1:13312`.** Outro executor iniciou o contêiner de teste `marina-pricing-validation` às 00:38 UTC e ele abriu o MySQL via docker-proxy em **`0.0.0.0:13312` e `[::]:13312`**. A credencial preexistente no `runtime.env` autenticou neste contêiner; as 27 tabelas, 16 migrações e o fingerprint SHA256 das **contagens por tabela** coincidiram exatamente com a origem: `267a683a1b688460b06503d16436e9a4b0d7ba9d525885d263a9d1c7a277d1b1`. O TCP externo a partir da origem até a porta 13312 do IP público de Marina teve timeout (isso NÃO certifica a NSG/VCN/firewall completa).

Esse contêiner ainda tem nome e função de **validação**; deve permanecer segregado do serviço produtivo até conferência oficial dos volumes, persistência após boot, ownership, network ingress em IPv4/IPv6, lease de writer e igualdade de conteúdo/transaction log. Seu bind wildcard merece revisão de segurança. A ação de inicializar ou parar o contêiner é uma mutação administrativa sujeita aos controles próprios; não fazer por outro transporte para contornar recusas anteriores.

O código do reparo inclui agora dois gates fail-closed que recusam a transferência de porta ao MySQL nativo se a `13312` estiver respondendo, inclusive ao segundo check imediatamente anterior a qualquer edição do `runtime.env`. Assim, enquanto o contêiner estiver ativo, o PR #3039 é uma **alternativa de recuperação sob decisão técnica pendente**, não uma ação pronta para aplicação. A autoridade deve escolher **um** destino para o Pricing:

1. **Promover a instância isolada de Pricing** somente depois de procedimento oficial que preserve backups/volumes, recrie a publicação apenas no loopback, prove restart seguro, retenção de dados e worker único; ou
2. **Usar o MySQL nativo `3306`**, após desligamento controlado do contêiner de validação e reconciliação de dados, provisionar a conta limitada e executar o reparo deste PR via sessão de operador realmente autorizada.

Não prosseguir em ambas as rotas simultaneamente. Manter `ml-pricing-worker.service` parado, `amazon-returns-safet.service` com todas as flags de escrita externa OFF, origem fenced e gate global `NO_CUT` até os testes.

