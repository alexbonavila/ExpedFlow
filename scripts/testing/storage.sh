#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
umask 077

work=$(mktemp -d)
mount_point="$work/mounted"
credentials="$work/credentials"
mkdir -p "$mount_point" "$credentials"
server=
stage=setup

cleanup() {
  if mountpoint -q "$mount_point"; then
    sudo -n umount "$mount_point" || printf '%s\n' 'CIFS cleanup could not unmount the share.' >&2
  fi
  if [[ -n "$server" ]]; then
    docker rm -f "$server" >/dev/null 2>&1 || true
  fi
  if ! mountpoint -q "$mount_point"; then
    rm -rf "$work"
  fi
}
report_failure() {
  printf '::error title=SMB smoke::Stage %s failed with exit %s.\n' "$stage" "$1" >&2
}
trap cleanup EXIT
trap 'report_failure "$?"' ERR

copy_fixture() {
  if ! findmnt --noheadings --mountpoint "$mount_point" --types cifs >/dev/null; then
    printf '%s\n' 'CIFS mount is unavailable.' >&2
    return 1
  fi
  cp -R "$mount_point/template" "$mount_point/copy"
}

assert_unmounted() {
  if copy_fixture || [[ -n "$(find "$mount_point" -mindepth 1 -print -quit)" ]]; then
    printf '%s\n' 'An unavailable SMB share allowed a local write.' >&2
    return 1
  fi
}

if [[ "${1:-}" == --check-unmounted ]]; then
  assert_unmounted
  printf '%s\n' 'Missing mount rejected and underlying directory remained empty.'
  exit 0
fi

if ! command -v sudo >/dev/null || ! command -v mount.cifs >/dev/null; then
  printf '%s\n' 'The full CIFS check needs sudo and cifs-utils on an isolated Linux runner.' >&2
  exit 1
fi
sudo -n true

image=expedflow-smb-testing
docker build --secret "id=apt_ca,src=${SSL_CERT_FILE:-/etc/ssl/certs/ca-certificates.crt}" --tag "$image" scripts/testing/smb
for account in writer reader; do
  printf 'username=%s\npassword=%s\n' "$account" "$(openssl rand -hex 24)" > "$credentials/$account"
done

stage='server-readiness'
server=$(docker run -d --rm --network host \
  --mount "type=bind,source=$credentials,target=/run/expedflow-credentials,readonly" "$image")
for ((attempt=0; attempt<25; attempt++)); do
  if docker exec "$server" smbclient //127.0.0.1/fixtures -p 1445 -A /run/expedflow-credentials/writer -c ls >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
docker exec "$server" smbclient //127.0.0.1/fixtures -p 1445 -A /run/expedflow-credentials/writer -c ls >/dev/null

mount_share() {
  sudo -n timeout 12 mount -t cifs //127.0.0.1/fixtures "$mount_point" \
    -o "port=1445,credentials=$credentials/$1,vers=3.1.1,uid=$(id -u),gid=$(id -g),nosuid,nodev,noexec"
}

stage='writer-mount'
mount_share writer
stage='writer-copy'
copy_fixture
cmp "$mount_point/template/nested/fixture.txt" "$mount_point/copy/nested/fixture.txt"
stage='writer-write'
printf '%s\n' 'Client write' > "$mount_point/copy/write.txt"
test "$(docker exec "$server" cat /srv/expedflow-fixtures/copy/write.txt)" = 'Client write'
sudo -n umount "$mount_point"

stage='reader-mount'
mount_share reader
stage='reader-permissions'
test "$(cat "$mount_point/template/nested/fixture.txt")" = 'Synthetic template file'
if touch "$mount_point/reader-write.txt" 2>/dev/null; then
  printf '%s\n' '::error title=SMB permissions::The read-only account unexpectedly wrote a file.' >&2
  exit 1
fi
sudo -n umount "$mount_point"
docker stop "$server" >/dev/null
server=

stage='missing-share'
if mount_share writer 2>/dev/null; then
  printf '%s\n' '::error title=SMB mount failure::Mount unexpectedly succeeded after the server stopped.' >&2
  exit 1
fi
assert_unmounted

printf '%s\n' 'CIFS read, copy, write, read-only account and missing-mount checks passed.'
