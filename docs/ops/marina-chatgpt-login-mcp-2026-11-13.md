# Login oficial ChatGPT no Firefox da Marina via MCP

## Objetivo
O controlador ShopVivaliz Remote Control expõe `marina_chatgpt_login` para conduzir as etapas **não secretas** do login Dev na sessão independente de Firefox da VM `marina-a1-4c24g`.

## Contrato
- `session`: somente `dev`.
- `action=status`: retorna o estágio observado na janela oficial sem alterar a página e **sem presumir identidade autenticada**.
- `action=open_login`: navega até `https://chatgpt.com/auth/login` na janela isolada.
- `action=fill_email`: preenche exclusivamente o endereço público da conta Dev, quando a tela de login estiver presente.
- `action=continue`: avança exclusivamente da etapa de e-mail; não pressiona Enter nas etapas de senha ou MFA.

As ações de mutação reaproveitam o lease de manutenção da conta e a concessão administrativa já existente para a Marina. O programa não oferece argumentos com URL arbitrária, texto livre, senha, cookie, token ou OTP. Não lê nem transfere conteúdo de cofres ou perfis de outra VM.

## Implantação e verificação
O auxiliar `scripts/marina_chatgpt_login.py` deve ser instalado com propriedade `ubuntu` em `/home/ubuntu/.local/share/shopvivaliz-marina-chatgpt/marina_chatgpt_login.py`. O controlador utiliza o instalador canônico `scripts/setup-remote-control-access.sh install-controller-code-only`, com o controlador e as dependências do checkpoint da **mesma revisão integrada**. Não editar `/home/ubuntu/shopvivaliz-deploy/current` ou uma release de produção.

Testar `status` na Marina, presença de `marina_chatgpt_login` no `tools/list` autenticado e os testes dirigidos `tests/test_marina_chatgpt_login_tool.py`. Confirmar que Olist e o Firefox mantêm suas sessões independentes.

## Limitação conhecida
As credenciais e o autenticador corporativo conhecidos pertencem a outro perfil/host; **não são transportados para a Marina**. A etapa de senha e eventual MFA permanece na interface oficial até que exista mecanismo legitimamente autorizado de preenchimento seguro no próprio perfil da Marina. A ferramenta nunca retorna `identity_verified=true` apenas porque a página foi aberta.
