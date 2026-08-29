#!/usr/bin/env bash
# oci-a1-retry.sh
#
# Rodada de provisionamento da shopvivaliz-free-a1 (Ampere A1.Flex, 2 OCPU/12GB,
# Always Free) na regiao sa-saopaulo-1. A capacidade da shape A1.Flex nessa regiao
# fica frequentemente esgotada ("Out of host capacity") -- esse script tenta de
# novo a cada execucao (instalado via cron a cada 3min, ver comentario de
# instalacao no final do arquivo) ate a instancia ser criada com sucesso.
#
# Idempotente: se a instancia ja existir (qualquer lifecycle-state != TERMINATED),
# o script se autodesativa removendo a propria entrada de crontab e sai sem tentar
# criar de novo.
#
# Autorizado por Fred em 2026-08-26 (sessao Cowork) -- ver docs/AGENTS.md entrada
# do mesmo dia "Provisionamento shopvivaliz-free-a1 via retry persistente".
#
# Requer: oci CLI instalado e configurado em ~/.oci/config (feito pelo workflow
# .github/workflows/oci-a1-bootstrap.yml, que copia as credenciais dos secrets
# OCI_CLI_* do GitHub pra essa VM).

set -uo pipefail

LOG="$HOME/oci-a1-retry.log"
DISPLAY_NAME="shopvivaliz-free-a1"
SHAPE="VM.Standard.A1.Flex"
OCPUS=2
MEMORY_GB=12
SSH_PUBLIC_KEY="ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQCx2OpiMOOyYIengZeDCuEAlPkSTsK0qA7iAhPpFRpEkEJ2+05i3STUbYkhxgyyYvKcwWpH+JJMQWA9Ijwo3MFemcMc4khEpPfquxKIUi1uCEMfTqkl1Uj6f2uR4+rPi93XPkUxFfmvbCxi1+/hwn7n9Y3o/zmjlZPYYuTvuQVe1fpYywnnlCu/zYnIxD1vuxf9lnr4ie8U02g3ddpttj6AYrTZXkpvptRlHZo/DyqPY/Ecxz+8lDQcsCs7/vuRcXxDsRi8rzQ+JR06Rqh/waJ0QU+FBjgyyoqAet8UlYcCoLRZcYTWs7yRqtapZNIeWtZcOgh5crV3Y0MhNual+qHj ssh-key-2026-07-04"
CRON_MARKER="oci-a1-retry.sh"

log() {
    echo "$(date -u +%FT%TZ) $*" >> "$LOG"
}

self_disable() {
    log "Autodesativando: removendo entrada de crontab (marcador: $CRON_MARKER)."
    ( crontab -l 2>/dev/null | grep -v "$CRON_MARKER" ) | crontab -
}

if ! command -v oci >/dev/null 2>&1; then
    log "ERRO FATAL: oci CLI nao encontrado no PATH. Bootstrap incompleto."
    exit 1
fi

if [ ! -f "$HOME/.oci/config" ]; then
    log "ERRO FATAL: ~/.oci/config nao encontrado. Bootstrap incompleto."
    exit 1
fi

COMPARTMENT_ID=$(grep -E '^tenancy=' "$HOME/.oci/config" | head -1 | cut -d= -f2 | tr -d '[:space:]')
if [ -z "$COMPARTMENT_ID" ]; then
    log "ERRO FATAL: nao consegui extrair tenancy (compartment raiz) de ~/.oci/config."
    exit 1
fi

# ── 1. Idempotencia: a instancia ja existe? ─────────────────────────────────
EXISTING_JSON=$(oci compute instance list \
    --compartment-id "$COMPARTMENT_ID" \
    --display-name "$DISPLAY_NAME" \
    --all 2>>"$LOG")

if [ -n "$EXISTING_JSON" ]; then
    EXISTING_STATE=$(echo "$EXISTING_JSON" | grep -o '"lifecycle-state": *"[^"]*"' | head -1 | cut -d'"' -f4)
    if [ -n "$EXISTING_STATE" ] && [ "$EXISTING_STATE" != "TERMINATED" ] && [ "$EXISTING_STATE" != "TERMINATING" ]; then
        EXISTING_ID=$(echo "$EXISTING_JSON" | grep -o '"id": *"ocid1.instance[^"]*"' | head -1 | cut -d'"' -f4)
        log "JA EXISTE: instancia $DISPLAY_NAME encontrada (id=$EXISTING_ID, estado=$EXISTING_STATE). Nada a fazer -- autodesativando retry."
        self_disable
        exit 0
    fi
fi

# ── 2. Descoberta dinamica: availability domains da regiao ─────────────────
AD_LIST=$(oci iam availability-domain list --compartment-id "$COMPARTMENT_ID" \
    --query 'data[*].name' --raw-output 2>>"$LOG" | tr -d '[]" ' | tr ',' '\n' | grep -v '^$')

if [ -z "$AD_LIST" ]; then
    log "ERRO: nao consegui listar availability domains. Tentando de novo no proximo ciclo."
    exit 0
fi

# ── 3. Descoberta dinamica: subnet (reusa a mesma rede da shopvivaliz-micro-2) ──
SUBNET_ID=""
ALL_INSTANCES=$(oci compute instance list --compartment-id "$COMPARTMENT_ID" \
    --lifecycle-state RUNNING \
    --query 'data[*].id' --raw-output 2>>"$LOG")

for INST_ID in $(echo "$ALL_INSTANCES" | tr -d '[]" ' | tr ',' '\n' | grep '^ocid1.instance'); do
    VNIC_ATTACH=$(oci compute vnic-attachment list --compartment-id "$COMPARTMENT_ID" --instance-id "$INST_ID" 2>>"$LOG")
    VNIC_ID=$(echo "$VNIC_ATTACH" | grep -o '"vnic-id": *"ocid1.vnic[^"]*"' | head -1 | cut -d'"' -f4)
    [ -z "$VNIC_ID" ] && continue
    VNIC_DETAIL=$(oci network vnic get --vnic-id "$VNIC_ID" 2>>"$LOG")
    PUB_IP=$(echo "$VNIC_DETAIL" | grep -o '"public-ip": *"[^"]*"' | head -1 | cut -d'"' -f4)
    if [ "$PUB_IP" = "136.248.69.116" ]; then
        SUBNET_ID=$(echo "$VNIC_DETAIL" | grep -o '"subnet-id": *"ocid1.subnet[^"]*"' | head -1 | cut -d'"' -f4)
        break
    fi
done

if [ -z "$SUBNET_ID" ]; then
    log "ERRO: nao consegui descobrir a subnet reutilizando a shopvivaliz-micro-2 (136.248.69.116). Tentando de novo no proximo ciclo."
    exit 0
fi

# ── 4. Descoberta dinamica: imagem Ubuntu ARM mais recente compativel ──────
IMAGE_ID=$(oci compute image list --compartment-id "$COMPARTMENT_ID" \
    --operating-system "Canonical Ubuntu" \
    --shape "$SHAPE" \
    --sort-by TIMECREATED --sort-order DESC \
    --query 'data[0].id' --raw-output 2>>"$LOG")

if [ -z "$IMAGE_ID" ] || [ "$IMAGE_ID" = "null" ]; then
    log "ERRO: nao consegui encontrar uma imagem Ubuntu compativel com $SHAPE. Tentando de novo no proximo ciclo."
    exit 0
fi

# ── 5. Tenta lancar em cada AD ate achar capacidade ─────────────────────────
for AD in $AD_LIST; do
    log "Tentando lancar em AD=$AD shape=$SHAPE ocpus=$OCPUS mem=${MEMORY_GB}GB subnet=$SUBNET_ID image=$IMAGE_ID"

    # Sem --wait-for-state de proposito: se a API aceitar o launch mas a
    # instancia demorar mais que o timeout pra ficar RUNNING, um CLI que
    # espera devolveria erro mesmo com a instancia ja criada -- e o proximo
    # ciclo tentaria lancar OUTRA instancia duplicada antes da checagem de
    # idempotencia (passo 1) rodar de novo. Aceitar a resposta do launch em
    # si como sucesso (ela retorna a instancia em estado PROVISIONING) e
    # deixar o passo 1 do proximo ciclo confirmar o estado real evita isso.
    LAUNCH_OUT=$(oci compute instance launch \
        --compartment-id "$COMPARTMENT_ID" \
        --availability-domain "$AD" \
        --shape "$SHAPE" \
        --shape-config "{\"ocpus\": $OCPUS, \"memoryInGBs\": $MEMORY_GB}" \
        --display-name "$DISPLAY_NAME" \
        --image-id "$IMAGE_ID" \
        --subnet-id "$SUBNET_ID" \
        --assign-public-ip true \
        --ssh-authorized-keys-file <(echo "$SSH_PUBLIC_KEY") 2>&1)

    LAUNCH_STATUS=$?
    NEW_ID=$(echo "$LAUNCH_OUT" | grep -o '"id": *"ocid1.instance[^"]*"' | head -1 | cut -d'"' -f4)

    if [ $LAUNCH_STATUS -eq 0 ] && [ -n "$NEW_ID" ]; then
        log "SUCESSO! Instancia $DISPLAY_NAME aceita pela API em AD=$AD (id=$NEW_ID, estado inicial=PROVISIONING). Autodesativando retry -- proximo ciclo (se rodar) so confirmaria via idempotencia."
        self_disable
        exit 0
    fi

    if echo "$LAUNCH_OUT" | grep -qi "Out of host capacity\|OutOfCapacity\|LimitExceeded.*capacity"; then
        log "SEM CAPACIDADE em AD=$AD (esperado, tentando proxima AD ou proximo ciclo)."
        continue
    else
        log "ERRO INESPERADO ao lancar em AD=$AD: $(echo "$LAUNCH_OUT" | tr '\n' ' ' | head -c 500)"
        continue
    fi
done

log "Nenhuma AD tinha capacidade neste ciclo. Proxima tentativa em ate 3min (cron)."
exit 0

# ── Instalacao (referencia -- feita automaticamente pelo workflow de bootstrap) ──
# crontab -l | { cat; echo "*/3 * * * * /usr/local/bin/oci-a1-retry.sh"; } | crontab -
