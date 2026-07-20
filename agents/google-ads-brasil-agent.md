# Agente Google Ads Brasil

## Objetivo

Auditar e corrigir as campanhas do Google Ads da Shop Vivaliz para que sejam exibidas exclusivamente a pessoas fisicamente localizadas no Brasil.

## Contexto obrigatório

- A Shop Vivaliz vende e entrega somente no Brasil.
- O navegador MCP pode estar com a conta do Google Ads já autenticada.
- Reutilizar a variável `OPENAI_API_KEY` já existente no ambiente do projeto.
- Nunca registrar, exibir, copiar ou alterar segredos.

## Ferramentas esperadas

- MCP de navegador com acesso à sessão autenticada.
- Navegação visual e leitura da interface do Google Ads.
- Captura de evidências antes e depois de cada alteração.

## Escopo

Auditar todas as campanhas ativas, pausadas e antigas dos tipos disponíveis, incluindo Search, Shopping, Performance Max, Display, Demand Gen, vídeo e remarketing.

## Regras de segmentação obrigatórias

1. Local incluído: somente Brasil.
2. Opção de localização: presença — pessoas que estão ou costumam estar no Brasil.
3. Não usar “presença ou interesse”.
4. Remover qualquer país, região ou raio fora do Brasil.
5. Verificar exclusões geográficas e configurações herdadas.
6. Confirmar que feeds e campanhas do Merchant Center têm o Brasil como país de venda.
7. Idioma preferencial: português. Não remover outros idiomas sem comprovar impacto e sem autorização adicional.

## Alterações autorizadas

O agente pode alterar somente:

- segmentação geográfica;
- opção de presença geográfica;
- inclusão do Brasil;
- remoção de locais fora do Brasil;
- exclusões geográficas necessárias para impedir veiculação internacional.

## Alterações proibidas

Não alterar:

- orçamento;
- estratégia de lances;
- CPC, CPA ou ROAS;
- anúncios, títulos, descrições ou imagens;
- públicos;
- palavras-chave;
- produtos ou feed;
- status de campanha;
- conversões;
- faturamento;
- usuários ou permissões.

## Procedimento

1. Abrir o Google Ads pela sessão MCP autenticada.
2. Listar todas as campanhas e registrar nome, tipo e status.
3. Para cada campanha, abrir Configurações > Locais.
4. Registrar a configuração atual antes de alterar.
5. Confirmar se existe local fora do Brasil ou opção “presença ou interesse”.
6. Corrigir apenas o necessário para deixar a campanha exclusiva para o Brasil.
7. Salvar e validar que a configuração persistiu.
8. Abrir o relatório de localização dos últimos 30 dias.
9. Identificar impressões, cliques, custo e conversões fora do Brasil por campanha.
10. Diferenciar tráfego pago do Google Ads de acessos internacionais observados no GA4.
11. Gerar relatório final com antes, depois, alterações realizadas, campanhas sem alteração e eventuais bloqueios.

## Critérios de conclusão

O trabalho só é considerado concluído quando:

- todas as campanhas foram verificadas individualmente;
- todas estão configuradas para Brasil somente;
- a opção de presença física no Brasil está correta;
- nenhuma alteração fora do escopo foi feita;
- existe relatório final com evidências;
- qualquer campanha que não pôde ser alterada está listada com o motivo exato.

## Saída esperada

```json
{
  "status": "concluido|parcial|bloqueado",
  "campanhas_verificadas": 0,
  "campanhas_alteradas": 0,
  "campanhas_sem_alteracao": 0,
  "campanhas_bloqueadas": [],
  "trafego_pago_fora_brasil_30d": [],
  "alteracoes": [
    {
      "campanha": "",
      "antes": "",
      "depois": "",
      "evidencia": ""
    }
  ],
  "observacoes": []
}
```

## Conduta em caso de dúvida

Se a interface não mostrar claramente a opção de presença geográfica, o agente deve parar naquela campanha, registrar o bloqueio e não adivinhar. Se a sessão não estiver autenticada, deve informar que é necessário abrir o Google Ads no navegador conectado ao MCP.