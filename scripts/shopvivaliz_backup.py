#!/usr/bin/env python3
"""
ShopVivaliz Backup & Disaster Recovery

Sistema automático de backup com snapshots diários.
"""

import os
import json
import shutil
import gzip
import subprocess
from datetime import datetime, timedelta
from pathlib import Path
from typing import Dict, List, Optional
import sqlite3

class BackupManager:
    """Gerenciador de backups."""

    def __init__(self, backup_dir: str = "backups", retention_days: int = 30):
        self.backup_dir = Path(backup_dir)
        self.backup_dir.mkdir(exist_ok=True)
        self.retention_days = retention_days
        self.timestamp = datetime.utcnow().strftime("%Y%m%d_%H%M%S")

    def backup_sqlite(self, db_path: str) -> str:
        """Fazer backup de arquivo SQLite."""
        db_path = Path(db_path)
        if not db_path.exists():
            return None

        backup_name = f"shopvivaliz_{self.timestamp}.sqlite.gz"
        backup_path = self.backup_dir / backup_name

        try:
            with open(db_path, 'rb') as f_in:
                with gzip.open(backup_path, 'wb') as f_out:
                    shutil.copyfileobj(f_in, f_out)

            size_mb = backup_path.stat().st_size / (1024 * 1024)
            return {
                "type": "sqlite",
                "name": backup_name,
                "path": str(backup_path),
                "size_mb": round(size_mb, 2),
                "timestamp": self.timestamp,
                "status": "success"
            }
        except Exception as e:
            return {
                "type": "sqlite",
                "name": backup_name,
                "error": str(e),
                "status": "failed"
            }

    def backup_postgres(self, host: str, user: str, password: str, database: str) -> str:
        """Fazer backup de PostgreSQL."""
        backup_name = f"shopvivaliz_{self.timestamp}.postgres.sql.gz"
        backup_path = self.backup_dir / backup_name

        try:
            env = os.environ.copy()
            env['PGPASSWORD'] = password

            cmd = f'pg_dump -h {host} -U {user} -d {database} | gzip > {backup_path}'
            result = subprocess.run(cmd, shell=True, env=env, capture_output=True, text=True)

            if result.returncode == 0:
                size_mb = backup_path.stat().st_size / (1024 * 1024)
                return {
                    "type": "postgres",
                    "name": backup_name,
                    "path": str(backup_path),
                    "size_mb": round(size_mb, 2),
                    "timestamp": self.timestamp,
                    "status": "success"
                }
            else:
                return {
                    "type": "postgres",
                    "name": backup_name,
                    "error": result.stderr,
                    "status": "failed"
                }
        except Exception as e:
            return {
                "type": "postgres",
                "name": backup_name,
                "error": str(e),
                "status": "failed"
            }

    def backup_redis(self, host: str = "localhost", port: int = 6379) -> str:
        """Fazer backup de Redis."""
        backup_name = f"shopvivaliz_{self.timestamp}.redis.rdb"
        backup_path = self.backup_dir / backup_name

        try:
            cmd = f"redis-cli -h {host} -p {port} BGSAVE"
            result = subprocess.run(cmd, shell=True, capture_output=True, text=True)

            if "Background saving started" in result.stdout or result.returncode == 0:
                # Copiar arquivo RDB padrão
                redis_rdb = Path(f"/var/lib/redis/dump.rdb")
                if redis_rdb.exists():
                    shutil.copy(redis_rdb, backup_path)
                    size_mb = backup_path.stat().st_size / (1024 * 1024)
                    return {
                        "type": "redis",
                        "name": backup_name,
                        "path": str(backup_path),
                        "size_mb": round(size_mb, 2),
                        "timestamp": self.timestamp,
                        "status": "success"
                    }

            return {
                "type": "redis",
                "name": backup_name,
                "error": "Redis backup not found",
                "status": "warning"
            }
        except Exception as e:
            return {
                "type": "redis",
                "name": backup_name,
                "error": str(e),
                "status": "failed"
            }

    def backup_configs(self) -> Dict:
        """Fazer backup de arquivos de config."""
        backup_name = f"shopvivaliz_{self.timestamp}.configs.tar.gz"
        backup_path = self.backup_dir / backup_name

        try:
            config_files = [
                ".env.example",
                "prometheus.yml",
                "docker-compose.yml",
                "Dockerfile",
                ".claude/settings.json"
            ]

            cmd = f"tar -czf {backup_path} {' '.join(config_files)} 2>/dev/null || true"
            subprocess.run(cmd, shell=True, capture_output=True)

            if backup_path.exists():
                size_mb = backup_path.stat().st_size / (1024 * 1024)
                return {
                    "type": "configs",
                    "name": backup_name,
                    "path": str(backup_path),
                    "size_mb": round(size_mb, 2),
                    "timestamp": self.timestamp,
                    "status": "success"
                }
            else:
                return {
                    "type": "configs",
                    "name": backup_name,
                    "error": "Failed to create tar",
                    "status": "failed"
                }
        except Exception as e:
            return {
                "type": "configs",
                "name": backup_name,
                "error": str(e),
                "status": "failed"
            }

    def full_backup(self) -> Dict:
        """Executar backup completo."""
        results = {
            "timestamp": self.timestamp,
            "backups": []
        }

        # SQLite
        sqlite_result = self.backup_sqlite("shopvivaliz.db")
        if sqlite_result:
            results["backups"].append(sqlite_result)

        # Configs
        config_result = self.backup_configs()
        results["backups"].append(config_result)

        # Redis (opcional)
        try:
            redis_result = self.backup_redis()
            results["backups"].append(redis_result)
        except:
            pass

        # Salvar manifest
        manifest_path = self.backup_dir / f"manifest_{self.timestamp}.json"
        with open(manifest_path, 'w') as f:
            json.dump(results, f, indent=2)

        results["manifest"] = str(manifest_path)
        return results

    def cleanup_old_backups(self) -> Dict:
        """Limpar backups mais antigos que retention_days."""
        cutoff = datetime.utcnow() - timedelta(days=self.retention_days)
        removed = []
        kept = []

        for backup_file in self.backup_dir.glob("shopvivaliz_*"):
            try:
                file_mtime = datetime.fromtimestamp(backup_file.stat().st_mtime)
                if file_mtime < cutoff:
                    backup_file.unlink()
                    removed.append(backup_file.name)
                else:
                    kept.append(backup_file.name)
            except:
                pass

        return {
            "removed": len(removed),
            "kept": len(kept),
            "removed_files": removed,
            "cutoff_date": cutoff.isoformat()
        }

    def list_backups(self) -> List[Dict]:
        """Listar todos os backups."""
        backups = []
        for backup_file in sorted(self.backup_dir.glob("shopvivaliz_*"), reverse=True):
            backups.append({
                "name": backup_file.name,
                "path": str(backup_file),
                "size_mb": round(backup_file.stat().st_size / (1024 * 1024), 2),
                "created": datetime.fromtimestamp(backup_file.stat().st_mtime).isoformat()
            })
        return backups

class RestoreManager:
    """Gerenciador de restore."""

    @staticmethod
    def restore_sqlite(backup_path: str, target_path: str = "shopvivaliz.db") -> bool:
        """Restaurar backup SQLite."""
        try:
            backup_path = Path(backup_path)
            if not backup_path.exists():
                return False

            target_path = Path(target_path)

            # Backup do arquivo atual
            if target_path.exists():
                target_path.rename(target_path.with_suffix('.bak'))

            # Descomprimir
            if backup_path.suffix == '.gz':
                with gzip.open(backup_path, 'rb') as f_in:
                    with open(target_path, 'wb') as f_out:
                        shutil.copyfileobj(f_in, f_out)
            else:
                shutil.copy(backup_path, target_path)

            # Validar
            conn = sqlite3.connect(target_path)
            conn.execute("SELECT 1")
            conn.close()

            return True
        except Exception as e:
            print(f"❌ Restore falhou: {e}")
            return False

    @staticmethod
    def restore_postgres(backup_path: str, host: str, user: str, password: str, database: str) -> bool:
        """Restaurar backup PostgreSQL."""
        try:
            env = os.environ.copy()
            env['PGPASSWORD'] = password

            if backup_path.endswith('.gz'):
                cmd = f'gunzip -c {backup_path} | psql -h {host} -U {user} -d {database}'
            else:
                cmd = f'psql -h {host} -U {user} -d {database} < {backup_path}'

            result = subprocess.run(cmd, shell=True, env=env, capture_output=True, text=True)
            return result.returncode == 0
        except Exception as e:
            print(f"❌ Restore falhou: {e}")
            return False

    @staticmethod
    def restore_configs(backup_path: str, target_dir: str = ".") -> bool:
        """Restaurar configs."""
        try:
            cmd = f"tar -xzf {backup_path} -C {target_dir}"
            result = subprocess.run(cmd, shell=True, capture_output=True)
            return result.returncode == 0
        except Exception as e:
            print(f"❌ Restore falhou: {e}")
            return False

if __name__ == "__main__":
    import sys

    if len(sys.argv) > 1:
        cmd = sys.argv[1]

        if cmd == "backup":
            manager = BackupManager()
            result = manager.full_backup()
            print("✅ Backup Completo:")
            for backup in result['backups']:
                status = "✅" if backup['status'] == 'success' else "⚠️"
                size = f" ({backup.get('size_mb', 0)} MB)" if 'size_mb' in backup else ""
                print(f"  {status} {backup['type']}{size}")

        elif cmd == "list":
            manager = BackupManager()
            backups = manager.list_backups()
            print(f"📦 Backups ({len(backups)}):")
            for backup in backups:
                print(f"  {backup['name']} ({backup['size_mb']} MB) - {backup['created']}")

        elif cmd == "cleanup":
            manager = BackupManager()
            result = manager.cleanup_old_backups()
            print(f"🧹 Limpeza de Backups:")
            print(f"  Removidos: {result['removed']}")
            print(f"  Mantidos: {result['kept']}")

        elif cmd == "restore":
            backup_file = sys.argv[2] if len(sys.argv) > 2 else None
            if backup_file:
                if RestoreManager.restore_sqlite(backup_file):
                    print(f"✅ Restaurado: {backup_file}")
                else:
                    print(f"❌ Falha ao restaurar: {backup_file}")
            else:
                print("Uso: python shopvivaliz_backup.py restore <backup_file>")

        else:
            print("Uso: python shopvivaliz_backup.py <backup|list|cleanup|restore>")
    else:
        manager = BackupManager()
        backups = manager.list_backups()
        print(f"📦 Total de backups: {len(backups)}")
        if backups:
            print(f"   Mais recente: {backups[0]['name']}")
