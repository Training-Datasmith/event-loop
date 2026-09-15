#!/usr/bin/env bash
# Cloud agent: PECL event, ev, uv so DriverTest suites are not @requires-skipped.
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
export DEBIAN_FRONTEND=noninteractive

php_ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

ensure_ondrej() {
  if ! apt-cache show "php${php_ver}-dev" &>/dev/null 2>&1; then
    sudo apt-get update -qq
    sudo apt-get install -y --no-install-recommends software-properties-common ca-certificates gnupg
    sudo add-apt-repository -y ppa:ondrej/php
    sudo apt-get update -qq
  fi
}

ensure_build_deps() {
  ensure_ondrej
  sudo apt-get install -y --no-install-recommends \
    build-essential pkg-config \
    "php${php_ver}-dev" php-pear \
    libevent-dev libev-dev libuv-dev libssl-dev
}

enable_extension() {
  local name="$1"
  local ini="/etc/php/${php_ver}/mods-available/${name}.ini"
  if [[ ! -f "${ini}" ]]; then
    echo "extension=${name}.so" | sudo tee "${ini}" >/dev/null
  fi
  sudo phpenmod -v "${php_ver}" "${name}"
}

try_apt_extension() {
  local name="$1"
  local pkg="php${php_ver}-${name}"
  if apt-cache show "${pkg}" &>/dev/null 2>&1; then
    sudo apt-get install -y --no-install-recommends "${pkg}"
    return 0
  fi
  return 1
}

install_extension() {
  local name="$1"
  if php -m 2>/dev/null | grep -q "^${name}$"; then
    return 0
  fi
  if try_apt_extension "${name}"; then
    php -m | grep -q "^${name}$"
    return 0
  fi
  # PECL defaults (event OpenSSL/sockets prompts, etc.)
  yes '' | sudo pecl install -f "${name}"
  enable_extension "${name}"
  php -m | grep -q "^${name}$"
}

ensure_build_deps

install_extension event
install_extension ev
install_extension uv

for ext in event ev uv; do
  php -m | grep -q "^${ext}$"
done

cd "${repo_root}"
if [[ -f composer.json ]]; then
  composer install --no-interaction
fi
