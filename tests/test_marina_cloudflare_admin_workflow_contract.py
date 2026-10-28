from pathlib import Path


WORKFLOW = Path(__file__).parents[1] / ".github" / "workflows" / "oci-marina-cloudflare-admin-tunnel.yml"


def test_marina_cloudflare_workflow_exists_and_is_explicitly_gated() -> None:
    text = WORKFLOW.read_text(encoding="utf-8")
    assert "OCI_ESPOSA_PRIVATE_KEY" in text
    assert "CLOUDFLARE_API_TOKEN" in text
    assert "CLOUDFLARE_MARINA_TUNNEL_TOKEN" in text
    assert "PROVISION_MARINA_ADMIN_TUNNEL" in text
    assert "environment: Production" in text
    assert "shopvivaliz-oci-marina-admin" in text
    assert "admin-marina.shopvivaliz.com.br" in text


def test_marina_cloudflare_workflow_does_not_log_or_embed_secrets() -> None:
    text = WORKFLOW.read_text(encoding="utf-8")
    forbidden = (
        "echo \"$CLOUDFLARE_API_TOKEN\"",
        "printf '%s\\n' \"$CLOUDFLARE_API_TOKEN\"",
        "tunnel_token=",
        "token=eyJ",
        "StrictHostKeyChecking=no",
    )
    for needle in forbidden:
        assert needle not in text


def test_marina_cloudflare_workflow_uses_instance_agent_and_removes_ssh_gate() -> None:
    text = WORKFLOW.read_text(encoding="utf-8")
    assert "create_instance_agent_command" in text
    assert "shopvivaliz-cloudflared-admin.service" in text
    assert "iptables" in text
    assert "ip6tables" in text
    assert "access-approved" in text


def test_marina_bootstrap_can_use_preconfigured_no_quota_tunnel() -> None:
    text = WORKFLOW.read_text(encoding="utf-8")
    assert "MARINA_CLOUDFLARE_PRECONFIGURED=PASS" in text
    assert "bastion bastion create" in text
    assert "CREATED_NO_QUOTA" in text
    assert "client-cidr-list" in text
    assert "--session-ttl 1800" in text
    assert "shopvivaliz-temporary-bastion-bootstrap" in text
    assert "Failed to restore Marina security-list ingress rules" in text
    assert text.count("network security-list update") >= 2
    assert text.count("--force") >= 3
    assert "ssh-keyscan -T 15 -p \"$local_port\"" in text
    assert "does not match approved OCI host-key material" in text
    assert "HostKeyAlias=127.0.0.1" in text
    assert 'python3 - "$vm_known_hosts" "$forwarded_host_key"' in text
    assert "tunnel_command=\"${tunnel_command//ssh /ssh -F $bastion_ssh_config }\"" in text
    assert "ssh-keygen -q -t rsa -b 3072" in text
    assert "HostKeyAlgorithms +ssh-rsa" in text
    assert "PubkeyAcceptedAlgorithms +ssh-rsa" in text
    assert "PubkeyAcceptedKeyTypes +ssh-rsa" in text
    assert "bastion_ssh_config" in text
    assert "Host *" in text
    assert "IdentityFile $bastion_key" in text
    assert "ConnectTimeout 30" in text
    assert "-F $bastion_ssh_config" in text
    assert "MARINA_BASTION_TUNNEL_RETRY" in text
    assert "MARINA_TOKEN_INSTALL=PASS" in text
    assert "MARINA_CLOUDFLARED_INSTALL=PASS" in text
    assert "find -L /home/ubuntu -maxdepth 10" in text
    assert "-print -quit 2>/dev/null || true" in text
    assert "cloudflared-linux-arm64" in text
    assert "sha256sum --check --strict" in text
    assert "MARINA_CLOUDFLARED_SOURCE=OFFICIAL_RELEASE" in text
    assert "/tmp/shopvivaliz-cloudflared-arm64-*" in text
    assert "cloudflared_source_file" in text
    assert 'cloudflared_source="$(cat "$cloudflared_source_file")"' in text
    assert "/usr/local/bin/cloudflared" in text
    assert "Marina token transfer failed after SSH and sudo probe passed" in text
    assert "MARINA_REMOTE_STEP_FAILED" in text
    assert "VM_SSH=(timeout --signal=TERM --kill-after=10s 180s ssh" in text
    assert "MARINA_PRIVATE_SSH_RETRY=$attempt" in text
    assert "bounded Bastion retries" in text
    assert "sleep 45" in text
    assert "MARINA_REMOTE_STEP=$remote_step" in text
    assert "timeout 90s systemctl enable --now" in text
    assert "MARINA_REMOTE_UNIT=PASS" in text
    assert "sudo -n bash -s" in text
    assert 'sed -E "s/^\\\\[127\\\\.0\\\\.0\\\\.1\\\\]:${local_port} /127.0.0.1 /"' in text
