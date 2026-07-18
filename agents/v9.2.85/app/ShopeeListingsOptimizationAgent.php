<?php

declare(strict_types=1);

/**
 * Otimiza anúncios Shopee no Tiny/Olist ERP:
 * - Título (SEO Shopee, até 120 chars)
 * - Descrição (estruturada, palavras-chave)
 * - Atributos / specs
 * - Ordem de imagens
 * NÃO altera preços.
 */
final class ShopeeListingsOptimizationAgent
{
    private const VERSION    = '9.2.85';
    private const API_BASE   = 'https://api.tiny.com.br/public-api/v3';
    private const TOKEN_URL  = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';
    private const PAGE_LIMIT = 100;
    private const MAX_PAGES  = 50;
    private const AI_DELAY_US = 500_000; // 500ms entre chamadas IA
    private const API_DELAY_US = 300_000; // 300ms entre chamadas Tiny

    // Anthropic Messages API
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_MODEL   = 'claude-haiku-4-5-20251001'; // rápido e barato para otimização em massa
    private const ANTHROPIC_VERSION = '2023-06-01';

    // OpenAI fallback
    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';
    private const OPENAI_MODEL   = 'gpt-4o-mini';

    public function run(): array
    {
        $result = [
            'agent'          => 'shopee_listings_optimization',
            'version'        => self::VERSION,
            'generated_at'   => date('c'),
            'secrets_check'  => [],
            'status'         => 'pending',
            'ai_provider'    => null,
            'total_products' => 0,
            'optimized'      => 0,
            'skipped'        => 0,
            'errors'         => [],
            'log'            => [],
        ];

        $tinyToken = $this->resolveTinyToken($result);
        if (!$tinyToken) {
            $result['status'] = 'error';
            return $result;
        }

        $aiToken    = null;
        $aiProvider = $this->resolveAiProvider($result, $aiToken);
        $result['ai_provider'] = $aiProvider;

        $products = $this->fetchAllProducts($tinyToken, $result);
        $result['total_products'] = count($products);

        if (empty($products)) {
            $result['status'] = 'error';
            $result['errors'][] = 'Nenhum produto encontrado na API Tiny.';
            return $result;
        }

        foreach ($products as $product) {
            $entry = $this->processProduct($product, $tinyToken, $aiProvider, $aiToken, $result);
            $result['log'][] = $entry;

            if ($entry['status'] === 'optimized') {
                $result['optimized']++;
            } else {
                $result['skipped']++;
            }

            usleep(self::API_DELAY_US);
        }

        $result['status'] = empty($result['errors']) ? 'success' : 'partial';
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Auth
    // ──────────────────────────────────────────────────────────────────────────

    private function resolveTinyToken(array &$result): ?string
    {
        // O access_token da Tiny expira em ~4h; refresh via OAuth2 tem
        // prioridade (mesmo criterio do ShopeeListingsExtractorAgent) --
        // um TINY_ACCESS_TOKEN estatico so serve de fallback se o refresh falhar.
        $clientId     = getenv('TINY_CLIENT_ID')     ?: getenv('OLIST_CLIENT_ID')     ?: '';
        $clientSecret = getenv('TINY_CLIENT_SECRET') ?: getenv('OLIST_CLIENT_SECRET') ?: '';
        $refreshToken = getenv('TINY_REFRESH_TOKEN') ?: getenv('OLIST_REFRESH_TOKEN') ?: '';

        if ($clientId && $clientSecret && $refreshToken) {
            $result['secrets_check']['tiny_token_source'] = 'oauth2_refresh';
            $token = $this->refreshOAuth($clientId, $clientSecret, $refreshToken, $result);
            if ($token) return $token;
        }

        foreach (['TINY_ACCESS_TOKEN', 'TINY_API_TOKEN', 'ERP_API_TOKEN', 'OLIST_ACCESS_TOKEN'] as $name) {
            $val = getenv($name);
            if ($val !== false && $val !== '') {
                $result['secrets_check']['tiny_token_source'] = $name;
                $result['secrets_check']['tiny_token_ok']     = true;
                return $val;
            }
        }

        $result['secrets_check']['tiny_token_ok'] = false;
        $result['errors'][] = 'Credencial Tiny ausente: TINY_ACCESS_TOKEN, TINY_API_TOKEN ou TINY_CLIENT_ID+SECRET+REFRESH_TOKEN';
        return null;
    }

    private function refreshOAuth(string $id, string $secret, string $refresh, array &$result): ?string
    {
        $resp = $this->httpPost(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'client_id'     => $id,
            'client_secret' => $secret,
            'refresh_token' => $refresh,
        ]);
        if (!empty($resp['access_token'])) {
            $result['secrets_check']['tiny_token_refreshed'] = true;
            return $resp['access_token'];
        }
        $result['errors'][] = 'Falha OAuth2 refresh: ' . ($resp['error_description'] ?? $resp['error'] ?? 'unknown');
        return null;
    }

    private function resolveAiProvider(array &$result, ?string &$aiToken): string
    {
        $anthropicKey = getenv('ANTHROPIC_API_KEY') ?: '';
        if ($anthropicKey) {
            $result['secrets_check']['ai_provider']  = 'anthropic';
            $result['secrets_check']['ai_key_found'] = true;
            $aiToken = $anthropicKey;
            return 'anthropic';
        }

        $openaiKey = getenv('OPENAI_API_KEY') ?: '';
        if ($openaiKey) {
            $result['secrets_check']['ai_provider']  = 'openai';
            $result['secrets_check']['ai_key_found'] = true;
            $aiToken = $openaiKey;
            return 'openai';
        }

        $result['secrets_check']['ai_provider']  = 'rule_based';
        $result['secrets_check']['ai_key_found'] = false;
        return 'rule_based';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fetch products from Tiny
    // ──────────────────────────────────────────────────────────────────────────

    private function fetchAllProducts(string $token, array &$result): array
    {
        $all     = [];
        $seenIds = [];
        $offset  = 0;
        $page    = 0;

        while ($page < self::MAX_PAGES) {
            $page++;
            $url  = self::API_BASE . '/produtos?limit=' . self::PAGE_LIMIT . '&offset=' . $offset;
            $data = $this->httpGet($url, $token);

            if (isset($data['_http_status']) && $data['_http_status'] === 401) {
                $result['errors'][] = 'Autenticação Tiny falhou (401).';
                break;
            }

            if (!isset($data['itens']) || !is_array($data['itens'])) break;
            $items = $data['itens'];
            if (empty($items)) break;

            $newCount = 0;
            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                if ($id === null || isset($seenIds[$id])) continue;
                $seenIds[$id] = true;
                $all[] = $this->fetchProductDetail((int)$id, $token) ?? $item;
                $newCount++;
                usleep(200_000);
            }
            if ($newCount === 0) break;
            $offset += self::PAGE_LIMIT;
        }

        return $all;
    }

    private function fetchProductDetail(int $id, string $token): ?array
    {
        $data = $this->httpGet(self::API_BASE . '/produtos/' . $id, $token);
        return is_array($data) && isset($data['id']) ? $data : null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Process & optimize each product
    // ──────────────────────────────────────────────────────────────────────────

    private function processProduct(array $product, string $tinyToken, string $aiProvider, ?string $aiToken, array &$result): array
    {
        $id  = (int)($product['id'] ?? 0);
        $sku = $product['sku'] ?? '?';

        $entry = [
            'sku'         => $sku,
            'id'          => $id,
            'titulo_antes' => $product['nome'] ?? null,
            'titulo_novo'  => null,
            'descricao_antes' => substr($product['descricao'] ?? '', 0, 80),
            'descricao_nova'  => null,
            'imagens_antes'  => count($product['imagens'] ?? []),
            'imagens_depois' => null,
            'status'      => 'skipped',
            'motivo'      => null,
        ];

        if (!$id) {
            $entry['motivo'] = 'ID inválido';
            return $entry;
        }

        // Gera otimização (AI ou rule-based)
        $aiError = null;
        $optimized = ($aiProvider !== 'rule_based' && $aiToken)
            ? $this->optimizeWithAI($product, $aiProvider, $aiToken, $aiError)
            : $this->optimizeRuleBased($product);

        if (!$optimized) {
            $entry['motivo'] = $aiError ?? 'Otimização não gerou alterações (rule-based não achou melhoria)';
            return $entry;
        }

        $entry['titulo_novo']  = $optimized['titulo'] ?? null;
        $entry['descricao_nova'] = isset($optimized['descricao']) ? substr($optimized['descricao'], 0, 80) . '...' : null;

        // Monta payload SEM alterar preços
        $payload = $this->buildUpdatePayload($product, $optimized);
        if (empty($payload)) {
            $entry['motivo'] = 'Sem campos a atualizar';
            return $entry;
        }

        // Aplica no Tiny
        $updateResult = $this->applyUpdate($id, $payload, $tinyToken);
        if ($updateResult['ok']) {
            $entry['status'] = 'optimized';
            $entry['imagens_depois'] = $updateResult['imagens'] ?? $entry['imagens_antes'];
        } else {
            $entry['status'] = 'error';
            $entry['motivo'] = $updateResult['error'] ?? 'Falha na atualização';
            $result['errors'][] = "SKU $sku (id=$id): " . ($updateResult['error'] ?? 'erro desconhecido');
        }

        if ($aiProvider !== 'rule_based') {
            usleep(self::AI_DELAY_US);
        }

        return $entry;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AI optimization
    // ──────────────────────────────────────────────────────────────────────────

    private function optimizeWithAI(array $product, string $provider, string $aiToken, ?string &$error = null): ?array
    {
        $prompt = $this->buildOptimizationPrompt($product);

        $raw = match ($provider) {
            'anthropic' => $this->callAnthropic($prompt, $aiToken, $error),
            'openai'    => $this->callOpenAI($prompt, $aiToken, $error),
            default     => null,
        };

        if (!$raw) return null; // $error já preenchido por callAnthropic()/callOpenAI()

        // Extrai JSON da resposta
        if (preg_match('/\{[\s\S]*\}/u', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) return $decoded;
            $error = 'JSON inválido na resposta da IA (json_decode falhou): ' . substr($raw, 0, 300);
            return null;
        }

        $error = 'Resposta da IA não contém um JSON reconhecível: ' . substr($raw, 0, 300);
        return null;
    }

    private function buildOptimizationPrompt(array $product): string
    {
        $nome      = $product['nome']       ?? $product['descricao']  ?? '';
        $descricao = $product['descricao']  ?? '';
        $sku       = $product['sku']        ?? '';
        $categoria = $product['categoria']['nome'] ?? '';
        $marca     = $product['marca']['nome']     ?? '';
        $imagens   = count($product['imagens'] ?? []);
        $atribs    = json_encode($product['atributos'] ?? [], JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Você é um especialista em SEO para Shopee Brasil. Otimize o anúncio abaixo para máxima visibilidade e conversão.

PRODUTO ATUAL:
- SKU: $sku
- Título atual: $nome
- Descrição atual: $descricao
- Categoria: $categoria
- Marca: $marca
- Atributos: $atribs
- Quantidade de imagens: $imagens

REGRAS OBRIGATÓRIAS:
1. Título: máximo 120 caracteres, inclua marca + modelo + atributo principal + benefício chave. Sem símbolos especiais.
2. Descrição: estruturada em seções (Destaques, Especificações, Sobre a marca), mínimo 300 chars, use linguagem persuasiva e palavras-chave naturais.
3. Atributos: retorne lista completa de atributos relevantes para a categoria.
4. NÃO altere preços.
5. Se o produto tiver menos de 3 imagens, inclua alerta em "alerta_imagens".

Retorne SOMENTE um JSON válido:
{
  "titulo": "...",
  "descricao": "...",
  "atributos": [{"nome": "...", "valor": "..."}, ...],
  "palavras_chave": ["...", "..."],
  "alerta_imagens": null
}
PROMPT;
    }

    private function callAnthropic(string $prompt, string $apiKey, ?string &$error = null): ?string
    {
        $body = json_encode([
            'model'      => self::ANTHROPIC_MODEL,
            'max_tokens' => 4096,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init(self::ANTHROPIC_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::ANTHROPIC_VERSION,
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp     = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlErrmsg = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $curlErrno !== 0) {
            $error = "Anthropic: curl error ($curlErrno): $curlErrmsg";
            return null;
        }

        $data = json_decode($resp, true);

        if ($httpCode !== 200 || !is_array($data)) {
            $msg = $data['error']['message'] ?? substr($resp, 0, 300);
            $error = "Anthropic: HTTP $httpCode: $msg";
            return null;
        }

        $text = $data['content'][0]['text'] ?? null;
        if ($text === null) {
            $error = 'Anthropic: resposta 200 sem content[0].text: ' . substr($resp, 0, 300);
        }
        return $text;
    }

    private function callOpenAI(string $prompt, string $apiKey, ?string &$error = null): ?string
    {
        $body = json_encode([
            'model'    => self::OPENAI_MODEL,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init(self::OPENAI_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp     = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlErrmsg = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $curlErrno !== 0) {
            $error = "OpenAI: curl error ($curlErrno): $curlErrmsg";
            return null;
        }

        $data = json_decode($resp, true);

        if ($httpCode !== 200 || !is_array($data)) {
            $msg = $data['error']['message'] ?? substr($resp, 0, 300);
            $error = "OpenAI: HTTP $httpCode: $msg";
            return null;
        }

        $text = $data['choices'][0]['message']['content'] ?? null;
        if ($text === null) {
            $error = 'OpenAI: resposta 200 sem choices[0].message.content: ' . substr($resp, 0, 300);
        }
        return $text;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Rule-based fallback (sem IA)
    // ──────────────────────────────────────────────────────────────────────────

    private function optimizeRuleBased(array $product): ?array
    {
        $nome      = trim($product['nome'] ?? $product['descricao'] ?? '');
        $marca     = trim($product['marca']['nome'] ?? '');
        $categoria = trim($product['categoria']['nome'] ?? '');
        $sku       = trim($product['sku'] ?? '');

        $titulo = $this->buildRuleBasedTitle($nome, $marca, $sku);
        $descricao = $this->buildRuleBasedDescription($product);

        if ($titulo === $nome && strlen($descricao) < 100) return null;

        return [
            'titulo'        => $titulo,
            'descricao'     => $descricao,
            'palavras_chave' => $this->extractKeywords($nome . ' ' . $marca . ' ' . $categoria),
            'atributos'     => $product['atributos'] ?? [],
        ];
    }

    private function buildRuleBasedTitle(string $nome, string $marca, string $sku): string
    {
        $titulo = $nome;

        // Adiciona marca no início se não estiver presente
        if ($marca && stripos($titulo, $marca) === false) {
            $titulo = $marca . ' ' . $titulo;
        }

        // Normaliza espaços e limita a 120 chars
        $titulo = preg_replace('/\s+/', ' ', trim($titulo));
        if (strlen($titulo) > 120) {
            $titulo = substr($titulo, 0, 117) . '...';
        }

        return $titulo;
    }

    private function buildRuleBasedDescription(array $product): string
    {
        $nome     = $product['nome']      ?? $product['descricao'] ?? '';
        $marca    = $product['marca']['nome']     ?? '';
        $categ    = $product['categoria']['nome'] ?? '';
        $sku      = $product['sku']       ?? '';
        $unidade  = $product['unidade']   ?? '';
        $gtin     = $product['gtin']      ?? '';
        $atribs   = $product['atributos'] ?? [];
        $imagens  = count($product['imagens'] ?? []);
        $estoque  = $product['estoque']   ?? null;

        $lines = [];
        $lines[] = "✅ DESTAQUES DO PRODUTO";
        $lines[] = "• " . ($nome ?: $sku);
        if ($marca) $lines[] = "• Marca: $marca";
        if ($categ) $lines[] = "• Categoria: $categ";
        if ($unidade) $lines[] = "• Unidade: $unidade";

        if (!empty($atribs)) {
            $lines[] = "";
            $lines[] = "📋 ESPECIFICAÇÕES TÉCNICAS";
            foreach ($atribs as $a) {
                $n = $a['nome'] ?? '';
                $v = $a['valor'] ?? '';
                if ($n && $v) $lines[] = "• $n: $v";
            }
        }

        if ($gtin) {
            $lines[] = "";
            $lines[] = "🔖 SKU: $sku | GTIN/EAN: $gtin";
        } elseif ($sku) {
            $lines[] = "";
            $lines[] = "🔖 SKU: $sku";
        }

        $lines[] = "";
        $lines[] = "🛒 Compre com confiança! Produto original com garantia.";

        if ($imagens < 3) {
            $lines[] = "";
            $lines[] = "⚠️ [ALERTA: produto com apenas $imagens imagem(ns) — recomendado mínimo 3]";
        }

        return implode("\n", $lines);
    }

    private function extractKeywords(string $text): array
    {
        $words = preg_split('/[\s\-\/,]+/', strtolower($text));
        $words = array_filter($words, fn($w) => strlen($w) > 3);
        return array_values(array_unique(array_slice($words, 0, 10)));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Apply update to Tiny API
    // ──────────────────────────────────────────────────────────────────────────

    private function buildUpdatePayload(array $product, array $optimized): array
    {
        $payload = [];

        $tituloNovo = $optimized['titulo'] ?? '';
        if ($tituloNovo && $tituloNovo !== ($product['nome'] ?? '')) {
            $payload['nome'] = $tituloNovo;
        }

        $descNova = $optimized['descricao'] ?? '';
        if ($descNova && $descNova !== ($product['descricao'] ?? '')) {
            $payload['descricao'] = $descNova;
        }

        if (!empty($optimized['atributos']) && is_array($optimized['atributos'])) {
            $payload['atributos'] = $optimized['atributos'];
        }

        if (!empty($optimized['palavras_chave'])) {
            $payload['palavras_chave'] = implode(', ', $optimized['palavras_chave']);
        }

        // Garante que preços NÃO estão no payload
        unset($payload['preco'], $payload['preco_promocional'], $payload['preco_custo']);

        return $payload;
    }

    private function applyUpdate(int $id, array $payload, string $token): array
    {
        $ch = curl_init(self::API_BASE . '/produtos/' . $id);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401) return ['ok' => false, 'error' => 'Token expirado (401)'];
        if ($httpCode === 404) return ['ok' => false, 'error' => 'Produto não encontrado (404)'];
        if ($httpCode >= 400)  return ['ok' => false, 'error' => "HTTP $httpCode: " . substr((string)$body, 0, 200)];

        return ['ok' => true];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HTTP helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function httpGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) return ['error' => 'curl_error'];
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) return ['error' => 'invalid_json', '_http_status' => $httpCode];
        if ($httpCode !== 200) $decoded['_http_status'] = $httpCode;
        return $decoded;
    }

    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $decoded = json_decode($body ?: '', true);
        return is_array($decoded) ? $decoded : ['error' => 'invalid_response'];
    }
}
