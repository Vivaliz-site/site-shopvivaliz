#!/bin/bash
# Emergency script to clear all caches after code update
# Called by CI/CD or manually when urgent refresh needed

echo "[$(date)] Emergency cache clear triggered..."

# Clear PHP cache
if command -v php-fpm7.4 &> /dev/null; then
    echo "  - Restarting PHP-FPM..."
    sudo systemctl restart php-fpm7.4 2>/dev/null || true
elif command -v php-fpm &> /dev/null; then
    echo "  - Restarting PHP-FPM..."
    sudo systemctl restart php-fpm 2>/dev/null || true
fi

# Clear Apache cache (if mod_cache enabled)
if command -v a2dismod &> /dev/null; then
    echo "  - Clearing Apache cache..."
    sudo systemctl restart apache2 2>/dev/null || true
fi

# Clear system page cache (if allowed)
if [ -w /proc/sys/vm/drop_caches ]; then
    echo "  - Clearing system page cache..."
    echo 3 | sudo tee /proc/sys/vm/drop_caches > /dev/null 2>&1 || true
fi

# Clear temp directories
echo "  - Clearing temp files..."
rm -rf /tmp/php-* 2>/dev/null || true
rm -rf /var/cache/php* 2>/dev/null || true

echo "[$(date)] Cache clear completed!"
echo "  Server should now serve fresh code."
