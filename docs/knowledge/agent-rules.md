# Regras para Agentes

## Bootstrap obrigatório de nova sessão

- Antes de qualquer diagnóstico, alteração ou validação, ler `docs/knowledge/host-access.md`.
- Identificar o host correto pelo papel atual; não assumir que IP/nome histórico ainda é produção.
- Para terminal e diagnóstico, preferir os canais remotos canônicos; Desktop Commander é apenas fallback operacional. Para navegador, seguir obrigatoriamente `GLOBAL_BROWSER_VM_POLICY_V2` abaixo.
- Confirmar acesso com evidência (`hostname`, `whoami`, diretório e estado Git quando aplicável).
- Nunca versionar, imprimir ou copiar para documentação o conteúdo de chave privada, senha, token ou secret.


<!-- SUPERPOWERS_EVERY_STAGE_V1 -->
## @Superpowers obrigatório em cada etapa

- Todo chat, conversa, sessão, agente e retomada de tarefa relacionada aos projetos ShopVivaliz deve usar **@Superpowers em cada etapa material**, do primeiro diagnóstico à validação final.
- Não considerar uma única invocação no início como suficiente. Reaplicar o workflow/skill apropriado ao mudar de fase: planejamento, investigação, implementação, debugging, TDD/testes, revisão, correção, PR/merge, deploy, pós-deploy e auditoria.
- Em `retome`, `continue` ou `prossiga`, recuperar o último checkpoint comprovado e continuar sob @Superpowers, sem reiniciar desnecessariamente.
- Se @Superpowers não estiver exposto pelo runtime, não simular a chamada: registrar `SUPERPOWERS_UNAVAILABLE` e aplicar a metodologia equivalente até que a capacidade esteja disponível.
- A fonte central desta regra é `REGRAS-AGENTES-CENTRALIZADAS.md`.

<!-- GLOBAL_BROWSER_VM_POLICY_V2 -->
## Navegação/browser: VM backend obrigatória

- Para qualquer navegação web, automação browser, Playwright/Selenium/CDP, Chrome/Chromium/Edge/Opera, CAPTCHA, MFA, consentimento ou validação visual, usar a VM `always-free-arm-1787907847-26` e o Browser Worker privado.
- Para intervenção humana, usar a interface autenticada `https://shopvivaliz.com.br/admin/browser-worker.php`.
- **Fred-Win (`LAPTOP-NIG4IFUU`) e `DESKTOP-KOCEPSV` não podem ser usados como destino ou fallback de navegação/browser.**
- Não perguntar qual máquina usar para browser: o padrão já está definido. Exceção somente se o proprietário pedir explicitamente, na tarefa atual, um Windows específico para aquela navegação.
- Se a VM estiver indisponível, reparar Browser Worker/túnel/OCI Bastion ou registrar bloqueio real; nunca cair silenciosamente para Windows.
- Windows continua permitido para tarefas não-browser que realmente dependam dele.

## Credenciais, MFA e fontes seguras já provisionadas

- Em projetos ShopVivaliz com autenticação já provisionada, o agente deve primeiro usar as fontes seguras existentes (env/arquivo protegido/systemd/secret/host autenticador) e **não pedir ao usuário novamente usuário, senha ou OTP** sem antes provar que a fonte existente está ausente ou inválida.
- Para Amazon Returns / Seller Central, o browser/bridge roda em `shopvivaliz-free-a1` e o TOTP é fornecido de forma restrita por `always-free-arm-1787907847-26`. O agente deve usar esse caminho automático e nunca depender de o usuário transcrever OTP rotineiramente.
- Antes de reportar `AUTH_REQUIRED`, verificar o runbook do projeto, referências de credencial e canal de MFA já configurados, sem revelar valores secretos.
- Pedir intervenção humana apenas para credencial realmente revogada/ausente, CAPTCHA, recovery, consentimento novo ou outro desafio que tecnicamente não possa ser resolvido pelo fluxo seguro existente.
- Nunca registrar em Git, docs, logs ou chat o conteúdo de senhas, tokens, chaves, seeds TOTP, OTPs ou cookies; documentar somente a localização segura e o procedimento.

## Fonte de conhecimento

- Sempre usar `/docs/knowledge/` como base inicial para diagnóstico e operação.
- Confirmar o comportamento no código, workflow, log ou resposta real quando a documentação não for suficiente.
- Nunca assumir uma resposta sem evidência.
- Informar claramente quando a evidência estiver incompleta, ambígua ou desatualizada.

## Diagnóstico

## Semântica de health/systemd

- Nunca declarar "backend inteiro inativo" ou equivalente apenas porque `systemctl is-active` retornou `inactive` para uma lista de nomes.
- Consultar `LoadState`, `ActiveState`, tipo da unidade e o runtime canônico em `host-access.md`.
- `LoadState=not-found` para nome aposentado é ausência esperada, não falha.
- Unidades `oneshot` podem ficar `inactive` após sucesso; validar timer/gatilho e `Result=success`/`ExecMainStatus=0`.
- `mei-mg-email-worker.service` inativo com `/var/lib/mei-mg-email/sender_blocked.pause` presente é estado fail-closed deliberado; não reiniciar automaticamente.
- Preferir `scripts/runtime-service-status.sh` ou a ação remota `runtime_status` em vez de inventários manuais de nomes históricos.

- Identificar o erro antes de sugerir a solução.
- Registrar método HTTP, URL, status, corpo da resposta e etapa do fluxo afetada.
- Não tratar 404, 405, 500, CORS e DNS como o mesmo problema.
- Não declarar que produção, deploy, banco, preço, imagem ou integração estão corretos sem teste verificável.

## AUDITORIA_FUNCIONAL_PRODUCAO

- HTTP 200 nao prova funcionamento. Arquivo presente, endpoint existente, secret configurado, schema valido ou pagina renderizada sao apenas evidencias estruturais.
- Auditoria local, lint, unit test e smoke estrutural nunca podem declarar a loja funcional em producao.
- Para declarar producao funcional, executar `scripts/production-functional-audit.sh` contra `https://shopvivaliz.com.br` e exigir `PRODUCTION_FUNCTIONAL_AUDIT=PASS`.
- A auditoria obrigatoria deve usar produto real disponivel e percorrer, no minimo: catalogo, carrinho, cotacao real de frete, checkout, health de pedidos e integracoes criticas.
- Melhor Envio, Olist e Mercado Pago so podem ser considerados saudaveis quando o provider real aceitar a credencial e responder ao probe funcional previsto.
- `configured=true`, token presente ou variavel de ambiente preenchida nao provam autenticacao nem funcionamento.
- qualquer falha critica deve produzir FAIL. Nao converter falha critica em `attention`, `warning`, sucesso parcial ou nota percentual capaz de resultar em status saudavel.
- Se a auditoria funcional nao puder ser executada por falta de credencial, conectividade ou ambiente, o resultado e INCONCLUSIVO/FAIL, nunca PASS.
- Relatorios devem separar explicitamente `STRUCTURAL`, `INTEGRATION`, `FUNCTIONAL` e `TRANSACTIONAL`.
- Nenhum agente, workflow, monitor, Claude, Codex ou automacao pode substituir o gate funcional por verificacao superficial.

## Validação do Squad Chat

Considerar o health válido somente quando todos os requisitos forem atendidos:

- `ok=true`
- `endpoint=squad-chat`
- campo `providers` presente

O campo `configured` indica configuração detectada, mas não prova que a credencial foi aceita pelo provider.

## Credenciais e segurança

- Sempre usar variáveis de ambiente ou GitHub Secrets.
- GitHub Secrets são write-only; nunca tentar recuperá-los em texto.
- Nunca hardcodar, registrar ou exibir senhas, tokens, chaves de API ou dados bancários.
- Não contornar políticas de segurança do navegador, CORS, autenticação ou controles de acesso.
- Não executar deleções destrutivas em FTP ou banco sem autorização explícita e backup.

## Catálogo e integrações

- Não inventar preço, estoque, frete, imagem ou disponibilidade.
- Não alterar campos comerciais em automações de anúncios sem evidência da fonte oficial.
- Ignorar ou sinalizar produtos sem estoque conforme a regra do canal.
- Vincular imagens por identificador confiável, preferencialmente SKU ou ID da origem.
- Distinguir falha de interface de falha de sincronização ou ausência de dados.

## Atualizações

- Produzir atualizações cumulativas para permitir pular versões intermediárias.
- Incluir automaticamente SQLs, migrations e reparos de vínculo necessários.
- Tornar migrations idempotentes e registrar as que foram executadas ou ignoradas.
- Executar preflight, backup, cópia, migrations, reparos e testes na mesma atualização.
- Não exigir abertura manual de links para concluir a instalação.
- Fazer merge apenas quando as alterações estiverem consistentes e validadas.

## Autonomia

Tomar decisões autônomas dentro do escopo autorizado, mas interromper ações destrutivas, irreversíveis ou sem evidência suficiente. Autonomia não substitui validação.
