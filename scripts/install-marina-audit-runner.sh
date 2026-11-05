#!/usr/bin/env bash
# Instala um runner GitHub Actions DEDICADO A AUDITORIAS no host Marina, com o
# label `shopvivaliz-audit`. Nao usa o label de deploy (`shopvivaliz-a1-deploy`).
#
# Regras:
# - roda como usuario comum (ubuntu), sem sudo;
# - o token de registro vem SOMENTE da variavel de ambiente RUNNER_REG_TOKEN
#   (gerado pelo dono no GitHub, validade ~1h) e nunca e impresso;
# - o pacote do runner e verificado por SHA-256 fixado neste arquivo;
# - idempotente: se o runner ja estiver configurado, nao reconfigura.
#
# A persistencia como servico (systemd) exige sudo e NAO e feita aqui: o
# script imprime o comando exato para o dono executar uma unica vez.
set -Eeuo pipefail

REPO_URL="https://github.com/Vivaliz-site/site-shopvivaliz"
RUNNER_VERSION="2.338.2"
RUNNER_SHA256="670adb2f268fe21dcbb58b3db0e0aa9d5b3bddfe2c651481336e561ff86de945"
RUNNER_LABEL="shopvivaliz-audit"
RUNNER_NAME="marina-audit-a1"
INSTALL_DIR="${INSTALL_DIR:-$HOME/actions-runner-audit}"

[ "$(id -u)" != "0" ] || { echo "ERROR=do_not_run_as_root" >&2; exit 2; }
[ -n "${RUNNER_REG_TOKEN:-}" ] || { echo "ERROR=registration_token_missing" >&2; exit 3; }

if [ -f "$INSTALL_DIR/.runner" ]; then
  echo "RUNNER_ALREADY_CONFIGURED=true dir=$INSTALL_DIR"
  exit 0
fi

mkdir -p "$INSTALL_DIR"
cd "$INSTALL_DIR"

archive="actions-runner-linux-arm64-${RUNNER_VERSION}.tar.gz"
curl -fsSL -o "$archive" \
  "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/${archive}"
echo "${RUNNER_SHA256}  ${archive}" | sha256sum -c - >/dev/null \
  || { echo "ERROR=runner_checksum_mismatch" >&2; rm -f "$archive"; exit 4; }
tar xzf "$archive"
rm -f "$archive"

./config.sh \
  --unattended \
  --url "$REPO_URL" \
  --token "$RUNNER_REG_TOKEN" \
  --name "$RUNNER_NAME" \
  --labels "$RUNNER_LABEL" \
  --work _work \
  --replace=false >/dev/null \
  || { echo "ERROR=runner_config_failed" >&2; exit 5; }

echo "RUNNER_CONFIGURED=true name=$RUNNER_NAME label=$RUNNER_LABEL dir=$INSTALL_DIR"
echo "PERSISTENCE_STEP_REQUIRES_SUDO (executar uma vez, pelo dono):"
echo "  cd $INSTALL_DIR && sudo ./svc.sh install ubuntu && sudo ./svc.sh start"
