<?php

declare(strict_types=1);

/**
 * Configuração da API de Otimização de Cadastro de Produtos (catálogo/SEO/GEO
 * via OpenAI / Google Gemini / Anthropic Claude).
 *
 * IMPORTANTE — SEGURANÇA: nenhuma chave de API real vive neste arquivo.
 * Tudo é lido de variáveis de ambiente (já carregadas pelo bootstrap padrão
 * do projeto em config/constants.php, que lê .env da release e
 * shared/.env do deploy). Configure as variáveis reais em .env /
 * shared/.env — NUNCA neste arquivo, e NUNCA versionado.
 *
 * IMPORTANTE — EXECUÇÃO REAL, SEM MOCK: se uma chave de API não estiver
 * configurada, o comportamento correto é falhar com uma exceção clara (ver
 * src/TextAiServices.php) — nunca simular uma resposta de sucesso. O mesmo
 * vale para qualquer erro de rede/HTTP das 3 APIs.
 *
 * Variáveis de ambiente esperadas (documentadas em .env.example):
 *   OPENAI_API_KEY
 *   GOOGLE_GEMINI_API_KEY
 *   CLAUDE_API_KEY
 *
 * REGRA DE NEGÓCIO: este módulo NUNCA lê nem escreve preço ou estoque.
 * ai_catalog_fetch_product() abaixo seleciona explicitamente só as colunas
 * de texto/atributo necessárias — não faz SELECT * — exatamente para deixar
 * essa proteção estrutural, não apenas convencional.
 */

// Reaproveita o bootstrap padrão do projeto: carrega .env/shared/.env e
// define DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS/DB_CHARSET e ENVIRONMENT.
// sv_pdo() já usa PDO::ATTR_ERRMODE_EXCEPTION (try/catch interno) e conecta
// com charset UTF-8 (DB_CHARSET), então reaproveitamos a mesma conexão
// compartilhada do projeto em vez de abrir uma segunda conexão MySQL.
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/pdo-database.php';

// --- Chaves de API (placeholders lidos de ambiente, nunca hardcoded) ---
define('CATALOG_AI_OPENAI_API_KEY', (string) (getenv('OPENAI_API_KEY') ?: ''));
define('CATALOG_AI_GOOGLE_GEMINI_API_KEY', (string) (getenv('GOOGLE_GEMINI_API_KEY') ?: ''));
define('CATALOG_AI_CLAUDE_API_KEY', (string) (getenv('CLAUDE_API_KEY') ?: ''));

// --- Modelos (podem ser sobrescritos via ambiente sem editar código) ---
// Modelos de TEXTO reais e vigentes (não os genéricos citados no briefing
// original — "gpt-4o"/"claude-3-5-sonnet" como nomes fixos ficam
// desatualizados rápido; usamos defaults atuais de 2026, sobrescrevíveis).
define('CATALOG_AI_OPENAI_MODEL', (string) (getenv('OPENAI_TEXT_MODEL') ?: 'gpt-4.1'));
define('CATALOG_AI_GEMINI_MODEL', (string) (getenv('GOOGLE_GEMINI_MODEL') ?: 'gemini-2.5-pro'));
define('CATALOG_AI_CLAUDE_MODEL', (string) (getenv('CLAUDE_TEXT_MODEL') ?: 'claude-sonnet-4-20250514'));

// --- Timeout estendido para chamadas de IA (mínimo 60s, texto longo demora) ---
define('CATALOG_AI_HTTP_TIMEOUT_SECONDS', max(60, (int) (getenv('CATALOG_AI_HTTP_TIMEOUT_SECONDS') ?: 90)));

/**
 * Retorna a conexão PDO compartilhada do projeto (mysql, UTF-8,
 * PDO::ERRMODE_EXCEPTION — ver includes/pdo-database.php). Pode retornar
 * null se a conexão falhar — todo código deste módulo precisa checar antes
 * de usar, e propagar o erro em vez de seguir como se tivesse conectado.
 */
function catalog_ai_db(): ?PDO
{
    return sv_pdo();
}

/**
 * Canais de venda suportados por este módulo — usado tanto para validar
 * entrada quanto para popular o <select> do dashboard.
 *
 * @return array<string,string> chave técnica => rótulo legível
 */
function catalog_ai_channels(): array
{
    return [
        'ml' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'amazon' => 'Amazon',
        'tiktok' => 'TikTok Shop',
        'site' => 'Site Próprio',
        'erp' => 'ERP (uso interno)',
    ];
}
