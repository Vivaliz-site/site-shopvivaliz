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
