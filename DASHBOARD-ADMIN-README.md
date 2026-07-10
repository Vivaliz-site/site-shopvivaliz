# Dashboard Admin - Caminho C: A/B Testing & Performance Analytics

> Status: **IMPLEMENTED** (FASE 1-3) | Deploy Ready

---

## Visão Geral

O Dashboard Admin oferece três módulos integrados para análise completa de A/B testing e performance de layouts:

1. **A/B Testing Dashboard** (`/admin/ab-testing/`)
2. **Performance Analytics** (`/admin/performance/`)
3. **Layout History** (`/admin/layout-history/`)

---

## FASE 1: A/B Testing Dashboard

### Localização
`/admin/ab-testing/index.php`

### Funcionalidades

#### Comparação de Variantes
- **Gráfico CTR**: Visualiza porcentagem de cliques por variante
- **Gráfico de Receita**: Compara receita gerada por cada variante
- **Gráfico de Eventos**: Histórico temporal de impressões, cliques e conversões

#### Métricas Agregadas
- Total de Impressões
- Total de Cliques
- CTR Médio (%)
- Taxa de Conversão Média (%)
- Receita Total (R$)
- Conversões Totais

#### Tabela Detalhada
Visualize por variante:
- Impressões
- Cliques
- CTR (%)
- Conversões
- Taxa de Conversão (%)
- Receita (R$)
- AOV - Average Order Value (R$)
- Status

#### Filtros
- **Por Página**: Homepage, Categoria, Produto, Checkout
- **Por Período**: Último dia, 7 dias, 30 dias, 90 dias

#### Ações
- **Exportar CSV**: Download de dados para análise externa
- **Atualizar**: Recarrega dados em tempo real

### Indicador de Vencedor
A variante com melhor taxa de conversão é automaticamente destacada com badge ⭐.

---

## FASE 2: Layout History (Git Timeline)

### Localização
`/admin/layout-history/index.php`

### Funcionalidades

#### Timeline de Commits
Visualize o histórico completo de cada layout:
- Hash do commit (7 primeiros caracteres)
- Mensagem do commit
- Autor
- Data e hora

#### Ações por Commit
- **Ver Detalhes**: Exibe a configuração JSON do layout naquele momento
- **Reverter para esta Versão**: Volta o layout para uma versão anterior

### Como Funciona
- Integra com `Core\GitVersioning`
- Monitora arquivo: `layouts/{page_id}-config.json`
- Auto-commit automático quando layout é salvo no editor visual

### Páginas Suportadas
- homepage
- categoria
- produto
- checkout

---

## FASE 3: Performance Analytics

### Localização
`/admin/performance/index.php`

### Análises Avançadas

#### Scatter Plot: Clicks vs Conversions
Visualiza correlação entre cliques gerados e conversões realizadas.
Util para identificar variantes que geram tráfego mas não convertem.

#### ROI por Variante
Exibe receita gerada por cada conversão.
Formula: `Revenue ÷ Conversions`

#### Taxa de Eficiência
Mostra a porcentagem de cliques que se tornam conversões.
Formula: `(Conversions ÷ Clicks) × 100`

#### Ranking de Performance
Tabela ordenada por conversões, com:
- Rank (1º, 2º, 3º)
- Variante vencedora destacada (amarelo)
- ROI por clique (receita ÷ cliques)
- Todas as métricas de performance

---

## Arquitetura

### Banco de Dados

#### Tabela: `page_layout_variants`
```sql
CREATE TABLE page_layout_variants (
    id BIGINT UNSIGNED PRIMARY KEY,
    page_id VARCHAR(100) NOT NULL,
    variant_name VARCHAR(100) NOT NULL,
    variant_type VARCHAR(40) DEFAULT 'control',
    config_json JSON,
    impressions INT DEFAULT 0,
    clicks INT DEFAULT 0,
    conversions INT DEFAULT 0,
    revenue DECIMAL(12,2) DEFAULT 0.00,
    status VARCHAR(40) DEFAULT 'active',
    created_at DATETIME,
    updated_at DATETIME,
    started_at DATETIME,
    ended_at DATETIME
)
```

#### Tabela: `ab_test_events`
```sql
CREATE TABLE ab_test_events (
    id BIGINT UNSIGNED PRIMARY KEY,
    variant_id BIGINT UNSIGNED NOT NULL,
    page_id VARCHAR(100) NOT NULL,
    event_type VARCHAR(40) NOT NULL,  -- impression|click|conversion
    session_id VARCHAR(100),
    user_agent VARCHAR(500),
    referer VARCHAR(1000),
    created_at DATETIME,
    FOREIGN KEY (variant_id) REFERENCES page_layout_variants(id)
)
```

### APIs

#### GET `/api/admin/ab-testing-data.php`

**Ações disponíveis:**

```bash
# Listar todas as páginas com testes ativos
GET /api/admin/ab-testing-data.php?action=pages

# Obter variantes de uma página
GET /api/admin/ab-testing-data.php?action=variants&page_id=homepage

# Obter histórico de eventos
GET /api/admin/ab-testing-data.php?action=events&page_id=homepage&days=7

# Comparar duas variantes
GET /api/admin/ab-testing-data.php?action=compare&variant_a=1&variant_b=2
```

**Response Format:**
```json
{
    "ok": true,
    "action": "variants",
    "page_id": "homepage",
    "count": 3,
    "data": [
        {
            "id": 1,
            "page_id": "homepage",
            "variant_name": "Control",
            "impressions": 2500,
            "clicks": 125,
            "ctr_percentage": 5.00,
            "conversions": 15,
            "conversion_rate": 12.00,
            "revenue": 750.00,
            "avg_order_value": 50.00
        }
    ],
    "chartData": { ... }
}
```

#### POST `/api/admin/layout-revert.php`

Reverter layout para versão anterior.

```bash
curl -X POST /api/admin/layout-revert.php \
  -H "Content-Type: application/json" \
  -d '{"page_id": "homepage", "hash": "abc1234"}'
```

#### GET `/api/admin/export-csv.php`

Exportar dados em CSV.

```bash
# Exportar página específica
GET /api/admin/export-csv.php?page_id=homepage

# Exportar todas as páginas
GET /api/admin/export-csv.php?action=all
```

#### GET `/api/admin/seed-ab-testing.php`

**[DEV ONLY]** Gerar dados de teste.

```bash
# Criar dados de teste
GET /api/admin/seed-ab-testing.php

# Limpar e recria dados
GET /api/admin/seed-ab-testing.php?clear=1
```

### Classes Helper

#### `AdminHelpers` (`includes/admin-helpers.php`)

Funções reutilizáveis para queries:

```php
// Obter variantes de uma página
AdminHelpers::getPageVariants(string $pageId): array

// Listar páginas com testes ativos
AdminHelpers::getActivePagesWithTests(): array

// Histórico de eventos (últimos N dias)
AdminHelpers::getEventHistory(string $pageId, int $days): array

// Registrar evento
AdminHelpers::logEvent(int $variantId, string $pageId, string $eventType, string $sessionId): bool

// Comparar duas variantes
AdminHelpers::compareVariants(int $variantIdA, int $variantIdB): array

// Obter variante vencedora
AdminHelpers::getWinnerVariant(string $pageId): ?array

// Criar variante
AdminHelpers::createPageVariant(string $pageId, string $variantName, string $type, array $configJson): ?int

// Adicionar receita
AdminHelpers::addRevenueToVariant(int $variantId, float $amount): bool

// Exportar para CSV
AdminHelpers::exportVariantsToCSV(string $pageId): string
```

---

## Integração com o Sistema

### Fluxo de Rastreamento

1. **Criação de Variante**
   ```php
   $variantId = AdminHelpers::createPageVariant(
       'homepage',
       'Variant A',
       'treatment',
       ['hero' => 'blue', 'cta' => 'bold']
   );
   ```

2. **Rastreamento de Impressão** (No template da página)
   ```php
   AdminHelpers::logEvent($variantId, 'homepage', 'impression', $sessionId);
   ```

3. **Rastreamento de Clique** (Em JavaScript)
   ```javascript
   fetch('/api/admin/ab-testing-data.php', {
       method: 'POST',
       body: JSON.stringify({
           action: 'log_event',
           variant_id: 1,
           page_id: 'homepage',
           event_type: 'click'
       })
   });
   ```

4. **Registrar Conversão** (No checkout)
   ```php
   AdminHelpers::logEvent($variantId, 'homepage', 'conversion', $sessionId);
   AdminHelpers::addRevenueToVariant($variantId, $orderTotal);
   ```

### Git Integration

Quando um layout é editado via **Editor Visual**:
1. Arquivo salvo: `layouts/{page_id}-config.json`
2. Automaticamente commitado via `GitVersioning::commitLayout()`
3. Histórico fica disponível em `/admin/layout-history/`

---

## Características Adicionais

### Segurança
- Todas as APIs protegidas por `admin-guard.php`
- Validação de `page_id` (alfanumérico + hífens)
- SQL injection protection via prepared statements

### Performance
- Índices em `page_id`, `status`, `created_at`
- Cache de gráficos client-side
- Limite de 50 commits no histórico (configurável)

### UI/UX
- Design responsivo (mobile-first)
- Chart.js CDN (não requer npm)
- Cards de métricas com cores temáticas
- Tabelas sortáveis e exportáveis

### Temas
- Paleta ShopVivaliz (Vivaliz Brand)
- Dark mode ready (CSS variables)
- Paleta de cores: Navy (#173B63), Green (#059669), Amber (#d97706)

---

## Próximas Melhorias (Futura)

- [ ] Dashboard de dashboard (meta-view)
- [ ] Estatística significância (p-value)
- [ ] Segmentação por dispositivo (mobile/desktop)
- [ ] Heatmaps de interação
- [ ] Integração com Google Analytics
- [ ] Alertas automáticos quando vencedor identificado
- [ ] API de webhook para integração

---

## Troubleshooting

### Nenhuma variante aparece
1. Verificar se tabelas foram criadas: `SHOW TABLES LIKE 'page_layout%'`
2. Executar seed: `GET /api/admin/seed-ab-testing.php`
3. Verificar permissões de banco de dados

### Gráficos não carregam
1. Verificar console do navegador (DevTools)
2. Validar URL da API
3. Confirmar que Chart.js CDN está acessível

### Layout History vazio
1. Git não está habilitado neste servidor
2. Nenhum commit foi feito para este layout ainda
3. Verifique: `$git->isEnabled()`

---

## Suporte

Para dúvidas ou sugestões:
- Verificar `/logs/` para erros
- Consultar `CLAUDE.md` para contexto do projeto
- Abrir issue no GitHub

---

**Desenvolvido com ❤️ por Claude Code - ShopVivaliz Dashboard Admin**
