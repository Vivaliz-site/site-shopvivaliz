#!/usr/bin/env python3
"""Force sync and configure agent keys on production server."""

import os
import subprocess
import sys
from pathlib import Path

AGENT_KEY = "RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k"
REPO_ROOT = Path(__file__).parent.parent

def run_command(cmd, description):
    """Run a shell command."""
    print(f"▶ {description}...")
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if result.returncode != 0:
        print(f"  ⚠️  {result.stderr}")
    else:
        print(f"  ✅ OK")
    return result.returncode == 0

def main():
    os.chdir(REPO_ROOT)
    
    print("🔄 Sincronizando com GitHub...")
    run_command("git fetch origin", "Fetching origin")
    run_command("git reset --hard origin/main", "Reset hard to origin/main")
    
    print("\n🔑 Configurando agent keys...")
    
    # Ensure config directory exists
    config_dir = REPO_ROOT / "config"
    config_dir.mkdir(exist_ok=True)
    
    # Write agent keys PHP file
    agent_keys_file = config_dir / "agent-keys.php"
    agent_keys_content = f'''<?php
// Agent Keys - Auto-configured on {import_datetime().datetime.now().isoformat()}
if (!function_exists('ensure_agent_keys_configured')) {{
    function ensure_agent_keys_configured(): void {{
        $keys = [
            'SHOPVIVALIZ_AGENT_KEY' => '{AGENT_KEY}',
            'RUNTIME_AGENT_KEY' => '{AGENT_KEY}',
            'WATCHDOG_AGENT_KEY' => '{AGENT_KEY}',
            'AUTONOMOUS_AGENT_KEY' => '{AGENT_KEY}',
        ];
        foreach ($keys as $key => $value) {{
            if (!getenv($key) && !isset($_SERVER[$key])) {{
                putenv($key . '=' . $value);
                $_SERVER[$key] = $value;
            }}
        }}
    }}
}}
ensure_agent_keys_configured();
'''
    
    try:
        agent_keys_file.write_text(agent_keys_content)
        print(f"  ✅ {agent_keys_file} configurado")
    except Exception as e:
        print(f"  ❌ Erro: {e}")
        return 1
    
    # Also create/update .env if it exists
    env_file = REPO_ROOT / ".env"
    if env_file.exists():
        try:
            content = env_file.read_text()
            if "SHOPVIVALIZ_AGENT_KEY" not in content:
                env_file.write_text(content + f"\nSHOPVIVALIZ_AGENT_KEY={AGENT_KEY}\nRUNTIME_AGENT_KEY={AGENT_KEY}\nWATCHDOG_AGENT_KEY={AGENT_KEY}\nAUTONOMOUS_AGENT_KEY={AGENT_KEY}\n")
                print(f"  ✅ .env atualizado com agent keys")
        except Exception as e:
            print(f"  ⚠️  Não consegui atualizar .env: {e}")
    
    print("\n✅ Sincronização e configuração concluídas!")
    print(f"   Agent key: {AGENT_KEY}")
    return 0

if __name__ == "__main__":
    import datetime as import_datetime
    sys.exit(main())
