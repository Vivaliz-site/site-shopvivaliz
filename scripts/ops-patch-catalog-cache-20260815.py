from pathlib import Path

path = Path('catalogo.php')
text = path.read_text(encoding='utf-8')
old = """// Precisa iniciar a sessao antes de qualquer output: esta pagina tem HTML
// suficiente antes do include do navbar (JSON-LD, meta tags) para estourar o
// buffer de saida do PHP, o que envia os headers cedo e faz o session_start()
// tardio do navbar.php falhar silenciosamente (usuario aparece deslogado).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');
"""
new = """// Catalogo publico: visitantes anonimos nao precisam abrir sessao PHP.
// Retomamos a sessao somente quando ja existe cookie, preservando o estado de
// login. GET/HEAD anonimos podem usar cache curto na borda sem misturar usuarios.
$svCatalogMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$svCatalogSessionName = session_name();
$svCatalogHasSessionCookie = $svCatalogSessionName !== ''
    && isset($_COOKIE[$svCatalogSessionName])
    && trim((string)$_COOKIE[$svCatalogSessionName]) !== '';
$svCatalogPublicCache = in_array($svCatalogMethod, ['GET', 'HEAD'], true)
    && !$svCatalogHasSessionCookie;

if ($svCatalogHasSessionCookie && session_status() === PHP_SESSION_NONE) {
    @session_start();
}

header('Content-Type: text/html; charset=UTF-8');
if ($svCatalogPublicCache) {
    header('Cache-Control: public, max-age=15, s-maxage=30, stale-while-revalidate=60');
    header('Vary: Cookie');
} else {
    header('Cache-Control: private, no-store, max-age=0, must-revalidate');
    header('Pragma: no-cache');
}
"""
if text.count(old) != 1:
    raise SystemExit(f'expected exactly one catalog session block, got {text.count(old)}')
path.write_text(text.replace(old, new, 1), encoding='utf-8')
