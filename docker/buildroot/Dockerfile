FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential clang flex bison g++ gawk gcc-multilib g++-multilib \
        gettext git libncurses5-dev libssl-dev python3 python3-dev python3-setuptools \
        rsync swig unzip zlib1g-dev file wget ca-certificates curl libelf-dev util-linux \
    && rm -rf /var/lib/apt/lists/* \
    && useradd -u 1000 -m builder \
    && mkdir -p /src /out /work /opt/openwrt \
    && chown 1000:1000 /src /out /work /opt/openwrt

USER 1000:1000
WORKDIR /work
