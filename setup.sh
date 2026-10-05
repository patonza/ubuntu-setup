#!/bin/bash
# Bootstrap: installa php-cli, scarica il setup in /opt/setup e lo lancia.
# Uso: curl -fsSL https://raw.githubusercontent.com/patonza/ubuntu-setup/main/setup.sh | sudo bash

set -euo pipefail

main() {
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get -o DPkg::Lock::Timeout=120 install -y php-cli

    rm -rf /opt/setup
    mkdir -p /opt/setup
    curl -fsSL https://github.com/patonza/ubuntu-setup/archive/refs/heads/main.tar.gz \
        | tar xz -C /opt/setup --strip-components=1

    exec php /opt/setup/setup.php "$@"
}

main "$@"
