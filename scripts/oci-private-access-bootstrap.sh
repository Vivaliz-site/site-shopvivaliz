#!/usr/bin/env bash
set -Eeuo pipefail
workdir="$(mktemp -d /tmp/shopvivaliz-private-access.XXXXXX)"
cleanup() {
  rm -rf "$workdir"
}
trap cleanup EXIT

rustdesk_script="$workdir/setup-rustdesk-remote.sh"
ssh_script="$workdir/setup-agent-ssh.sh"
curl -fsSL https://raw.githubusercontent.com/Vivaliz-site/site-shopvivaliz/main/scripts/setup-rustdesk-remote.sh -o "$rustdesk_script"
curl -fsSL https://raw.githubusercontent.com/Vivaliz-site/site-shopvivaliz/main/scripts/setup-agent-ssh.sh -o "$ssh_script"
chmod 700 "$rustdesk_script" "$ssh_script"

agent_key=/home/ubuntu/.ssh/shopvivaliz_agent_ed25519
install -d -m 700 -o ubuntu -g ubuntu /home/ubuntu/.ssh
if [ ! -s "$agent_key" ]; then
  sudo -u ubuntu ssh-keygen -q -t ed25519 -N '' -C shopvivaliz-agent -f "$agent_key"
fi
chown ubuntu:ubuntu "$agent_key" "$agent_key.pub"
chmod 600 "$agent_key"
chmod 644 "$agent_key.pub"
agent_pub="$(cat "$agent_key.pub")"
test -n "$agent_pub"

sudo -n bash "$rustdesk_script" server_install
server_key="$(sudo -n cat /opt/shopvivaliz-rustdesk-server/data/id_ed25519.pub)"
test -n "$server_key"
sudo -n env RUSTDESK_SERVER_KEY="$server_key" RUSTDESK_ID_SERVER=10.0.1.38 bash "$rustdesk_script" client_install
sudo -n env SHOPVIVALIZ_AGENT_SSH_PUBKEY="$agent_pub" bash "$ssh_script" install

peer_key=/home/ubuntu/.ssh/shopvivaliz-free-a1-monitor
test -s "$peer_key"
site_known_hosts=/home/ubuntu/.ssh/known_hosts
test -s "$site_known_hosts"

scp -q -i "$peer_key" -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$site_known_hosts" \
  "$rustdesk_script" "$ssh_script" ubuntu@10.0.1.112:/tmp/

server_key_b64="$(printf '%s' "$server_key" | base64 -w0)"
agent_pub_b64="$(printf '%s' "$agent_pub" | base64 -w0)"
ssh -i "$peer_key" -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$site_known_hosts" \
  ubuntu@10.0.1.112 \
  "set -Eeuo pipefail; server_key=\$(printf '%s' '$server_key_b64' | base64 -d); agent_pub=\$(printf '%s' '$agent_pub_b64' | base64 -d); sudo -n env RUSTDESK_SERVER_KEY=\"\$server_key\" RUSTDESK_ID_SERVER=10.0.1.38 bash /tmp/setup-rustdesk-remote.sh client_install; sudo -n env SHOPVIVALIZ_AGENT_SSH_PUBKEY=\"\$agent_pub\" bash /tmp/setup-agent-ssh.sh install; rm -f /tmp/setup-rustdesk-remote.sh /tmp/setup-agent-ssh.sh"

backend_known_hosts="$workdir/backend_known_hosts"
backend_host_key="$(sudo -n cat /etc/ssh/ssh_host_ed25519_key.pub)"
test -n "$backend_host_key"
printf '10.0.1.38 %s\n' "$backend_host_key" > "$backend_known_hosts"
chmod 600 "$backend_known_hosts"
backend_probe="$(ssh -i "$agent_key" -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$backend_known_hosts" -o ConnectTimeout=10 shopvivaliz-agent@10.0.1.38 'sudo -n /usr/local/sbin/shopvivaliz-agent-ops host-status')"
printf '%s\n' "$backend_probe" | grep -q 'always-free-arm-1787907847-26'

site_probe="$(ssh -i "$agent_key" -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$site_known_hosts" -o ConnectTimeout=10 shopvivaliz-agent@10.0.1.112 'sudo -n /usr/local/sbin/shopvivaliz-agent-ops host-status')"
printf '%s\n' "$site_probe" | grep -q 'shopvivaliz-free-a1'

backend_rustdesk="$(sudo -n bash "$rustdesk_script" status)"
printf '%s\n' "$backend_rustdesk" | grep -q 'RUSTDESK_CLIENT_INSTALLED=true'
printf '%s\n' "$backend_rustdesk" | grep -q 'RUSTDESK_HBBS=running'
printf '%s\n' "$backend_rustdesk" | grep -q 'RUSTDESK_HBBR=running'

site_rustdesk="$(ssh -i "$peer_key" -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$site_known_hosts" ubuntu@10.0.1.112 'sudo -n bash -s -- status' < "$rustdesk_script")"
printf '%s\n' "$site_rustdesk" | grep -q 'RUSTDESK_CLIENT_INSTALLED=true'

backend_id="$(printf '%s\n' "$backend_rustdesk" | sed -n 's/^RUSTDESK_ID=//p' | head -1)"
site_id="$(printf '%s\n' "$site_rustdesk" | sed -n 's/^RUSTDESK_ID=//p' | head -1)"
test -n "$backend_id"
test -n "$site_id"
test "$backend_id" != unavailable
test "$site_id" != unavailable

echo "PRIVATE_ACCESS_BOOTSTRAP=PASS"
echo "AGENT_SSH_BACKEND=PASS"
echo "AGENT_SSH_SITE=PASS"
echo "RUSTDESK_SERVER=PASS"
echo "RUSTDESK_BACKEND_ID=$backend_id"
echo "RUSTDESK_SITE_ID=$site_id"
