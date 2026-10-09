# ChatGPT na VM — autenticação, senha e TOTP

## Estado operacional registrado em 2026-10-04

As duas contas ChatGPT operacionais após a migração são:

- `dev@shopvivaliz.com.br` — engenharia/desenvolvimento;
- `atendimento@shopvivaliz.com.br` — atendimento/operação corporativa.

A referência antiga `fredmourao` é legado de migração e não deve ser usada para novos logins. A existência de senha/TOTP para `dev` só pode ser tratada como provisionada após validação da fonte segura local; nunca inferir a partir da documentação.

O registro legado de 2026-10-04 indicava `DISPLAY=:99`, usuário `fredrdp`. **Não usar esse par como valor fixo.** Em diagnóstico de 2026-10-08, o OTPClient em execução estava sob `fredconsole` e `DISPLAY=:0`, confirmado pelo UID do processo e pelas variáveis de ambiente, e havia uma janela gráfica visível. O agente deve descobrir a sessão efetiva do processo OTPClient a cada autenticação, validar que a janela pertence ao mesmo UID/display e nunca trocar de sessão por inferência. A existência de janela não comprova que o cofre esteja desbloqueado ou contenha um TOTP válido.

## Regra de segurança

- Nunca registrar em Git, documentação, logs, saída de ferramenta ou chat o seed TOTP, código OTP atual, senha ou conteúdo de qualquer secret.
- Documentar somente que a credencial existe, onde o autenticador está provisionado e como o agente deve consumi-la com segurança.
- A senha do banco do OTPClient e os seeds TOTP permanecem somente na VM e no armazenamento local protegido do aplicativo.

## Regra obrigatória para agentes

Quando uma tarefa exigir login ou MFA nas contas ChatGPT acima, o agente deve usar primeiro as credenciais/TOTP já provisionados na VM. Não deve pedir ao usuário para transcrever senha ou OTP enquanto a fonte local estiver disponível e válida.

O fluxo de automação deve permitir que o agente:

1. identifique o UID e `DISPLAY` efetivos do processo OTPClient e focalize sua janela nessa sessão, separadamente do display do navegador ChatGPT;
2. obtenha o OTP pela fonte local autorizada;
3. digite senha e OTP no campo focado por uma ação que não persista o valor em logs, argumentos de processo, histórico de shell ou auditoria;
4. confirme o resultado do login sem revelar o segredo usado.

Para navegador, ações de digitação devem manter o valor digitado redigido da auditoria. Para aplicações desktop, qualquer capacidade de `secret typing` deve seguir o mesmo contrato: entrada write-only, sem echo e sem persistência do conteúdo.

Se a ferramenta atual não expuser uma ação segura para digitar um segredo em aplicação desktop, isso é uma lacuna de capacidade do MCP e deve ser corrigida no MCP; não é motivo para voltar a depender rotineiramente da interação do usuário.



## Persistencia do OTPClient apos reinicio da VM

A fonte local autorizada de MFA deve continuar criptografada em
`/home/fredrdp/.local/share/otpclient.enc` (permissoes restritas ao dono).
O servico `shopvivaliz-otpclient.service` executa somente a interface
`/usr/bin/otpclient` como `fredrdp` no display `:99`, condicionado
ao socket X11 e a existencia do cofre. A unidade suporta tanto o
provedor de display `shopvivaliz-virtual-display.service` do repositorio
como `shopvivaliz-xvfb99.service` instalado no backend durante a recuperacao.
A instalacao aprovada e idempotente usa
`bash scripts/setup-shopvivaliz-otpclient.sh` a partir do clone canônico,
com acesso root via MCP autorizado. A instalação **não lê a senha do cofre**
e nao fornece MFA por si so.

Validar separadamente: (1) unidade `enabled` e `active`,
(2) processo pertencente a `fredrdp`, (3) socket e perfil certos,
(4) cofre criptografado intacto, (5) desbloqueio do cofre efetivamente
autorizado e (6) identidade da conta ChatGPT validada na sessao correta.
Se o cofre ou GNOME Keyring permanecer bloqueado após o reboot, registrar
`VAULT_LOCKED`, **nao tentar contornar a senha ou a criptografia**.
O usuário pode desbloquear uma vez por meio da interface oficial protegida
ou provisionar um mecanismo de desbloqueio automático autorizado e seguro.
Nunca transferir senha, seed TOTP, OTP ou chave do cofre para logs, Git,
argumentos de processos ou conversa.

## Gmail no controlador autonomo: permissao e isolamento

O recebimento de codigo ChatGPT destinado a `dev@shopvivaliz.com.br`
na caixa Gmail conectada ao ChatGPT **nao** demonstra que o backend tenha
autorizacao OAuth independente para ler essa caixa. Para operar sem conversa
ativa, exigir concessao propria e verificavel de **Gmail readonly** ao servico,
com refresh autorizado em armazenamento protegido e acesso estritamente
limitado a conta/remetentes/destinatarios esperados. Nunca reutilizar o
OAuth de Google Ads por presumir que inclui escopo Gmail.

O cliente em `includes/amazon-returns/GmailApi.php` seleciona somente
uma familia completa `GMAIL_OAUTH_*` ou `GOOGLE_OAUTH_*`; misturar
client ID/secret e refresh token de concessoes distintas e invalido.
`SvAmazonReturnsConfig::readiness()['gmail']['ready']` comprova apenas
a completude da configuracao, **nao** autentica nem valida escopo.
A prova de disponibilidade e uma chamada real da Gmail API na caixa
esperada, com permissoes verificadas e sem registrar tokens/codigos.

O consentimento Google, quando exigido, usa exclusivamente o fluxo OAuth
oficial e uma autorizacao inicial legitima. Tokens invalidados ou revogados
nao podem ser recuperados nem ter MFA burlado por inferencia. Enquanto a
autorizacao Gmail estiver ausente, apenas a etapa dependente de email fica
em espera de autenticacao; tarefas independentes continuam via worker
normal sob leases exclusivos e invariantes de conta/conversa. Nenhum
codigo temporario deve aparecer em logs, comandos, PRs ou auditorias.
