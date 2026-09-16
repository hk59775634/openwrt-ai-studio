#!/bin/sh
set -eu

MODE="firmware"
TARGET=""
SUBTARGET=""
PROFILE="generic"
PACKAGE=""
JOBS="8"
REVISION="24.10.4"
COMMIT="unknown"
SOURCE=""
VENDOR="0"

while [ "$#" -gt 0 ]; do
  case "$1" in
    --mode) MODE="$2"; shift 2 ;;
    --target) TARGET="$2"; shift 2 ;;
    --subtarget) SUBTARGET="$2"; shift 2 ;;
    --profile) PROFILE="$2"; shift 2 ;;
    --package) PACKAGE="$2"; shift 2 ;;
    --version) shift 2 ;;
    --arch) shift 2 ;;
    --revision) REVISION="$2"; shift 2 ;;
    --commit) COMMIT="${2:-unknown}"; shift 2 ;;
    --jobs) JOBS="$2"; shift 2 ;;
    --source) SOURCE="$2"; shift 2 ;;
    --vendor) VENDOR="1"; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

if [ -z "$TARGET" ] || [ -z "$SUBTARGET" ]; then
  echo "missing --target/--subtarget" >&2
  exit 2
fi

if [ "$MODE" = "package" ] && [ -z "$PACKAGE" ]; then
  echo "missing --package" >&2
  exit 2
fi

case "$REVISION" in
  mtk-mt7628|mt7628)
    TAG="mtk-mt7628"
    VENDOR="1"
    ;;
  v*) TAG="$REVISION" ;;
  24.10|24.10.*) TAG="v24.10.4" ;;
  snapshot|master|main) TAG="v24.10.4" ;;
  *) TAG="v${REVISION}" ;;
esac

if [ -n "$SOURCE" ]; then
  TAG="$SOURCE"
fi

SRC="/opt/openwrt/src/${TAG}"
DL="/opt/openwrt/dl"
TREE="/opt/openwrt/trees/${TAG}/${TARGET}-${SUBTARGET}"
OUT="/out"

mkdir -p "$DL" "$(dirname "$SRC")" "$(dirname "$TREE")" "$OUT"

if [ ! -f "$SRC/Makefile" ]; then
  if [ "$VENDOR" = "1" ]; then
    echo "Vendor SDK missing at ${SRC} (no Makefile). Copy the SDK tree there; do not clone official OpenWrt." >&2
    exit 1
  fi
  echo "Cloning official OpenWrt ${TAG}"
  git clone --depth 1 --branch "$TAG" https://github.com/openwrt/openwrt.git "$SRC"
fi

if [ "$VENDOR" != "1" ] && [ -f "$SRC/feeds.conf.default" ] && grep -q 'for-14.07' "$SRC/feeds.conf.default" 2>/dev/null; then
  VENDOR="1"
fi

mkdir -p "$TREE"
exec 9>"$TREE/.studio.lock"
flock 9

echo "Syncing source into ${TREE}"
rsync -a \
  --exclude='/.git' \
  --exclude='/bin/' \
  --exclude='/build_dir/' \
  --exclude='/staging_dir/' \
  --exclude='/tmp/' \
  --exclude='/dl' \
  --exclude='/.studio.lock' \
  "$SRC"/ "$TREE"/

cd "$TREE"
rm -f dl
ln -sfn "$DL" dl

if [ "$VENDOR" = "1" ]; then
  echo "Dropping host-built kconfig binaries so they rebuild against this container libc"
  make -C scripts/config clean >/dev/null 2>&1 || true
  rm -f scripts/config/conf scripts/config/mconf scripts/config/*.o scripts/config/lxdialog/*.o
  export FORCE_UNSAFE_CONFIGURE=1
  # Host gcc 8 defaults to GNU++14; gcc 4.8 / binutils 2.22 need GNU++98.
  export CXX="g++ -std=gnu++98"
  export HOSTCXX="g++ -std=gnu++98"
  export CXXFLAGS_FOR_BUILD="-O2 -std=gnu++98"
fi

# An older exclude of 'bin/' also dropped tools/missing-macros/src/bin. Force
# host-tool re-prepare if the working copy is still incomplete.
if [ -d tools/missing-macros/src/bin ] && [ -d build_dir/host/missing-macros ] && [ ! -d build_dir/host/missing-macros/bin ]; then
  echo "Repairing incomplete tools/missing-macros host copy"
  rm -rf build_dir/host/missing-macros
  rm -f staging_dir/host/stamp/.missing-macros_*
fi

if [ "$VENDOR" = "1" ]; then
  echo "Vendor SDK ${TAG}: skipping feeds update"
elif [ ! -d feeds/luci ]; then
  echo "Updating feeds"
  ./scripts/feeds update -a
  ./scripts/feeds install -a
fi

workspace_config_lines=0
if [ -f /src/.config ]; then
  workspace_config_lines="$(grep -c '^CONFIG_' /src/.config || true)"
fi

if [ "$VENDOR" = "1" ]; then
  if [ -f /src/.config ] && [ "$workspace_config_lines" -gt 50 ]; then
    cp /src/.config .config
    echo "Using workspace .config (${workspace_config_lines} options)"
  elif [ ! -f .config ]; then
    echo "Vendor SDK has no .config" >&2
    exit 1
  else
    echo "Keeping vendor SDK .config"
  fi
elif [ -f /src/.config ]; then
  cp /src/.config .config
elif [ ! -f .config ]; then
  {
    echo "CONFIG_TARGET_${TARGET}=y"
    echo "CONFIG_TARGET_${TARGET}_${SUBTARGET}=y"
    echo "CONFIG_TARGET_DEVICE_${TARGET}_${SUBTARGET}_DEVICE_${PROFILE}=y"
    echo "CONFIG_TARGET_MULTI_PROFILE=n"
    echo "CONFIG_PACKAGE_luci=y"
  } > .config
fi

if [ "$VENDOR" != "1" ]; then
  mkdir -p /ccache
  export CCACHE_DIR=/ccache
  if ! grep -q '^CONFIG_CCACHE=' .config 2>/dev/null; then
    echo 'CONFIG_CCACHE=y' >> .config
  fi
fi

# Feed-style LuCI app: Makefile + files/ at the repo root (no package/).
root_is_package=0
if [ -f /src/Makefile ] && grep -q '^PKG_NAME:=' /src/Makefile; then
  root_pkg="$(awk -F:= '/^PKG_NAME:=/{gsub(/[[:space:]\r]/,"",$2); print $2; exit}' /src/Makefile)"
  case "$root_pkg" in
    ''|*/*|*..*)
      echo "Ignoring invalid PKG_NAME in /src/Makefile" >&2
      ;;
    *)
      dest="package/studio/${root_pkg}"
      mkdir -p "$dest"
      rsync -a \
        --exclude='/.git/' \
        --exclude='/.config' \
        --exclude='/bin/' \
        --exclude='/build/' \
        --exclude='/release/' \
        --exclude='/.github/' \
        /src/ "$dest"/
      echo "Injected root package ${root_pkg} into ${dest}"
      root_is_package=1
      ;;
  esac
fi

if [ -d /src/package ]; then
  mkdir -p package/studio
  cp -a /src/package/. package/studio/
fi
if [ -d /src/files ] && [ "$root_is_package" != "1" ]; then
  mkdir -p files
  cp -a /src/files/. files/
elif [ "$root_is_package" = "1" ] && [ -d /src/files ] && [ -d files ]; then
  # Vendor SDK files/ is copied onto the rootfs AFTER packages. Drop overlay
  # copies of this package so luci-app install (PKG_VERSION / PKG_IMAGE_VERSION)
  # is not overwritten by a stale SDK tree (e.g. VPS000_IMAGE=1.3.3).
  n=0
  (cd /src/files && find . -type f) | while IFS= read -r rel; do
    [ -n "$rel" ] || continue
    if [ -e "files/$rel" ] || [ -L "files/$rel" ]; then
      rm -f "files/$rel"
      n=1
    fi
  done
  echo "Cleared vendor files/ overlay paths owned by ${root_pkg}"
fi

enable_studio_packages() {
  find package/studio -name Makefile 2>/dev/null | while IFS= read -r mk; do
    pkg="$(awk -F:= '/^PKG_NAME:=/{print $2; exit}' "$mk" | tr -d ' \r')"
    if [ -n "$pkg" ] && ! grep -q "^CONFIG_PACKAGE_${pkg}=" .config 2>/dev/null; then
      echo "CONFIG_PACKAGE_${pkg}=y" >> .config
      echo "Enabled CONFIG_PACKAGE_${pkg}=y"
    fi
  done
}

if [ -d package/studio ]; then
  enable_studio_packages
fi

echo "OpenWrt Build System ${TAG} ${TARGET}/${SUBTARGET} PROFILE=${PROFILE} mode=${MODE} vendor=${VENDOR} commit=${COMMIT}"

announce_packaging_success() {
  kind="$1"
  echo ""
  echo "========================================"
  if [ "$kind" = "firmware" ]; then
    echo "固件打包成功"
    echo "FIRMWARE PACKAGING SUCCEEDED"
  else
    echo "软件包打包成功"
    echo "IPK PACKAGING SUCCEEDED"
  fi
  echo "========================================"
  find "$OUT" -type f 2>/dev/null | while IFS= read -r f; do
    bytes=$(wc -c < "$f" | tr -d ' ')
    echo "  $(basename "$f")  ${bytes} bytes"
  done
  echo "========================================"
}

if [ "$MODE" = "package" ]; then
  PKG_MAKEFILE="$(grep -rl "^PKG_NAME:=${PACKAGE}$" package/studio --include=Makefile 2>/dev/null | head -n 1 || true)"
  if [ ! -f "${PKG_MAKEFILE:-}" ]; then
    PKG_MAKEFILE="$(find package/studio package -path "*/${PACKAGE}/Makefile" 2>/dev/null | head -n 1 || true)"
  fi
  if [ ! -f "${PKG_MAKEFILE:-}" ]; then
    echo "package Makefile for ${PACKAGE} not found" >&2
    find package/studio -type f 2>/dev/null | head -40 >&2 || true
    exit 1
  fi
  if ! grep -q "define Package/${PACKAGE}/install" "$PKG_MAKEFILE"; then
    printf '\ndefine Build/Compile\nendef\n\ndefine Package/%s/install\n\t$(INSTALL_DIR) $(1)/usr/lib/lua/luci\n\t[ ! -d ./luasrc ] || $(CP) ./luasrc/. $(1)/usr/lib/lua/luci/\nendef\n' "$PACKAGE" >> "$PKG_MAKEFILE"
    echo "Patched ${PKG_MAKEFILE} with a LuCI install rule"
  fi
  PKG_REL="${PKG_MAKEFILE#package/}"
  PKG_REL="${PKG_REL%/Makefile}"
  echo "CONFIG_PACKAGE_${PACKAGE}=y" >> .config
  echo "make defconfig && make package/${PKG_REL}/compile -j${JOBS} DL_DIR=${DL} V=s"
  make defconfig
  make "package/${PKG_REL}/compile" -j"${JOBS}" DL_DIR="$DL" V=s
  make package/index || true
  find bin -type f -name "${PACKAGE}*.ipk" -exec cp -v {} "$OUT"/ \; 2>/dev/null || true
  count="$(find "$OUT" -name '*.ipk' 2>/dev/null | wc -l)"
  if [ "$count" -eq 0 ]; then
    echo "Build System produced no IPK" >&2
    find bin -type f -name '*.ipk' 2>/dev/null | head -40 >&2 || true
    exit 1
  fi
  echo "Copied ${count} IPK(s) to /out"
  announce_packaging_success package
  exit 0
fi

echo "make defconfig && make -j${JOBS} DL_DIR=${DL} V=s"
rm -rf bin
if [ "$VENDOR" = "1" ]; then
  mkdir -p firmware
fi
make defconfig
make -j"${JOBS}" DL_DIR="$DL" V=s

copied=0
find bin -type f \( \
    -name '*.img.gz' -o \
    -name '*.img' -o \
    -name '*.bin' -o \
    -name '*.itb' -o \
    -name '*.vmdk' -o \
    -name '*.vdi' -o \
    -name '*.vhdx' -o \
    -name '*.iso' -o \
    -name '*rootfs.tar.gz' \
  \) ! -name '*kernel.bin' ! -path 'bin/packages/*' -exec cp -v {} "$OUT"/ \; 2>/dev/null || true
copied="$(find "$OUT" -type f 2>/dev/null | wc -l)"

if [ "$copied" -eq 0 ]; then
  echo "Build System produced no firmware artifacts" >&2
  find bin -type f 2>/dev/null | head -50 >&2 || true
  exit 1
fi

echo "Copied ${copied} artifact(s) to /out"
announce_packaging_success firmware
