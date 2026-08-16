<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';

/**
 * Fonte unica para cupons que podem ser anunciados publicamente.
 *
 * A consulta replica as mesmas regras de validade/visibilidade do endpoint
 * publico e falha fechada: se o banco nao estiver disponivel, nenhum cupom e
 * anunciado. O checkout continua sendo a autoridade final de elegibilidade.
 *
 * @return array<int,array{code:string,label:string,type:string,value:float,starts_at:mixed,ends_at:mixed,source:string}>
 */
function sv_active_coupons(int $limit = 6): array
{
    static $cached = null;

    if ($cached === null) {
        $apcu = function_exists('apcu_fetch') && function_exists('apcu_store');
        $apcuKey = 'sv_active_coupons_public_v1';
        if ($apcu) {
            $hit = false;
            $stored = apcu_fetch($apcuKey, $hit);
            if ($hit && is_array($stored)) {
                $cached = $stored;
            }
        }

        if ($cached === null) {
            $cached = [];

            try {
            $pdo = sv_pdo();
            $stmt = $pdo->query(
                'SELECT code, description, discount_type, discount_value,
                        starts_at, ends_at, expires_at, max_uses, used_count
                 FROM coupons
                 WHERE is_active = 1
                   AND display_in_popup = 1
                   AND (starts_at IS NULL OR starts_at <= NOW())
                   AND (COALESCE(expires_at, ends_at) IS NULL OR COALESCE(expires_at, ends_at) >= NOW())
                   AND (max_uses IS NULL OR max_uses = 0 OR used_count < max_uses)
                 ORDER BY
                   CASE discount_type
                     WHEN "percent" THEN 1
                     WHEN "shipping" THEN 2
                     ELSE 3
                   END,
                   discount_value DESC,
                   code ASC
                 LIMIT 6'
            );

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $seenCodes = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $code = strtoupper(trim((string)($row['code'] ?? '')));
                if ($code === '' || isset($seenCodes[$code])) {
                    continue;
                }

                $seenCodes[$code] = true;
                $cached[] = [
                    'code' => $code,
                    'label' => trim((string)($row['description'] ?? '')) ?: $code,
                    'type' => strtolower(trim((string)($row['discount_type'] ?? 'percent'))),
                    'value' => (float)($row['discount_value'] ?? 0),
                    'starts_at' => $row['starts_at'] ?? null,
                    'ends_at' => ($row['expires_at'] ?? null) ?: ($row['ends_at'] ?? null),
                    'source' => 'database',
                ];
            }
            } catch (Throwable $e) {
                error_log('[active-coupons] db lookup failed: ' . $e->getMessage());
            }

            // Fonte publica apenas para anuncio no navbar. O checkout/API
            // continuam validando o cupom na fonte autoritativa. TTL curto
            // reduz conexoes MySQL durante picos sem manter oferta stale.
            if ($apcu) {
                apcu_store($apcuKey, $cached, 60);
            }
        }
    }

    $limit = max(0, min(6, $limit));
    return $limit === 0 ? [] : array_slice($cached, 0, $limit);
}

/** @return array{code:string,label:string,type:string,value:float,starts_at:mixed,ends_at:mixed,source:string}|null */
function sv_primary_active_coupon(): ?array
{
    $coupons = sv_active_coupons(1);
    return $coupons[0] ?? null;
}

function sv_active_coupon_offer_text(array $coupon): string
{
    $type = strtolower(trim((string)($coupon['type'] ?? '')));
    $value = max(0.0, (float)($coupon['value'] ?? 0));

    if ($type === 'shipping') {
        return 'FRETE GRÁTIS';
    }

    if ($type === 'percent' && $value > 0) {
        $formatted = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
        return $formatted . '% OFF';
    }

    if ($type === 'fixed' && $value > 0) {
        return 'R$ ' . number_format($value, 2, ',', '.') . ' OFF';
    }

    return 'OFERTA ATIVA';
}

// A home ainda possui um banner legado com copy promocional estatica no
// template. Ao carregar a fonte de cupons pelo navbar, registra um filtro de
// saida que substitui essa copy pela promocao realmente ativa ou por texto
// evergreen quando nao ha cupom. Em outros scripts e no CLI nada e alterado.
$svActiveCouponScript = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
if ($svActiveCouponScript === 'index.php' && PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/promotion-output-filter.php';
    sv_promotion_output_filter_register();
}
