# 📊 A/B Testing de Layouts

Teste múltiplas variantes de layouts e veja qual converte mais!

## Quick Start

### 1. Criar variante no editor

```php
// Via LayoutManager
$layoutManager = new LayoutManager($db, $userId);

// Criar "Teste A" com 30% do tráfego
$variantId = $layoutManager->createVariant(
    'homepage',
    'Teste A',
    ['sections' => [...]],  // JSON do layout
    30.0  // traffic_percent
);

// Criar "Teste B" com 20% do tráfego
$variantId = $layoutManager->createVariant(
    'homepage',
    'Teste B',
    ['sections' => [...]],
    20.0
);

// Controle automático recebe os 50% restantes (100% - 30% - 20%)
```

### 2. Renderizar homepage com A/B

```html
<!-- Na home, chamar o endpoint para resolver qual variante servir -->
<script>
fetch('/api/catalog/ab-variant.php?page_id=homepage')
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            // Renderizar usando data.config
            console.log('Variante:', data.variant_name);
            console.log('Usando layout:', data.config);
            // renderLayout(data.config);
        }
    });
</script>
```

### 3. Rastrear conversões

```php
// No checkout (api/orders/create-v2.php)
if (isset($_COOKIE['ab_variant_homepage'])) {
    $variantId = (int)$_COOKIE['ab_variant_homepage'];
    $orderValue = 100.00;  // preço do pedido

    fetch('/api/catalog/ab-tracking.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'conversion',
            variant_id: $variantId,
            value: $orderValue
        })
    });
}
```

## Arquitetura

### Tabelas

**`page_layout_variants`**: Armazena as variantes
```sql
id | layout_id | page_id | variant_name | traffic_percent | config | impressions | conversions | revenue
```

**`ab_variant_sessions`** (opcional): Rastreamento detalhado por sessão
```sql
id | variant_id | session_id | ip_address | converted | conversion_value
```

### Fluxo

```
1. Cliente acessa homepage
   ↓
2. GET /api/catalog/ab-variant.php?page_id=homepage
   - Cria hash determinístico (IP + User-Agent)
   - Seleciona variante baseado em percentual + hash
   - Retorna config do layout + variant_id
   - Seta cookie ab_variant_homepage
   ↓
3. Cliente renderiza layout usando config retornado
   ↓
4. POST /api/catalog/ab-tracking.php
   - action: 'impression' (automático)
   ↓
5. Cliente faz compra
   ↓
6. POST /api/catalog/ab-tracking.php
   - action: 'conversion'
   - value: preço do pedido
   - Incrementa conversions na variante
```

## Seleção Determinística

A seleção é **determinística** por sessão — mesmo visitante sempre vê mesma variante:

```php
// Hash = CRC32(IP + User-Agent)
$hash = md5($ip . '|' . $userAgent);
$value = (crc32($hash) & 0x7fffffff) % 10000;

// Distribuir 10000 pontos entre variantes
// Variante A: 0-3000 (30%)
// Variante B: 3000-5000 (20%)
// Controle: 5000-10000 (50%)
```

Isso garante:
- ✅ Mesmo visitante volta e vê mesmo layout
- ✅ Sem cookie (incógnito) ainda funciona
- ✅ Distribuição igual ao traffic_percent configurado

## Métricas

Cada variante rastreia:

```php
$variants = $layoutManager->getVariants('homepage');

foreach ($variants as $v) {
    $impressions = $v['impressions'];
    $conversions = $v['conversions'];
    $ctr = ($conversions / $impressions) * 100;  // Click-through rate
    $revenue = $v['revenue'];
}
```

## Comparação

Endpoint no painel admin (futuro):

```php
GET /api/admin/ab-comparison.php?page_id=homepage
```

Retorna:
```json
{
  "page_id": "homepage",
  "variants": [
    {
      "variant_id": 1,
      "name": "Controle",
      "traffic_percent": 50,
      "impressions": 5000,
      "conversions": 250,
      "ctr": 5.0,
      "revenue": 25000.00
    },
    {
      "variant_id": 2,
      "name": "Teste A",
      "traffic_percent": 30,
      "impressions": 3000,
      "conversions": 180,
      "ctr": 6.0,
      "revenue": 18000.00
    },
    {
      "variant_id": 3,
      "name": "Teste B",
      "traffic_percent": 20,
      "impressions": 2000,
      "conversions": 90,
      "ctr": 4.5,
      "revenue": 9000.00
    }
  ]
}
```

## Setup no Banco

Criar tabelas:

```bash
mysql -u shopvivaliz -p shopvivaliz < database/schema-ab-testing.sql
```

Ou via PHP:

```php
$db = Database::connect();
$schema = file_get_contents('database/schema-ab-testing.sql');
foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
    if (!empty($stmt)) {
        $db->exec($stmt);
    }
}
```

## UI no Editor

No `admin/editor-visual.php`, adicionar aba "A/B Testing":

```html
<div class="editor-tab" id="tab-ab-testing">
    <h3>A/B Testing</h3>
    <div class="variants-list">
        <!-- Listar variantes aqui -->
    </div>
    <button onclick="createNewVariant()">+ Nova Variante</button>
    <button onclick="duplicateCurrentLayout()">Duplicar Layout</button>
</div>
```

## Exemplos

### Criar e ativar teste

```php
// Pegue o layout atual da homepage
$homepage = $layoutManager->getByPageId('homepage');

// Crie variante B alterando apenas 1 coisa (ex: cor do botão)
$config = json_decode($homepage['config'], true);
$config['sections'][0]['styles']['backgroundColor'] = '#ff0000';  // Vermelho em vez de azul

$variantId = $layoutManager->createVariant(
    'homepage',
    'Botão Vermelho',
    $config,
    30.0
);

echo "Variante criada: {$variantId}";
```

### Monitorar performance

```php
$variants = $layoutManager->getVariants('homepage', activeOnly: true);

foreach ($variants as $v) {
    $ctr = $v['ctr'];  // já calculado
    printf(
        "%s: %.1f%% conversão (%d conversões em %d impressões)\n",
        $v['variant_name'],
        $ctr,
        $v['conversions'],
        $v['impressions']
    );
}
```

## Próximos Passos

- [ ] Dashboard de comparação visual
- [ ] Estatísticas de significância (Chi-square test)
- [ ] Webhooks para alertas (ex: "Teste A venceu!")
- [ ] Schedule automático (ex: promover vencedor em 7 dias)
- [ ] Múltiplos testes simultâneos (página 1 vs página 2)

---

**Status:** ✅ Infraestrutura pronta, falta UI dashboard
