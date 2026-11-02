# MCP Marina por Cloudflare Tunnel existente — decisao atual (2026-11-02)

## Fonte de verdade / escopo
Decisao atual do proprietario: **reutilizar o mesmo Cloudflare Tunnel** ja conectado a `marina-a1-4c24g`, identificado na documentacao de checkpoint como `shopvivaliz-oci-marina-admin` e executado pela unit `shopvivaliz-cloudflared-admin.service`. **Adicionar um segundo hostname/ingress especifico para MCP**, sem criar novo tunnel nem instalar o `tunnel-client` OpenAI. O ingress administrativo atual `admin-marina.shopvivaliz.com.br` deve permanecer inalterado e isolado. Nao usar backend/RDC como relay para trafego normal do MCP.

O servidor MCP existente executa `shopvivaliz-marina-mcp.service` como usuario sem root, `NoNewPrivileges=yes`, loopback `127.0.0.1:5580`, release imutavel `a8ccd5b4904918af065efecc1150c60b25a095a0` (E2E local validado anteriormente). Sao 6 tools de leitura e 1 escrita limitada ao workspace privado, controlada pelo grant humano #3514, CAS, lease/fencing, audit e modos 0600. A identidade da release precisa ser revalidada antes de qualquer mudanca. **Nao habilitar shell/root universal** nem alterar producao.

## Endereco e protocolo propostos
- Cloudflare existente: `shopvivaliz-oci-marina-admin` (conferir tunnel ID e conta exatos no painel/API antes de configurar).
- Novo hostname: `mcp-marina.shopvivaliz.com.br`, endpoint do ChatGPT `https://mcp-marina.shopvivaliz.com.br/mcp`.
- Novo **published application route** no MESMO tunnel: origin `http://127.0.0.1:5580` — a Cloudflare encaminha o path `/mcp` intacto, sem rewrite. Nao incluir `/mcp` no Service URL a menos que o painel/teste documente rewrite.
- Nenhum TCP 5580 publico, nenhum listener 0.0.0.0, nenhuma alteracao em 443/Apache, sem alterar rota SSH/admin, Cloudflare token de conector, dominio Returns ou DNS comercial.

## Gates obrigatorios, em ordem
1. **Inventario read-only**: comprovar o hostname/UUID/owner/account do tunnel e configuracao de ingress; verificar `admin-marina.shopvivaliz.com.br` funcional, Access atual e ausencia de colisao DNS de `mcp-marina`. Verificar estado local do MCP, user, NNP, listener e release. No probe de 2026-11-02, `cloudflared-admin=active/enabled`, `marina-mcp=active/disabled`, GET local sem bearer = 401; o novo hostname nao resolvia. Isso NAO certifica endpoint publicado.
2. **Politica antes de exposicao**: criar/verificar Access app MCP **separada**, escopo minimo, deny-by-default, MFA/IdP oficial, nunca herdar permissao do app administrativo. Configurar **Managed OAuth** se compativel. Cloudflare exige que o **origin valide o JWT assinado em `Cf-Access-Jwt-Assertion`**; o servidor Marina atual exige um bearer estatico local e NAO ha prova de validacao Access JWT. Implementar e testar adapter/origin verificador com audience/issuer/assinatura/expiracao/scopes e origem confiavel; rejeitar header injetado por cliente ou JWT fora de escopo. Preservar o bearer local so em secret 0600, jamais como credencial enviada pelo ChatGPT.
3. **Aprovacao e controles**: eventual nova publicacao de superficie/capacidade exige PR de seguranca com grant formal especifico de `shopvivaliz-human`, verificacao HEAD, checks, ownership/lease e rollback. A PR #3514 ja aprovada cobre **somente** escrita privada atual; nao solicitar reaprovacao nem interpretá-la como outorga de ingress publico. Autorizacoes Cloudflare Access e workspace ChatGPT sao controles independentes.
4. **Rota remota**: somente apos testes negativos de Access/OAuth e preflight, adicionar o hostname ao tunnel EXISTENTE; conferir configuracao e DNS publicados por controle oficial. A Cloudflare suporta varios aplicativos/hostnames por um tunnel; nao criar conector ou token duplicado.
5. **E2E**: confirmar que requisicao anonima nao obtem MCP e recebe desafio OAuth adequado, endpoint HTTPS valida metadados, login/consentimento oficial pela conta/workspace ChatGPT, autentica e executa `initialize`, `tools/list` (7 tools), 6 leituras, 1 escrita E2E limitada ao workspace somente com grant valido, readback e negativas de replay/stale-CAS, logs redigidos e persistencia 0600. Testar relogin/reconexao sem RDC/backend, revogacao de Access e restore do processo/tunnel. Conferir site/DB/workers inalterados.
6. **Rollback restrito**: remover SOMENTE hostname/Access app MCP criados, preservar tunnel/ingress administrativo, configuracao de conector e release MCP ja ativa. Sem politica e OAuth validados, manter endpoint nao publicado e issue #3152 aberta `NAO_APTO`.

## Referencias oficiais
- https://developers.cloudflare.com/tunnel/concepts/routing/ (um tunnel suporta multiplos hostnames)
- https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/managed-oauth/ (Managed OAuth MCP, JWT no origin)
- https://developers.cloudflare.com/cloudflare-one/access-controls/ai-controls/secure-mcp-servers/
- https://developers.openai.com/plugins/build/auth (ChatGPT usa OAuth/PKCE; nao envia chaves arbitrarias)
- https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt

## Rastreio
- https://github.com/Vivaliz-site/site-shopvivaliz/issues/3152
- https://github.com/Vivaliz-site/site-shopvivaliz/pull/3514 (grant humano restrito a escrita local)
- https://github.com/Vivaliz-site/site-shopvivaliz/pull/3517 (release MCP local)
