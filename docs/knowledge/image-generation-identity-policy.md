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
- A foto-base precisa ter pelo menos 600 px por lado para evitar edicao apoiada em referencia de baixa qualidade.
- A imagem gerada quadrada precisa ter pelo menos 1000 px por lado antes de entrar em staging/publicacao. O Studio solicita 1024x1024 aos provedores quando suportado.
- A imagem principal `white` usa fundo branco puro, produto centralizado e dominante no quadro, sem badge, watermark, texto promocional ou objeto extra.
- O mesmo gate de 1000 px e aplicado na regeneracao e novamente na publicacao; nenhum caminho manual contorna a validacao.

## Identidade de produto

Nunca usar correspondencia permissiva do tipo `olist_id = X OR sku = Y` para atualizar imagem.

Quando SKU e ID externo existem:

- ambos precisam coincidir com o mesmo registro;
- conflito entre um SKU correto e um ID incorreto bloqueia a operacao;
- conflito entre um ID correto e um SKU incorreto bloqueia a operacao;
- mais de uma correspondencia e considerada ambigua e bloqueada;
- nao escolher arbitrariamente o primeiro resultado.

Quando somente um identificador esta disponivel, ele so pode ser usado se a correspondencia for unica.

## Publicacao por marketplace

- Cada aprovacao vale para exatamente um canal.
- Um marketplace recebe somente a imagem atual e imagens que ja tenham sido aprovadas anteriormente para esse mesmo canal; a galeria do site nao e copiada silenciosamente para outro marketplace.
- A ordem externa e sempre `white -> hero -> ambient`. Se `white` ainda nao foi aprovada no canal, `hero` ou `ambient` sao bloqueadas para impedir que virem capa.
- Amazon recebe as imagens aprovadas na frente, mas preserva os locators existentes da listagem para nao apagar a galeria anterior ao adicionar novas imagens.
- Mercado Livre, Shopee e TikTok preservam as imagens externas existentes por meio dos respectivos publishers, com `white` mantida como primeira imagem do novo conjunto.
- O caminho de publicacao de imagem gerada no Olist/Tiny via API V2 fica bloqueado. O endpoint de alteracao de produto exige reenviar `preco` no layout completo; isso conflita com a regra absoluta do AI Image Studio de nao tocar, recalcular ou reenviar preco/estoque. O ERP permanece fonte protegida.

## Cache da vitrine

A publicacao do Admin deve encontrar exatamente um item do cache. Se zero ou mais de um item corresponderem, o arquivo nao e persistido no cache.

## QA

`tests/image-identity-policy-test.php` cobre:

- validacao real de PNG e fingerprint SHA-256;
- bloqueio de foto-base abaixo de 600 px;
- bloqueio de saida/regeneracao abaixo de 1000 px;
- bloqueio de arquivo falso com extensao de imagem;
- bloqueio de traversal no caminho da foto-base;
- prompts com preservacao de identidade, 1024x1024 e regra de imagem principal branca;
- SKU + ID externo obrigatoriamente consistentes;
- ordem `white -> hero -> ambient`;
- proveniencia de aprovacao por marketplace;
- preservacao da galeria Amazon;
- bloqueio fail-closed do caminho ERP que exigiria reenviar preco;
- remocao da query antiga `WHERE olist_id = ? OR sku = ?`;
- remocao do bind incompleto da rotina antiga.
