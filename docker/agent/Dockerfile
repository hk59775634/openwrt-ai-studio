FROM python:3.12-alpine

RUN apk add --no-cache git make bash jq ripgrep nodejs npm \
    && adduser -D -u 1000 -g 1000 agent

WORKDIR /workspace
USER agent

# OpenWrt AI Studio agent sandbox. No Docker socket. Network is disabled at runtime.
CMD ["sleep", "infinity"]
