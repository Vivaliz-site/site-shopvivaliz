#!/usr/bin/env python3
from pathlib import Path


def replace_once(path: Path, old: str, new: str, label: str) -> None:
    text = path.read_text(encoding="utf-8")
    if old not in text:
        raise SystemExit(f"{label}_EXPECTED_TEXT_NOT_FOUND")
    path.write_text(text.replace(old, new, 1), encoding="utf-8")


index = Path("index.php")
replace_once(
    index,
    "'alt' => 'Banner Vivaliz com 5% de desconto na primeira compra',",
    "'alt' => 'Banner Vivaliz com 3% OFF automatico em carrinho com 2 ou mais produtos diferentes',",
    "INDEX_ALT",
)
replace_once(
    index,
    "'subtitle' => 'Ganhe 10% de desconto na sua primeira compra com o cupom VIVALIZ10.',",
    "'subtitle' => 'Leve 2 ou mais produtos diferentes e ganhe 3% OFF automatico no carrinho.',",
    "INDEX_SUBTITLE",
)
replace_once(
    index,
    "'primary' => ['label' => 'Aproveitar Desconto', 'href' => '/catalogo'],",
    "'primary' => ['label' => 'Ver produtos', 'href' => '/catalogo'],",
    "INDEX_CTA",
)

sanitizer = Path("includes/product-trust-sanitizer.php")
old_sanitizer = '''    // NOTA HISTORICA: ate 2026-08-15 esta funcao substituia o badge de PIX/
    // parcelamento por um texto generico ("disponivel no checkout"), sob a
    // premissa de que o valor exibido nao era calculado de forma autoritativa.
    // Isso deixou de ser verdade: produto.php calcula pixPrice e
    // installmentValue diretamente de $priceRaw (preco real do produto) --
    // ver produto.php linhas ~702-709. Sanitizar esse bloco hoje so serve pra
    // apagar um desconto real que o cliente teria direito de ver. Removido.
'''
new_sanitizer = '''    // Condicoes de PIX/parcelamento so podem ser exibidas quando forem
    // autoritativas no pedido. Mantemos copy neutra no storefront para nao
    // transformar um calculo visual em promessa comercial nao aprovada.
    $sanitized = preg_replace(
        '~<div\\s+class="pix-discount-badge"[^>]*>.*?</div>~si',
        '<div class="pix-discount-badge" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-weight:700;font-size:14px;"><span>PIX disponível no checkout</span></div>',
        $sanitized,
        1
    ) ?? $sanitized;
    $sanitized = preg_replace(
        '~<div\\s+class="installment-label"[^>]*>.*?</div>~si',
        '<div class="installment-label" style="font-size:13px;color:#64748b;font-weight:600;"><span>Parcelamento disponível no checkout</span></div>',
        $sanitized,
        1
    ) ?? $sanitized;
'''
replace_once(sanitizer, old_sanitizer, new_sanitizer, "SANITIZER")

ads = Path(".github/workflows/google-ads-antique-decore-status.yml")
replace_once(
    ads,
    "VOLTEI5|5%[[:space:]]*OFF[[:space:]]*NA[[:space:]]*1|5%[[:space:]]*(OFF|DE DESCONTO).*(PRIMEIRA|1.?)[[:space:]]*COMPRA",
    "VOLTEI5|VIVALIZ10|PRIMEIRA10|10%[[:space:]]*(OFF|DE DESCONTO).*(PRIMEIRA|1.?)[[:space:]]*COMPRA|5%[[:space:]]*OFF[[:space:]]*NA[[:space:]]*1|5%[[:space:]]*(OFF|DE DESCONTO).*(PRIMEIRA|1.?)[[:space:]]*COMPRA",
    "ADS_GUARD",
)

Path("tests/commercial-policy-regression-test.php").write_text(
    '''<?php\ndeclare(strict_types=1);\n\nfunction policy_assert(bool $ok, string $message): void {\n    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\\n"); exit(1); }\n}\n\n$index = file_get_contents(dirname(__DIR__) . '/index.php') ?: '';\n$sanitizer = file_get_contents(dirname(__DIR__) . '/includes/product-trust-sanitizer.php') ?: '';\n\npolicy_assert(!str_contains($index, 'VIVALIZ10'), 'public home must not advertise legacy VIVALIZ10');\npolicy_assert(!str_contains($index, 'PRIMEIRA10'), 'public home must not advertise legacy PRIMEIRA10');\npolicy_assert(!preg_match('/10%[^\\n]{0,100}(primeira|1.?)\\s*compra/iu', $index), 'public home must not claim 10% first purchase');\npolicy_assert(str_contains($index, '3% OFF automatico no carrinho'), 'home must advertise the approved automatic 3% cart offer');\npolicy_assert(str_contains($sanitizer, 'PIX disponível no checkout'), 'PIX claim must stay neutral until checkout-authoritative');\npolicy_assert(str_contains($sanitizer, 'Parcelamento disponível no checkout'), 'installment claim must stay neutral until checkout-authoritative');\n\nfwrite(STDOUT, "commercial_policy_regression=ok\\n");\n''',
    encoding="utf-8",
)

print("COMMERCIAL_POLICY_RECONCILED")
