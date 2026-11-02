# ShopVivaliz Web Agent (MCP browser takeover)

MVP isolado de navegador controlado pelo ChatGPT, com **handoff humano** para digitar senhas ou códigos diretamente em uma interface de navegador remoto. **Nao esta publicado em producao.**

## Recursos implementados

- MCP Streamable HTTP JSON-RPC em `127.0.0.1:5598/mcp` com ferramentas `web_agent_open`, `web_agent_inspect`, `web_agent_click`, `web_agent_takeover`, `web_agent_status`, `web_agent_close`.
- UI MCP `ui://shopvivaliz-web-agent/takeover.html`: visualizacao JPEG, toque/clique para selecionar campo, entrada humana de texto, teclas Tab/Enter/Backspace/Esc, devolucao de controle ao agente.
- Token exclusivo de 256 bits por handoff, expira em 15 min e nunca e colocado em `content` ou `structuredContent` (somente `_meta`, entregue ao widget).
- Estado de controle `agent` / `human`; durante takeover nao e permitido ao modelo abrir, inspecionar, clicar, fechar ou capturar a pagina. A capacidade humana expirada **nao** reativa o agente: exige novo takeover emitido via MCP.
- Credenciais digitadas no widget sao enviadas diretamente ao host pelo canal de navegador, sem serem emitidas como chamadas de ferramenta ao modelo, sem logs de texto de campos e sem persistencia em estado do widget.
- Playwright Chromium com perfil proprio em `.private-profile` ou `WEB_AGENT_PROFILE_DIR`; NUNCA compartilhar perfil corporate Dev / Atendimento nem CDP existentes.
- Enderecos nao publicos, protocolos locais, loopback, metadata IP, rede privada e portas incomuns sao bloqueados em URL e requisicoes HTTP(S); DNS rebinding exige endurecimento complementar antes de rede publica.

## Uso local de desenvolvimento (nao publico)

Requer Node >=20, Chromium e `npm install --omit=dev --ignore-scripts`. Use um usuario dedicado **sem root**. No mesmo terminal, configure `WEB_AGENT_MCP_TOKEN` com ao menos 32 caracteres aleatorios em secret manager (`umask 077`); nunca inclua o valor na linha de comando, logs ou Git. Opcional: `WEB_AGENT_CHROME_BINARY`, `WEB_AGENT_PROFILE_DIR`, `WEB_AGENT_PORT`. Execute `npm start`. O listener permanece **somente em 127.0.0.1**.

Teste de saude: `curl -fsS http://127.0.0.1:5598/health`. Testes sem rede: `npm test && npm run check`. O browser real exige ligacao ao Chromium e navegacao publica, nao substituida por mocks.

## Fluxo de uso (apos publicacao autenticada e registro do MCP)

1. ChatGPT chama `web_agent_open({url})`, recebe `session_id`.
2. Ao encontrar autenticacao, ChatGPT chama `web_agent_takeover({session_id})` e renderiza o componente MCP.
3. O componente le `window.openai.toolResponseMetadata.mcp_tool_result._meta.handoff` e passa o token apenas em cabecalho `Authorization` diretamente para o endpoint humano. **Nunca usar `callTool` para enviar a senha.**
4. Usuario toca no campo da imagem, digita ou cola senha/TOTP no input da interface e pressiona `Digitar`, `Enter` e `Devolver controle ao agente`.
5. Apenas a pessoa com a capacidade valida pode liberar a sessao. O componente envia ao ChatGPT um follow-up **sem texto sensivel**, para `web_agent_status` e continuidade.

## Implantacao protegida — ainda pendente

O backend da ShopVivaliz (OCI PRINCIPAL, `always-free-arm-1787907847-26`) hospeda o browser; **nao usar a VM antiga excluida**, nao instalar este modulo sobre os perfis Chromium corporativos e nao modificar MCP Marina/Cloudflare admin ingress. Executar em usuario e serviço separados, com supervisor systemd, limite de memoria e egress firewall. Nao instalar direto no checkout legado alterado: usar release limpa, pin de SHA e rollback.

**Bloqueios para publicacao** (P2): aprovar hostname/ingress exclusivo, OAuth/Access deny-by-default, validacao do JWT e RBAC no origin, TLS, CSP/iframe, allowlist de dominio ou isolamento de rede contra DNS rebinding e auditoria de browser; registrar grant formal em PR se exigido. O `WEB_AGENT_MCP_TOKEN` local NAO e credencial OAuth do ChatGPT. Sem essas etapas, nao expor porta nem fornecer URL publica.

**Limites deste MVP:** sem stream de video em tempo real (quadros JPEG 1,4s); sem PDF/download remoto; sem agente autonomo por LLM embutido (ChatGPT utiliza ferramentas). `web_agent_click` ainda pode executar acoes em sites terceiros: exigir escopo/confirmacao para compras, delecoes, postagens e alteracoes de seguranca. Validacao no iPhone/ChatGPT precisa do app publicado e de testes reais, ainda nao concluida.

## Garantias relevantes

A UI usa um input temporario em memoria, com tipo `password` por padrao. O servidor da VM recebe as teclas para redireciona-las ao Chromium (nao e possivel operar o navegador sem transmiti-las); nenhuma senha e colocada em respostas MCP visiveis ao modelo nem gravada explicitamente. O trafego fora do loopback so podera ser utilizado apos publicacao HTTPS autenticada. O operador deve verificar dominio oficial e que a pagina seja de sua propria conta; login, TOTP e CAPTCHA sao realizados pela pessoa sem bypass.
