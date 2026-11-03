#!/bin/bash
set -euo pipefail
set -a
source ~/.config/restic/restic.env
set +a
exec /usr/bin/restic "$@"
