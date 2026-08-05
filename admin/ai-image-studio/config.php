<?php

declare(strict_types=1);

/**
 * Configuração do módulo AI Image Studio (EDIÇÃO de imagens de produto via
 * OpenAI /images/edits, Google Gemini multimodal, ou Claude-otimização de
 * prompt — sempre partindo da foto REAL do produto já cadastrada no banco,
 * nunca geração pura por texto).
 *
 * IMPORTANTE — SEGURANÇA: nenhuma chave de API real vive neste arquivo.
 * Tudo é lido de variáveis de ambiente (já carregadas pelo bootstrap padrão
 * do projeto em config/constants.php, que lê .env da release e
 * shared/.env do deploy). Configure as variáveis reais em .env /
 * shared/.env — NUNCA neste arquivo, e NUNCA versionado.
 *
 * Variáveis de ambiente esperadas (documentadas em .env.example):
 *   OPENAI_API_KEY
 *   GOOGLE_IMAGEN_API_KEY      (chave da Gemini API / Google AI Studio)
 *   GOOGLE_IMAGEN_PROJECT_ID   (não usado na integração atual — reservado
 *                                caso migre para Vertex AI/OAuth no futuro)
 *   GOOGLE_IMAGEN_LOCATION     (idem, não usado na integração atual)
 *   CLAUDE_API_KEY
 */

// Reaproveita o bootstrap padrão do projeto: carrega .env/shared/.env e
// define DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS/DB_CHARSET e ENVIRONMENT.
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/pdo-database.php';

// --- Chaves de API (placeholders lidos de ambiente, nunca hardcoded) ---
// NOTA 2026-08-05: o resto do projeto (Liz, config/constants.php,
// squad-chat.php etc.) já usa chaves reais e funcionando sob os nomes
// GEMINI_API_KEY e ANTHROPIC_API_KEY (confirmado em produção: Liz respondeu
// de verdade via provider 'gemini'). Este módulo tentava nomes próprios
// (GOOGLE_IMAGEN_API_KEY, CLAUDE_API_KEY) que nunca foram configurados —
// por isso caía em "sem chave" mesmo com a chave real já existindo no
// projeto. Agora tenta primeiro o nome específico deste módulo (permite
// override futuro sem tocar na chave compartilhada) e cai para a variável
// já usada pelo resto do sistema, sem duplicar segredo em lugar nenhum.
define('AI_STUDIO_OPENAI_API_KEY', (string) (getenv('OPENAI_API_KEY') ?: ''));
define('AI_STUDIO_GOOGLE_IMAGEN_API_KEY', (string) (getenv('GOOGLE_IMAGEN_API_KEY') ?: (getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_GEMINI_API_KEY')) ?: ''));
define('AI_STUDIO_GOOGLE_IMAGEN_PROJECT_ID', (string) (getenv('GOOGLE_IMAGEN_PROJECT_ID') ?: ''));
define('AI_STUDIO_GOOGLE_IMAGEN_LOCATION', (string) (getenv('GOOGLE_IMAGEN_LOCATION') ?: 'us-central1'));
define('AI_STUDIO_CLAUDE_API_KEY', (string) (getenv('CLAUDE_API_KEY') ?: getenv('ANTHROPIC_API_KEY') ?: ''));

// --- Modelos (podem ser sobrescritos via ambiente sem editar código) ---
// gpt-image-1 é o modelo da OpenAI que suporta o endpoint /v1/images/edits
// (edição com imagem de referência) usado por este módulo.
define('AI_STUDIO_OPENAI_IMAGE_MODEL', (string) (getenv('OPENAI_IMAGE_MODEL') ?: 'gpt-image-1'));
// gemini-2.5-flash-image ("Nano Banana") é o modelo multimodal do Google que
// aceita imagem + texto como entrada e devolve imagem editada via
// :generateContent — NÃO confundir com os modelos Imagen clássicos
// (imagen-4.0-generate-001 etc), que são text-to-image puro e não aceitam
// imagem de referência.
define('AI_STUDIO_GOOGLE_IMAGEN_MODEL', (string) (getenv('GOOGLE_IMAGEN_MODEL') ?: 'gemini-2.5-flash-image'));
define('AI_STUDIO_CLAUDE_MODEL', (string) (getenv('CLAUDE_MODEL') ?: 'claude-3-7-sonnet-20250219'));

// --- Diretórios físicos de staging ---
// Imagens geradas/editadas (resultado final, pendente de aprovação):
define('AI_STUDIO_STORAGE_DIR', __DIR__ . '/storage/staging/');
define('AI_STUDIO_STORAGE_URL_PREFIX', '/admin/ai-image-studio/storage/staging/');
// Cópias temporárias da foto REAL do produto (baixadas do banco/CDN só para
// servir de referência de entrada às APIs) — nunca expostas por URL pública,
// e sempre apagadas logo após o uso (ver process_item.php).
define('AI_STUDIO_BASE_IMAGE_TMP_DIR', __DIR__ . '/storage/base-image-tmp/');
if (!is_dir(AI_STUDIO_BASE_IMAGE_TMP_DIR)) {
    if (!@mkdir(AI_STUDIO_BASE_IMAGE_TMP_DIR, 0775, true) && !is_dir(AI_STUDIO_BASE_IMAGE_TMP_DIR)) {
        error_log('[ai-image-studio] Falha ao criar diretório temporário de imagem base: ' . AI_STUDIO_BASE_IMAGE_TMP_DIR);
    }
}

// Garante que o diretório de staging existe e é gravável (Ubuntu: www-data
// precisa ter permissão de escrita — ver nota de troubleshooting abaixo).
if (!is_dir(AI_STUDIO_STORAGE_DIR)) {
    if (!@mkdir(AI_STUDIO_STORAGE_DIR, 0775, true) && !is_dir(AI_STUDIO_STORAGE_DIR)) {
        error_log('[ai-image-studio] Falha ao criar diretório de staging: ' . AI_STUDIO_STORAGE_DIR);
    }
}

/**
 * Troubleshooting de permissões no Ubuntu (VM de produção):
 *   sudo mkdir -p /caminho/do/projeto/admin/ai-image-studio/storage/staging
 *   sudo chown -R www-data:www-data /caminho/do/projeto/admin/ai-image-studio/storage
 *   sudo chmod -R 0775 /caminho/do/projeto/admin/ai-image-studio/storage
 * Se o processo PHP-FPM/Apache rodar sob outro usuário, ajuste o chown de
 * acordo (confira com `ps aux | grep php-fpm` ou `apachectl -S`).
 */

/**
 * Retorna a conexão PDO compartilhada do projeto (mysql, ver
 * includes/pdo-database.php). Pode retornar null se a conexão falhar — todo
 * código deste módulo precisa checar antes de usar.
 */
function ai_studio_db(): ?PDO
{
    return sv_pdo();
}
