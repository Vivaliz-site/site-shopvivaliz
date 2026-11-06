#!/usr/bin/env bash
set -Eeuo pipefail
[[ "$(id -u)" == 0 ]] || { echo "root_required" >&2; exit 2; }
base="$(cd "$(dirname "$0")/.." && pwd)"
[[ "$(hostname -s)" == "marina-a1-4c24g" ]] || { echo "wrong_host" >&2; exit 3; }
python3 -m py_compile "$base/ops/mysql-native-oci-backup.py"
install -o root -g root -m 0700 "$base/ops/mysql-native-oci-backup.py" /usr/local/sbin/shopvivaliz-mysql-native-oci-backup
install -o root -g root -m 0644 "$base/deploy/systemd/shopvivaliz-mysql-native-oci-backup.service" /etc/systemd/system/
install -o root -g root -m 0644 "$base/deploy/systemd/shopvivaliz-mysql-native-oci-backup.timer" /etc/systemd/system/
systemd-analyze verify /etc/systemd/system/shopvivaliz-mysql-native-oci-backup.service /etc/systemd/system/shopvivaliz-mysql-native-oci-backup.timer
systemctl daemon-reload
/usr/local/sbin/shopvivaliz-mysql-native-oci-backup --check
systemctl enable --now shopvivaliz-mysql-native-oci-backup.timer
systemctl is-enabled --quiet shopvivaliz-mysql-native-oci-backup.timer
systemctl is-active --quiet shopvivaliz-mysql-native-oci-backup.timer
echo "MYSQL_BACKUP_TIMER_INSTALLED=PASS"
