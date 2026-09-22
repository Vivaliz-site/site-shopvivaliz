# Regras para Agentes

## Bootstrap obrigatório de nova sessão

- Antes de qualquer diagnóstico, alteração ou validação, ler `docs/knowledge/host-access.md`.
- Identificar o host correto pelo papel atual; não assumir que IP/nome histórico ainda é produção.
- Preferir SSH privado/Tailscale com usuario dedicado `shopvivaliz-agent` para operacao de agentes; usar RustDesk para tarefas graficas; Desktop Commander fica somente como fallback.
- Confirmar acesso com evidência (`hostname`, `whoami`, diretório e estado Git quando aplicável).
- Nunca versionar, imprimir ou copiar para documentação o conteúdo de chave privada, senha, token ou secret.


<!-- SUPERPOWERS_EVERY_STAGE_V1 -->
## @Superpowers obrigatório em cada etapa

- Todo chat, conversa, sessão, agente e retomada de tarefa relacionada aos projetos ShopVivaliz deve usar **@Superpowers em cada etapa material**, do primeiro diagnóstico à validação final.
- Não considerar uma única invocação no início como suficiente. Reaplicar o workflow/skill apropriado ao mudar de fase: planejamento, investigação, implementação, debugging, TDD/testes, revisão, correção, PR/merge, deploy, pós-deploy e auditoria.
- Em `retome`, `continue` ou `prossiga`, recuperar o último checkpoint comprovado e continuar sob @Superpowers, sem reiniciar desnecessariamente.
- Se @Superpowers não estiver exposto pelo runtime, não simular a chamada: registrar `SUPERPOWERS_UNAVAILABLE` e aplicar a metodologia equivalente até que a capacidade esteja disponível.
- A fonte central desta regra é `REGRAS-AGENTES-CENTRALIZADAS.md`.

## Credenciais, MFA e fontes seguras já provisionadas

- Em projetos ShopVivaliz com autenticação já provisionada, o agente deve primeiro usar as fontes seguras existentes (env/arquivo protegido/systemd/secret/host autenticador) e **não pedir ao usuário novamente usuário, senha ou OTP** sem antes provar que a fonte existente está ausente ou inválida.
- Para Amazon Returns / Seller Central, o browser/bridge roda em `shopvivaliz-free-a1` e o TOTP é fornecido de forma restrita por `always-free-arm-1787907847-26`. O agente deve usar esse caminho automático e nunca depender de o usuário transcrever OTP rotineiramente.
- Antes de reportar `AUTH_REQUIRED`, verificar o runbook do projeto, referências de credencial e canal de MFA já configurados, sem revelar valores secretos.
- Pedir intervenção humana apenas para credencial realmente revogada/ausente, CAPTCHA, recovery, consentimento novo ou outro desafio que tecnicamente não possa ser resolvido pelo fluxo seguro existente.
- Nunca registrar em Git, docs, logs ou chat o conteúdo de senhas, tokens, chaves, seeds TOTP, OTPs ou cookies; documentar somente a localização segura e o procedimento.

## Navegador e pesquisa técnica

- Para tarefas ShopVivaliz, navegador de agente deve executar na VM de navegação. Não usar Opera Connector nem navegador dos hosts Windows como caminho operacional.
- Em programação, infraestrutura, APIs, bibliotecas, frameworks, cloud, segurança e integrações externas, consultar a web quando versão/comportamento atual puder alterar a solução.
- Priorizar documentação oficial, especificações, release notes/changelogs e repositórios oficiais; complementar com issues/fóruns técnicos apenas quando necessário e deixando claro o nível de autoridade da fonte.
- Não assumir flags CLI, endpoints, modelos, parâmetros, limites, deprecações ou comportamento de SDK/API sem verificar quando isso for material à implementação.
- Pesquisa web não substitui validação local: confrontar a fonte externa com versão instalada, código real, testes e runtime.
- Se a web estiver indisponível e a informação atual for necessária, marcar como não verificada em vez de adivinhar.

## Fonte de conhecimento

- Sempre usar `/docs/knowledge/` como base inicial para diagnóstico e operação.
- Confirmar o comportamento no código, workflow, log ou resposta real quando a documentação não for suficiente.
- Nunca assumir uma resposta sem evidência.
- Informar claramente quando a evidência estiver incompleta, ambígua ou desatualizada.

## Diagnóstico

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
- **BROWSER E2E OBRIGATÓRIO:** se o fluxo possui UI, o próprio agente deve executar o caminho de ponta a ponta no navegador real da VM, contra o mesmo release/ambiente certificado. Seguir `docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md`.
- `scripts/production-functional-audit.sh`, `curl`, API direta, SQL, testes automatizados e healthchecks complementam a evidência, mas **não substituem** cliques, formulários, navegação, submissão, reload/revisita e confirmação de persistência pela UI real.
- É proibido encerrar a validação pedindo ao usuário para executar o browser ou enviar screenshot quando o agente possui acesso técnico ao ambiente. Screenshot do usuário pode complementar, nunca substituir, o E2E do agente.
- Execução somente headless ou screenshot sem percorrer o fluxo completo não certifica a UI. Para auditoria formal, usar a sessão gráfica real da VM de navegação, salvo impossibilidade técnica comprovada; nesse caso o resultado é `NÃO VALIDADO/INCONCLUSIVO`, nunca `APTO`.

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
