# Geracao e vinculacao de imagens

## Fluxo canonico do Admin

- Geracao: `/admin/ai-image-studio/admin_dashboard.php` -> `/admin/ai-image-studio/process_item.php`
- Validacao/publicacao: `/admin/ai-image-studio/admin_validate.php` -> `src/OmnichannelImagePublisher.php`
- Sincronizacao de imagens Olist: `/olist/sync-images-to-site.php`

## Regras de fidelidade

- A IA nunca deve gerar o produto do zero.
- Toda geracao exige foto real vinculada ao mesmo `products.id`.
- Forma, proporcao, cor, material aparente, logos, textos impressos, conectores, controles e partes incluidas devem ser preservados.
- A cena nao pode adicionar acessorios que parecam fazer parte do produto vendido.
- Arquivos falsos, HTML renomeado como imagem, formatos nao permitidos e resolucao insuficiente sao bloqueados.
- A foto-base precisa ter pelo menos 300 px por lado.
- A imagem gerada precisa ter pelo menos 512 px por lado antes de entrar em staging/publicacao.

## Identidade de produto

Nunca usar correspondencia permissiva do tipo `olist_id = X OR sku = Y` para atualizar imagem.

Quando SKU e ID externo existem:

- ambos precisam coincidir com o mesmo registro;
- conflito entre um SKU correto e um ID incorreto bloqueia a operacao;
- conflito entre um ID correto e um SKU incorreto bloqueia a operacao;
- mais de uma correspondencia e considerada ambigua e bloqueada;
- nao escolher arbitrariamente o primeiro resultado.

Quando somente um identificador esta disponivel, ele so pode ser usado se a correspondencia for unica.

## Cache da vitrine

A publicacao do Admin deve encontrar exatamente um item do cache. Se zero ou mais de um item corresponderem, o arquivo nao e persistido no cache.

## QA

`tests/image-identity-policy-test.php` cobre:

- validacao real de PNG e fingerprint SHA-256;
- bloqueio de resolucao insuficiente;
- bloqueio de arquivo falso com extensao de imagem;
- bloqueio de traversal no caminho da foto-base;
- prompts com preservacao de identidade;
- SKU + ID externo obrigatoriamente consistentes;
- remocao da query antiga `WHERE olist_id = ? OR sku = ?`;
- remocao do bind incompleto da rotina antiga.
