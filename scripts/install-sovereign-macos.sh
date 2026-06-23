#!/usr/bin/env bash

set -euo pipefail

cat <<'EOF' >&2
install-sovereign-macos.sh is deprecated.

Sovereign Coolify installs target Linux VPS only.
For Mac local development use marketplace/scripts/dev-tunnel.sh instead.

To force a legacy mac-dev install (not recommended):
  SOVEREIGN_ALLOW_MAC_INSTALL=true SOVEREIGN_HOST_PROFILE=mac-dev bash scripts/install-sovereign.sh
EOF

exit 1
