<?php
// Agent Keys Configuration
// This file is loaded by autonomous-watchdog.php to provide agent keys

if (!function_exists('ensure_agent_keys_configured')) {
    function ensure_agent_keys_configured(): void
    {
        $keys = [
            'SHOPVIVALIZ_AGENT_KEY' => 'RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k',
            'RUNTIME_AGENT_KEY' => 'RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k',
            'WATCHDOG_AGENT_KEY' => 'RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k',
            'AUTONOMOUS_AGENT_KEY' => 'RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k',
        ];

        foreach ($keys as $key => $value) {
            if (!getenv($key) && !isset($_SERVER[$key])) {
                putenv($key . '=' . $value);
                $_SERVER[$key] = $value;
            }
        }
    }
}

// Auto-configure on load
ensure_agent_keys_configured();
