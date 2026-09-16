#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_DIR="${ROOT}/infrastructure/compose"
ENV_FILE="${COMPOSE_DIR}/.env"
EXAMPLE="${COMPOSE_DIR}/.env.example"

if [[ ! -f "$EXAMPLE" ]]; then
  echo "missing ${EXAMPLE}" >&2
  exit 1
fi

if [[ ! -f "$ENV_FILE" ]]; then
  cp "$EXAMPLE" "$ENV_FILE"
fi

upsert() {
  local key="$1" value="$2"
  python3 - "$ENV_FILE" "$key" "$value" <<'PY'
import pathlib, sys
path = pathlib.Path(sys.argv[1])
key, value = sys.argv[2], sys.argv[3]
lines = path.read_text().splitlines() if path.exists() else []
out, found = [], False
for line in lines:
    if line.startswith(key + "="):
        out.append(f"{key}={value}")
        found = True
    else:
        out.append(line)
if not found:
    if out and out[-1] != "":
        out.append("")
    out.append(f"{key}={value}")
path.write_text("\n".join(out) + "\n")
PY
}

current() {
  grep -E "^${1}=" "$ENV_FILE" | tail -n1 | cut -d= -f2- || true
}

fill_if_empty() {
  local key="$1" value="$2"
  local now
  now="$(current "$key")"
  if [[ -z "$now" || "$now" == "change-me" ]]; then
    upsert "$key" "$value"
  fi
}

DOCKER_GID="$(stat -c '%g' /var/run/docker.sock 2>/dev/null || true)"
if [[ -z "$DOCKER_GID" ]]; then
  echo "warning: cannot read gid of /var/run/docker.sock; set DOCKER_GID in ${ENV_FILE}" >&2
  DOCKER_GID=0
fi

upsert STUDIO_HOST_ROOT "$ROOT"
upsert DOCKER_GID "$DOCKER_GID"

if [[ -z "$(current DATA_ROOT)" ]]; then
  upsert DATA_ROOT "${DATA_ROOT:-$ROOT/data}"
fi

rand() { openssl rand -hex 16; }
app_key() { printf 'base64:%s' "$(openssl rand -base64 32 | tr -d '\n')"; }

fill_if_empty POSTGRES_PASSWORD "$(rand)"
fill_if_empty REDIS_PASSWORD "$(rand)"
fill_if_empty MINIO_ROOT_PASSWORD "$(rand)"
fill_if_empty APP_KEY "$(app_key)"
fill_if_empty HTTP_PORT "8080"
fill_if_empty HOST_BIND "127.0.0.1"
fill_if_empty APP_URL "http://127.0.0.1:$(current HTTP_PORT)"

DATA_ROOT_VAL="$(current DATA_ROOT)"
mkdir -p \
  "${DATA_ROOT_VAL}/postgres" \
  "${DATA_ROOT_VAL}/redis" \
  "${DATA_ROOT_VAL}/minio" \
  "${DATA_ROOT_VAL}/workspaces" \
  "${DATA_ROOT_VAL}/openwrt/src" \
  "${DATA_ROOT_VAL}/openwrt/trees" \
  "${DATA_ROOT_VAL}/downloads-cache" \
  "${DATA_ROOT_VAL}/ccache"

if chown -R 1000:1000 \
    "${DATA_ROOT_VAL}/workspaces" \
    "${DATA_ROOT_VAL}/openwrt" \
    "${DATA_ROOT_VAL}/downloads-cache" \
    "${DATA_ROOT_VAL}/ccache" 2>/dev/null; then
  true
else
  echo "warning: could not chown workspace/OpenWrt dirs to 1000:1000" >&2
fi

echo "Studio root : $ROOT"
echo "Data root   : $DATA_ROOT_VAL"
echo "Docker gid  : $DOCKER_GID"
echo
echo "Start the stack:"
echo "  make up"
echo
echo "Build firmware/IPK images (once per machine):"
echo "  make images"
echo "  make prepare-openwrt"
echo "Vendor SDKs go in: ${DATA_ROOT_VAL}/openwrt/src/<name>/"
