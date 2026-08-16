from pathlib import Path

p = Path('includes/analytics-tracking.php')
text = p.read_text(encoding='utf-8')
old = """    private function getClientId() {
        if (empty($_COOKIE['_ga'])) {
            $_COOKIE['_ga'] = bin2hex(random_bytes(8));
            setcookie('_ga', $_COOKIE['_ga'], [
                'expires' => time() + 63072000,
                'path' => '/',
                'secure' => $this->isHttps(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        return $_COOKIE['_ga'];
    }
"""
new = """    private function getClientId() {
        $gaCookie = trim((string)($_COOKIE['_ga'] ?? ''));
        if ($gaCookie !== '') {
            // GA4/gtag normally stores _ga as GA1.1.<client>.<timestamp>.
            // Measurement Protocol expects only the numeric client_id pair.
            if (preg_match('/^GA\\d+\\.\\d+\\.(\\d+\\.\\d+)$/', $gaCookie, $matches) === 1) {
                return $matches[1];
            }
            if (preg_match('/^\\d+\\.\\d+$/', $gaCookie) === 1) {
                return $gaCookie;
            }
        }

        // Do not emit Set-Cookie from the server on public/cacheable pages.
        // The browser GA tag owns the real _ga cookie. Until it exists, use
        // one request-local Measurement Protocol identifier only.
        static $ephemeralClientId = null;
        if ($ephemeralClientId === null) {
            $ephemeralClientId = random_int(100000000, 2147483647) . '.' . time();
        }
        return $ephemeralClientId;
    }
"""
count = text.count(old)
if count != 1:
    raise SystemExit(f'expected exactly one getClientId block, got {count}')
p.write_text(text.replace(old, new, 1), encoding='utf-8')
