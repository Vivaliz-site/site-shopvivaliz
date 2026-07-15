# ShopVivaliz - Security & Optimization Enhancements

**Date:** July 2026  
**Status:** Actively Deployed  
**Coverage:** Security, Performance, Code Quality, UX

---

## 📚 New Security & Performance Components

### 1. CSRF Protection (`includes/csrf-protection.php`)

Prevents Cross-Site Request Forgery attacks through token validation.

**Usage in HTML Forms:**
```html
<form method="POST" action="/process">
    <?php echo csrf_field(); ?>
    <input type="text" name="data">
    <button type="submit">Submit</button>
</form>
```

**Usage in PHP:**
```php
<?php
require_once 'includes/csrf-protection.php';

if ($_POST) {
    if (!csrf_verify()) {
        http_response_code(403);
        exit('CSRF token invalid');
    }
    // Process form
}
?>
```

**API Usage (JSON):**
```javascript
// In HTML data attribute:
<div data-csrf-token="<?php echo csrf_token(); ?>"></div>

// JavaScript:
const token = document.querySelector('[data-csrf-token]').dataset.csrfToken;
fetch('/api/endpoint', {
    method: 'POST',
    headers: {
        'X-CSRF-Token': token,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify(data)
});
```

---

### 2. Input Validation (`includes/input-validator.php`)

Comprehensive input validation and sanitization framework.

**Basic Usage:**
```php
<?php
require_once 'includes/input-validator.php';

$v = validator();

// Get and validate inputs
$email = $v->getEmail('email', true);  // Required email
$name = $v->getString('name', '', 1, 100);  // Optional string, 1-100 chars
$amount = $v->getMoney('amount', 0.0, 10000.0);  // Money value
$active = $v->getBoolean('active');  // Boolean
$category = $v->getEnum('category', ['electronics', 'clothing', 'books']);  // Enum

// Check for errors
if ($v->hasErrors()) {
    $errors = $v->getErrors();  // Array of errors
    // Return errors to user
}

// Use validated data
$data = $v->getValidated();
?>
```

**Supported Validators:**
- `getString()` - String with length validation
- `requireString()` - Required string
- `getEmail()` - Email validation
- `getInteger()` - Integer with range
- `getFloat()` - Float/decimal with range
- `getBoolean()` - Boolean conversion
- `getUrl()` - URL validation
- `getPhone()` - Phone number
- `getEnum()` - Enum/choice validation
- `getMoney()` - Currency validation

---

### 3. Security Headers (`includes/security-headers.php`)

HTTP security headers configuration.

**Setup:**
```php
<?php
require_once 'includes/security-headers.php';

// Call early in request
set_security_headers();

// Optional: Custom CSP
set_security_headers([
    'csp' => "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.example.com;"
]);

// HTTPS enforcement
enforce_https();

// Cache control
set_cache_control('public', 3600);  // Public, 1 hour
disable_cache();  // No caching

// Set JSON response headers
set_json_response(200);  // Sets Content-Type + appropriate headers
?>
```

**Headers Configured:**
- `X-Frame-Options: DENY` - Clickjacking protection
- `X-Content-Type-Options: nosniff` - MIME sniffing prevention
- `Content-Security-Policy` - XSS prevention
- `Strict-Transport-Security` - HTTPS enforcement
- `Referrer-Policy` - Referrer information control
- `Permissions-Policy` - Feature/capability restrictions

---

### 4. Centralized Logging (`includes/logger.php`)

Structured logging system with automatic rotation.

**Usage:**
```php
<?php
require_once 'includes/logger.php';

$logger = Logger::getInstance();

// Log messages
$logger->debug('Debug message', ['key' => 'value']);
$logger->info('User logged in', ['user_id' => 123]);
$logger->warning('High memory usage', ['memory' => '512MB']);
$logger->error('Database connection failed', ['host' => 'db.example.com']);
$logger->critical('Security issue detected', ['ip' => '192.168.1.1']);

// Log exceptions
try {
    throw new Exception('Something went wrong');
} catch (Exception $e) {
    $logger->exception($e, ['context' => 'user_registration']);
}

// Global function
sv_log('Application started', Logger::INFO);

// Retrieve recent logs
$lastLines = $logger->getTail(50);  // Last 50 lines
?>
```

**Log Format:**
```
[2026-07-09 14:30:45] [INFO] [a1b2c3d4e5f6g7h8] User logged in | {"user_id":123}
```

**Features:**
- Automatic log rotation (10MB default)
- Gzip compression of archived logs
- Request ID tracking for tracing
- Context data as JSON

---

### 5. Query Builder (`includes/query-builder.php`)

Safe parameterized SQL queries preventing SQL injection.

**Usage:**
```php
<?php
require_once 'includes/query-builder.php';

$db = new mysqli('localhost', 'user', 'pass', 'database');

// SELECT
$users = query($db, 'users')
    ->select(['id', 'email', 'name'])
    ->where('status', '=', 'active')
    ->andWhere('age', '>', 18)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// Get first result
$user = query($db, 'users')
    ->where('email', '=', 'user@example.com')
    ->first();

// Count
$count = query($db, 'users')
    ->where('status', '=', 'active')
    ->count();

// INSERT
query($db, 'users')->insert([
    'email' => 'new@example.com',
    'name' => 'New User',
    'status' => 'active'
]);

// UPDATE
query($db, 'users')
    ->where('id', '=', 123)
    ->update([
        'status' => 'inactive',
        'updated_at' => date('Y-m-d H:i:s')
    ]);

// DELETE
query($db, 'users')
    ->where('status', '=', 'deleted')
    ->delete();

// Last insert ID
$lastId = query($db, 'users')->lastInsertId();
?>
```

---

### 6. Performance Optimization (`includes/performance-optimization.php`)

Caching, image optimization, and performance metrics.

**Simple Cache:**
```php
<?php
require_once 'includes/performance-optimization.php';

$cache = new SimpleCache();

// Get from cache
$products = $cache->get('home_products');
if ($products === null) {
    // Fetch and cache (1 hour TTL)
    $products = fetchProductsFromDB();
    $cache->set('home_products', $products, 3600);
}
?>
```

**Lazy Image Loading:**
```php
<?php
echo lazy_image(
    '/images/product.jpg',
    'Product Image',
    [
        '320w' => '/images/product-320w.jpg',
        '640w' => '/images/product-640w.jpg',
        '1280w' => '/images/product-1280w.jpg',
    ],
    ['class' => 'product-image', 'id' => 'main-image']
);
// Outputs: <img loading="lazy" src="..." srcset="..." alt="...">
?>
```

**Cache Headers:**
```php
<?php
set_asset_cache_headers(31536000, 'image');  // 1 year for images
enable_output_compression();  // Gzip compression
?>
```

---

### 7. Security Bootstrap (`includes/security-bootstrap.php`)

Automatic security initialization.

**Add to every entry point:**
```php
<?php
require_once __DIR__ . '/includes/security-bootstrap.php';

// Now you have:
// - Security headers set
// - Session secured
// - Error/exception handlers active
// - HTTPS enforced (in production)
?>
```

---

## 🛡️ Apache Configuration (`.htaccess`)

Enhanced `.htaccess` with:
- **Security headers** via mod_headers
- **Gzip compression** via mod_deflate
- **Cache expiration** via mod_expires
- **Access blocks** for sensitive files
- **URL rewrites** for clean URLs

**Key Rules:**
```apache
# Block sensitive files
<FilesMatch "^(\.env|\.git|composer.json|package.json)">
    Require all denied
</FilesMatch>

# Security headers
Header set Strict-Transport-Security "max-age=31536000"
Header set X-Frame-Options "DENY"
Header set X-Content-Type-Options "nosniff"

# Cache optimization
ExpiresByType image/jpeg "access plus 30 days"
ExpiresByType application/javascript "access plus 30 days"
```

---

## 🎯 Integration Checklist

### Bootstrap Phase (All Entry Points)
- [ ] Include `security-bootstrap.php` at top of index.php
- [ ] Call `initialize_security()` early in app bootstrap
- [ ] Test CSRF token generation in forms

### Forms & User Input
- [ ] Add `csrf_field()` to all forms
- [ ] Use `InputValidator` for all input
- [ ] Check `$v->hasErrors()` before processing

### Database Queries
- [ ] Replace `mysqli` with `query()` builder
- [ ] Ensure all queries are parameterized
- [ ] Add logging for important operations

### API Endpoints
- [ ] Call `set_json_response()` at top
- [ ] Validate CSRF on POST/PUT/DELETE
- [ ] Use `InputValidator` for request data
- [ ] Log all API calls

### Logging
- [ ] Use `Logger::getInstance()` for errors
- [ ] Log security events (login, permission changes)
- [ ] Monitor log file size rotation

### Performance
- [ ] Enable `enable_output_compression()`
- [ ] Use `lazy_image()` for images
- [ ] Implement `SimpleCache` for expensive queries
- [ ] Set cache headers for static assets

---

## 📊 Metrics & Monitoring

**Performance Monitor:**
```php
<?php
require_once 'includes/logger.php';

PerformanceMonitor::start('query_time');
// ... expensive operation ...
$elapsed = PerformanceMonitor::stop('query_time');

PerformanceMonitor::increment('api_calls');
$metrics = PerformanceMonitor::getMetrics();
// Returns: timers, counters, memory usage
?>
```

**Security Assessment:**
```php
<?php
$assessment = get_security_assessment();
// Returns:
// - https: bool
// - hsts: bool
// - csp: bool
// - x_frame_options: bool
// - security_score: 0-100
?>
```

---

## 🚀 Deployment Checklist

- [ ] Environment variables configured (`.env` or runtime-secrets.php)
- [ ] HTTPS enabled on production
- [ ] Security headers verified (use Mozilla Observatory)
- [ ] CSRF protection active on all forms
- [ ] Logging directory writable
- [ ] Cache directory writable (for SimpleCache)
- [ ] .htaccess properly configured
- [ ] Error pages (404.php, 500.php) in place
- [ ] Database tables backed up
- [ ] Admin credentials changed from defaults

---

## 🔗 Related Documentation

- [OPTIMIZATION-PLAN.md](OPTIMIZATION-PLAN.md) - Phase-by-phase roadmap
- [CLAUDE.md](CLAUDE.md) - System architecture
- [README.md](README.md) - Project overview

---

**Last Updated:** July 9, 2026  
**Maintained By:** Claude Code Autonomous  
**Support:** Check logs in `/logs/` directory
