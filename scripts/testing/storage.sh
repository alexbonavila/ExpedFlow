#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

image=expedflow-smb-testing
docker build --secret "id=apt_ca,src=${SSL_CERT_FILE:-/etc/ssl/certs/ca-certificates.crt}" --tag "$image" scripts/testing/smb
docker run --rm --network none --cap-add SYS_ADMIN --security-opt apparmor=unconfined "$image"
