# Relatorio Trio IA — Modo: Diagnóstico de falha no deploy

## Relatorio parcial

**Gemini:** A ShopVivaliz, um e-commerce PHP hospedado na HostGator, utiliza um fluxo de deploy e testes que envolve GitHub Actions e agentes de IA, o que adiciona complexidade e pontos de falha potenciais. A análise abaixo detalha o fluxo e aponta erros típicos de infraestrutura da HostGator que podem causar as falhas mencionadas.

---

### Análise do Fluxo de Deploy e Testes da ShopVivaliz

**1. Fluxo de Deploy (`.github/workflows/deploy.yml`):**

*   **Trigger:** O deploy é acionado por pushes na branch `main` (com algumas exclusões) ou manualmente via `workflow_dispatch`.
*   **Validação de Secrets:** O primeiro passo crucial é a validação dos secrets obrigatórios: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_REMOTE_DIR`. Secrets opcionais (DB_*, FTP_PORT) também são verificados.
    *   **Ponto Crítico:** Se qualquer um dos secrets FTP obrigatórios estiver ausente ou incorreto no GitHub, o deploy falhará *antes mesmo de tentar se conectar à HostGator*.
    *   `FTP_REMOTE_DIR` é vital; se estiver incorreto, os arquivos serão enviados para o lugar errado no servidor.
*   **Ausência do Passo de Deploy FTP:** O `.github/workflows/deploy.yml` fornecido **não contém o passo real de FTP deploy**. Ele apenas valida os secrets. Assumo que há um job `deploy` posterior (não incluído no snippet) que utilizaria esses secrets para transferir os arquivos via FTP. Sem esse passo, o diagnóstico é limitado ao pré-deploy.

**2. Configuração dos Agentes de IA (`.codex/config.toml` e `AGENTS.md`):**

*   **Autonomia dos Agentes:** O sistema ShopVivaliz está fortemente integrado com agentes de IA (AutoDev, GPT, Claude, Gemini) com modo "danger-full-access" em seus sandboxes e acesso à rede.
*   **Regras de Governância vs. Workflow:** O documento `AGENTS.md` estabelece claramente "Nunca fazer deploy sem autorização explícita" e lista "necessidade de deploy" como condição para intervenção humana.
    *   **Conflito Potencial:** O workflow de GitHub Actions, sendo `on: push`, sugere um deploy *automático*. Se a "autorização explícita" não for um passo intermediário entre o `push` e a execução do deploy propriamente dito na HostGator, há um desalinhamento. O `workflow_dispatch` permite um deploy manual, que pode ser a autorização explícita.
*   **Testes e Diagnóstico Pós-Deploy:** `require_self_test` em `.codex/config.toml` e as notas de versão em `config/shopvivaliz-version.php` mencionam "diagnostico consistente apos deploy" e "versiona update-applied-check e auto-routines no repositorio para diagnostico consistente".
    *   **Importância:** Isso indica que há mecanismos para verificar a saúde da aplicação após o deploy. As "testes de rede que falharam" provavelmente são resultados desses diagnósticos pós-deploy.

**3. Configuração da Aplicação (Arquivos PHP):**

*   **`config/shopvivaliz-version.php`:** Define a versão, canal (`dev`), e lista diversas funcionalidades e integrações (Olist/Tiny, Melhor Envio, Pagar.me, Shopee Media Space).
    *   **Dependências de Rede:** Muitas dessas integrações são APIs externas, o que implica em chamadas de rede que dependem de conectividade, URLs corretas e certificados SSL válidos.
    *   **Injeção de Secrets:** "Prepara o deploy para injetar secrets de Melhor Envio e Pagar.me no .env temporario do servidor". Isso sugere que o processo de deploy modifica ou cria um `.env` no servidor.
*   **`admin/squad-chat.php`:** Um painel administrativo que carrega o `.env` do diretório pai (`dirname(__DIR__) . '/.env'`) e injeta o `SQUAD_TOKEN` na sessão.
    *   **Dependência do `.env`:** A localização e a existência do `.env` no servidor são críticas para que esta funcionalidade funcione. Se o deploy não envia o `.env` ou o coloca no local errado, o token não será carregado.
    *   **Variáveis de Ambiente:** Usa `getenv()`, que depende de como o PHP-FPM ou Apache da HostGator está configurado para expor variáveis.
*   **`api/agent/squad-chat.php`:** Um endpoint de API simples para comunicação entre agentes.
    *   **Acessibilidade:** Este endpoint precisa ser acessível publicamente ou internamente através de uma URL correta, e a configuração do servidor (Apache, .htaccess) deve rotear a requisição para este arquivo PHP.

---

### Erros Típicos de Infraestrutura Web na HostGator (Relacionados ao ShopVivaliz)

Com base na análise, os "testes de rede que falharam" e o deploy podem ser impactados pelos seguintes problemas comuns na HostGator:

**1. Permissões FTP e Caminhos de Deploy:**

*   **Erro de `FTP_REMOTE_DIR`:**
    *   **Problema:** O secret `FTP_REMOTE_DIR` no GitHub Actions aponta para um diretório incorreto na HostGator. Por exemplo, apontar para `/public_html` quando o site deve estar em `/public_html/shopvivaliz`, ou vice-versa.
    *   **Impacto:** Arquivos do deploy são enviados para o lugar errado, resultando em 404 Not Found para o site, ou o site carrega parcialmente (sem CSS/JS/imagens) se os arquivos de assets estiverem em outro lugar.
    *   **Relacionamento com ShopVivaliz:** É um erro primário que impediria qualquer teste pós-deploy de funcionar corretamente, pois a aplicação não estaria acessível no URL esperado. O `.env` também estaria no lugar errado, afetando `admin/squad-chat.php`.
*   **Credenciais FTP Incorretas:**
    *   **Problema:** `FTP_USERNAME` ou `FTP_PASSWORD` (ou `FTP_SERVER`) são inválidos.
    *   **Impacto:** O job de `deploy.yml` (o passo FTP real, não o de validação) falharia na autenticação, abortando o deploy por completo.
    *   **Relacionamento com ShopVivaliz:** Os secrets são validados, mas a validação apenas verifica se *existem*. Não verifica se as credenciais são *válidas* para o servidor FTP da HostGator.
*   **Permissões de Arquivos/Diretórios no Servidor:**
    *   **Problema:** Após o upload via FTP, os arquivos e diretórios podem ter permissões incorretas (ex: `600` para arquivos PHP, `700` para diretórios).
    *   **Impacto:** O servidor web da HostGator (Apache) não conseguiria ler/executar os arquivos PHP, resultando em "500 Internal Server Error" ou páginas em branco. Arquivos de asset (CSS/JS/Imagens) também podem não ser carregados.
    *   **Relacionamento com ShopVivaliz:** Essencial para a execução de todos os scripts PHP, incluindo `index.php`, `admin/squad-chat.php`, `api/agent/squad-chat.php`, e o atualizador web.

**2. Bloqueios de SSL (HTTPS):**

*   **Certificado SSL Inválido/Ausente:**
    *   **Problema:** O domínio principal (`shopvivaliz.com.br`) não possui um certificado SSL válido, foi instalado incorretamente, ou está expirado. A HostGator geralmente oferece Let's Encrypt gratuito, mas problemas podem surgir.
    *   **Impacto:** Navegadores exibirão `ERR_SSL_PROTOCOL_ERROR` ou avisos de segurança ao tentar acessar `https://shopvivaliz.com.br`. Se o site força HTTPS via `.htaccess` ou configuração da aplicação, ele ficará inacessível.
    *   **Relacionamento com ShopVivaliz:** O ambiente de desenvolvimento usa HTTPS (`https://dev.shopvivaliz.com.br`). Se o ambiente de produção não tiver SSL funcionando, todas as chamadas `https://` internas ou externas (para as APIs Olist/Tiny, Pagar.me, etc.) falhariam ou gerariam erros de "mixed content" no navegador.
*   **Conteúdo Misto (Mixed Content):**
    *   **Problema:** A página principal é carregada via HTTPS, mas alguns recursos (imagens, CSS, JavaScript, chamadas de API) são carregados via HTTP.
    *   **Impacto:** Navegadores bloqueiam esses recursos inseguros, resultando em um site quebrado (sem estilo, sem funcionalidades JavaScript) e falha de chamadas de API.
    *   **Relacionamento com ShopVivaliz:** As múltiplas integrações de API e o frontend do e-commerce são suscetíveis a isso. Se os URLs internos no código PHP ou JS não forem `//` (protocol-relative) ou `https://` explícitos, podem surgir problemas.
*   **Problemas com `cURL` ou `file_get_contents()` para APIs:**
    *   **Problema:** As funções PHP usadas para fazer chamadas HTTP/HTTPS (como `curl` ou `file_get_contents`) encontram problemas de validação SSL ao tentar se conectar a APIs externas (Olist/Tiny, Pagar.me, Shopee) ou até mesmo internas (como `api/agent/squad-chat.php` se houver chamadas entre agentes ou módulos).
    *   **Impacto:** As integrações com terceiros falham, e funcionalidades dependentes delas não operam.
    *   **Relacionamento com ShopVivaliz:** Todas as notas de versão indicam uma forte dependência de APIs externas e internas. `Diagnóstico versionado de Melhor Envio e Pagar.me` sugere que esses são pontos de falha conhecidos.

**3. Caminhos de URL Incorretos e Roteamento (URL Routing):**

*   **Configuração de `.htaccess` Incorreta/Ausente:**
    *   **Problema:** O arquivo `.htaccess` (usado pelo Apache para reescrita de URL) está ausente, mal configurado, ou conflita com as configurações padrão da HostGator.
    *   **Impacto:** URLs amigáveis (friendly URLs) não funcionam, resultando em 404 Not Found para todas as páginas da aplicação, exceto talvez `index.php`. Endpoints de API (como `api/agent/squad-chat.php` se não for acessado diretamente) também podem falhar com 404.
    *   **Relacionamento com ShopVivaliz:** É fundamental para qualquer framework PHP que use um front controller (ex: `index.php` roteando todas as requisições) e para o funcionamento correto das APIs.
*   **Configuração da Base URL na Aplicação:**
    *   **Problema:** A configuração interna da ShopVivaliz (em algum arquivo de configuração PHP) para a "base URL" não corresponde ao domínio real onde o site está acessível na HostGator (ex: configurado para `http://localhost` ou `https://dev.shopvivaliz.com.br` em produção).
    *   **Impacto:** Links internos, caminhos para assets (CSS/JS/Imagens), e chamadas de API internas geram URLs incorretas, levando a 404s e funcionalidades quebradas.
    *   **Relacionamento com ShopVivaliz:** Afetaria a navegação do usuário, o carregamento de recursos e a comunicação entre os próprios módulos da aplicação (incluindo os agentes de IA).
*   **Localização Incorreta do `.env`:**
    *   **Problema:** `admin/squad-chat.php` espera o `.env` no diretório pai do `admin/`. Se o deploy não colocar o `.env` no diretório raiz da aplicação (geralmente `public_html` na HostGator), ou se ele estiver acima da `public_html` e o PHP não tiver permissão para lê-lo, a aplicação não carregará secrets.
    *   **Impacto:** A funcionalidade de chat administrativo não conseguirá obter o `SQUAD_TOKEN`, resultando em falhas de autenticação ou de comunicação com os agentes de IA. A injeção de secrets de Pagar.me/Melhor Envio no `.env` também seria impactada.
    *   **Relacionamento com ShopVivaliz:** Crítico para a segurança e funcionalidade dos módulos que dependem de variáveis de ambiente.

---

### Diagnóstico Conclusivo para os Testes de Rede Falhos

As "falhas nos testes de rede" na ShopVivaliz na HostGator podem ser causadas por uma combinação de:

1.  **Problemas de Deploy Básico:**
    *   **Erro no `FTP_REMOTE_DIR`:** Arquivos da aplicação no lugar errado, tornando-a inacessível via URL. Este é o ponto mais comum e fundamental.
    *   **Permissões de arquivos/diretórios:** PHP não executa, assets não carregam.
2.  **Problemas de Conectividade/Segurança de Rede (SSL):**
    *   **SSL do domínio principal inválido/ausente:** Impede o acesso HTTPS.
    *   **Problemas de `curl` ou `file_get_contents()`:** Falhas nas chamadas para APIs externas/internas devido a certificados SSL inválidos no destino ou problemas de validação no servidor HostGator.
3.  **Problemas de Roteamento/Configuração da Aplicação:**
    *   **`.htaccess` incorreto:** URLs amigáveis e endpoints de API não funcionam.
    *   **Base URL incorreta na aplicação PHP:** Geração de links e chamadas de API internas com URLs erradas.
    *   **`.env` inacessível:** Secrets da aplicação (como `SQUAD_TOKEN`) não são carregados, impactando funcionalidades.

Para diagnosticar, o primeiro passo seria verificar a acessibilidade básica do site via HTTP e HTTPS, o status dos certificados SSL, e a correta localização dos arquivos via FTP na HostGator, começando pela `FTP_REMOTE_DIR` usada no deploy. Em seguida, verificar os logs de erro do Apache/PHP na HostGator e os logs do GitHub Actions para o passo de deploy real (não apenas a validação de secrets).

**Claude:** [Claude indisponivel — API key ausente ou invalida]