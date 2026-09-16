# OpenWrt AI Studio

Web IDE for OpenWrt LuCI apps and firmware. Clone this repository on any Linux host with Docker and run it — **no hardcoded machine paths**.

中文：任意目录克隆后执行 `./scripts/bootstrap.sh` 再 `make up`。数据目录、仓库路径、Docker gid 都写进 `infrastructure/compose/.env`，不要改代码里的绝对路径。

This project was built in [Cursor](https://cursor.com) with Agent (**vibe coding**): prompts in the IDE, not a handwritten codebase. Local Agent transcripts from 8–16 Sep 2026 add up to about **130 million tokens** (81 user prompts, 1,159 model steps, counting conversation context replayed on each step). That is an estimate from this machine’s session logs, not the Cursor billing dashboard.

中文：本项目通过 Cursor 的 vibe coding（Agent）模式开发。2026 年 9 月 8 日至 16 日本机 Agent 会话合计约 **1.3 亿 token**（81 次提问、1159 次模型步骤，按每一步回放对话上下文估算）。

## Requirements

- Linux (uid 1000 in the containers; data dirs are owned by 1000:1000)
- Docker Engine + Compose v2
- Disk for OpenWrt trees if you will **Build IPK / firmware** (tens of GB)

## Quick start

```bash
git clone https://github.com/hk59775634/openwrt-ai-studio.git
cd openwrt-ai-studio
./scripts/bootstrap.sh
make up
```

Open `http://127.0.0.1:8080` and register a user.

`bootstrap.sh` writes **absolute** paths for this checkout:

| Variable | Meaning |
| --- | --- |
| `STUDIO_HOST_ROOT` | This git working tree (needed because builds talk to the **host** Docker daemon) |
| `DATA_ROOT` | Postgres, Redis, MinIO, workspaces, OpenWrt src/trees, download + ccache |
| `DOCKER_GID` | Group id of `/var/run/docker.sock` |

Default `DATA_ROOT` is `<repo>/data`. Point it at a large disk if you want:

```bash
# in infrastructure/compose/.env
DATA_ROOT=/mnt/bigdisk/studio
```

then re-run `./scripts/bootstrap.sh` (it will not overwrite a non-empty `DATA_ROOT`).

## Firmware / IPK builds

Web IDE comes up without an OpenWrt tree. Real compiles need images and sources:

```bash
make images
make prepare-openwrt
```

Vendor SDKs (for example MediaTek MT7628) are **not** cloned. Copy the SDK so it contains a `Makefile`:

```text
$DATA_ROOT/openwrt/src/<source-name>/Makefile
```

Tune CPU/RAM in `.env` (`BUILD_FIRMWARE_CPUS`, `BUILD_FIRMWARE_MEMORY`). Conservative defaults are `4` / `8g`.

## Layout

```text
apps/api          Laravel API
apps/web          Vite UI
docker/           API, agent, OpenWrt buildroot images
infrastructure/compose
scripts/bootstrap.sh
```

`.env` files are gitignored. Copy only `.env.example`.

## Make targets

| Target | Action |
| --- | --- |
| `make up` | bootstrap + compose up |
| `make down` | stop |
| `make images` | build agent + official/legacy buildroot images |
| `make prepare-openwrt` | clone official OpenWrt feeds into `$DATA_ROOT` |
| `make test` | PHPUnit inside the API container |

## Notes

- Bind `HOST_BIND=0.0.0.0` only behind your own reverse proxy; set `APP_URL` to the public URL.
- Do not commit `infrastructure/compose/.env`.
- Container contract is user `1000:1000`. The login user on the host can be anyone.
