#!/bin/bash
# Bootstrap: installs php-cli, downloads the setup to /opt/setup and runs it.
# Usage: curl -fsSL "https://raw.githubusercontent.com/patonza/ubuntu-setup/main/setup.sh?$(date +%s)" | sudo bash

set -euo pipefail

# info leaves the terminal dark gray, so the output of the following commands is gray.
info() { printf '\033[38;2;232;232;232m%s\033[38;2;88;94;104m\n' "$*"; }
ok() { printf '\t\033[38;2;92;184;92m%s\033[38;2;168;176;186m\n' "$*"; }
already() { printf '\t\033[38;2;56;170;204m%s\033[38;2;168;176;186m\n' "$*"; }

main() {
    export DEBIAN_FRONTEND=noninteractive
    info "Installing php-cli"
    # Skipped on reruns: setup.php upgrades the system anyway.
    if php -r 'exit(0);' >/dev/null 2>&1; then
        already "Already installed"
    else
        apt-get update
        apt-get -o DPkg::Lock::Timeout=120 install -y php-cli
        ok "php-cli installed"
    fi

    info "Downloading setup to /opt/setup"
    rm -rf /opt/setup
    mkdir -p /opt/setup
    curl -fsSL https://github.com/patonza/ubuntu-setup/archive/refs/heads/main.tar.gz \
        | tar xz -C /opt/setup --strip-components=1
    # Only root needs the setup. tar run by root keeps the archive permissions (664/775).
    chmod -R u=rwX,g=rX,o= /opt/setup
    ok "Setup downloaded"

    exec php /opt/setup/setup.php "$@"
}

main "$@"
