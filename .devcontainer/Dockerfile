FROM mcr.microsoft.com/devcontainers/base:ubuntu-24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    ca-certificates \
    unzip \
    zip \
    php-cli \
    php-xml \
    php-curl \
    php-mbstring \
    php-sqlite3 \
    php-pdo \
    php-zip \
    nodejs \
    npm \
    python3 \
    python3-pip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /workspaces/site-shopvivaliz
