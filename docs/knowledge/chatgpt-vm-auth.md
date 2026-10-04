# ChatGPT na VM — autenticação, senha e TOTP

## Estado operacional registrado em 2026-10-04

A VM `always-free-arm-1787907847-26` possui autenticação TOTP local configurada no OTPClient para as duas contas ChatGPT usadas no ambiente:

- `fredmourao` / conta pessoal do Fred;
- `atendimento@shopvivaliz.com.br`.

O OTPClient é executado na sessão gráfica usada pelo RustDesk/ChatGPT em `DISPLAY=:99`, usuário `fredrdp`.

## Regra de segurança

- Nunca registrar em Git, documentação, logs, saída de ferramenta ou chat o seed TOTP, código OTP atual, senha ou conteúdo de qualquer secret.
- Documentar somente que a credencial existe, onde o autenticador está provisionado e como o agente deve consumi-la com segurança.
- A senha do banco do OTPClient e os seeds TOTP permanecem somente na VM e no armazenamento local protegido do aplicativo.

## Regra obrigatória para agentes

Quando uma tarefa exigir login ou MFA nas contas ChatGPT acima, o agente deve usar primeiro as credenciais/TOTP já provisionados na VM. Não deve pedir ao usuário para transcrever senha ou OTP enquanto a fonte local estiver disponível e válida.

O fluxo de automação deve permitir que o agente:

1. abra/focalize a aplicação ou navegador correto na sessão `:99`;
2. obtenha o OTP pela fonte local autorizada;
3. digite senha e OTP no campo focado por uma ação que não persista o valor em logs, argumentos de processo, histórico de shell ou auditoria;
4. confirme o resultado do login sem revelar o segredo usado.

Para navegador, ações de digitação devem manter o valor digitado redigido da auditoria. Para aplicações desktop, qualquer capacidade de `secret typing` deve seguir o mesmo contrato: entrada write-only, sem echo e sem persistência do conteúdo.

Se a ferramenta atual não expuser uma ação segura para digitar um segredo em aplicação desktop, isso é uma lacuna de capacidade do MCP e deve ser corrigida no MCP; não é motivo para voltar a depender rotineiramente da interação do usuário.
