#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
BACKEND_PID=""
FRONTEND_PID=""

cleanup() {
  if [ -n "$FRONTEND_PID" ]; then kill "$FRONTEND_PID" 2>/dev/null || :; fi
  if [ -n "$BACKEND_PID" ]; then kill "$BACKEND_PID" 2>/dev/null || :; fi
  wait "$FRONTEND_PID" 2>/dev/null || :
  wait "$BACKEND_PID" 2>/dev/null || :
  rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT

free_port() {
  php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);$n=stream_socket_get_name($s,false);echo substr(strrchr($n,":"),1);fclose($s);'
}

BACKEND_PORT="$(free_port)"
FRONTEND_PORT="$(free_port)"
mkdir -p "$TMP_DIR/backend/api" "$TMP_DIR/runtime"
printf '%s\n' '<?php header("Content-Type: application/json"); echo json_encode(["ok" => true, "answer" => "backend-reached"]);' > "$TMP_DIR/backend/api/liz-general.php"

php -S "127.0.0.1:$BACKEND_PORT" -t "$TMP_DIR/backend" >"$TMP_DIR/backend.log" 2>&1 &
BACKEND_PID=$!
SHOPVIVALIZ_INTERNAL_ORIGIN="http://127.0.0.1:$BACKEND_PORT" \
SHOPVIVALIZ_RUNTIME_DIR="$TMP_DIR/runtime" \
php -S "127.0.0.1:$FRONTEND_PORT" -t "$ROOT" >"$TMP_DIR/frontend.log" 2>&1 &
FRONTEND_PID=$!

for _ in $(seq 1 50); do
  if curl --fail --silent \
    "http://127.0.0.1:$FRONTEND_PORT/api/liz-router.php?health=1" >/dev/null 2>&1; then
    break
  fi
  sleep 0.1
done

BODY="$TMP_DIR/response.json"
CODE="$(curl --silent --show-error --output "$BODY" --write-out '%{http_code}' \
  --header 'Content-Type: application/json' \
  --data '{"message":"me passe uma receita de bolo"}' \
  "http://127.0.0.1:$FRONTEND_PORT/api/liz-router.php")"

php -r '
$data=json_decode(file_get_contents($argv[1]),true);
if ($argv[2] !== "200" || !is_array($data) || ($data["answer"] ?? "") !== "backend-reached") {
    fwrite(STDERR, "FAIL: Liz router did not reach configured loopback backend. HTTP ".$argv[2]." body=".file_get_contents($argv[1]).PHP_EOL);
    exit(1);
}
' "$BODY" "$CODE"

echo "PASS: Liz router reaches the configured private loopback backend."
