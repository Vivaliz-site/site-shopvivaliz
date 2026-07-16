# Integridade de Dados

## Catálogo

- Produto deve ser identificado por SKU ou ID estável da origem.
- Nome não deve ser usado como única chave de vínculo.
- Preço zero, ausente ou inválido deve ser tratado explicitamente.
- Estoque deve refletir a fonte comercial disponível.

## Imagens

- Validar URL, download, formato, tamanho e vínculo com o produto.
- Não substituir a logo real por uma versão gerada ou aproximada.
- Manter fallback visual sem esconder falhas de importação.

## Pedidos

- Recalcular preços e frete no servidor.
- Não confiar em valores enviados apenas pelo navegador.
- Registrar origem, identificadores, itens, quantidades e totais usados na criação do pedido.

## Migrations

- Usar transações quando suportado.
- Evitar alterações destrutivas sem backup.
- Registrar versão, data, resultado e erro.
- Reparos devem ser repetíveis e seguros.

## Evidência mínima

Uma correção de dados só deve ser considerada concluída quando houver consulta, log, teste ou resposta que demonstre o estado final.
