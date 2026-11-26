#!/usr/bin/env bash
set -euo pipefail
umask 077

work=$(mktemp -d)
share="$work/share"
mount_point="$work/mounted"
mkdir -p "$share/template/nested" "$mount_point"
chmod 755 "$work" "$share/template" "$share/template/nested"
server=
cleanup() {
  if mountpoint -q "$mount_point"; then
    umount "$mount_point" || true
  fi
  if [[ -n "$server" ]]; then
    kill "$server" 2>/dev/null || true
    wait "$server" 2>/dev/null || true
  fi
  rm -rf "$work"
}
trap cleanup EXIT

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

for account in writer reader; do
  useradd --system --no-create-home --shell /usr/sbin/nologin "$account"
  password=$(head -c 24 /dev/urandom | od -An -v -tx1 | tr -d ' \n')
  printf '%s\n%s\n' "$password" "$password" | smbpasswd -s -a "$account" >/dev/null
  printf 'username=%s\npassword=%s\n' "$account" "$password" > "$work/$account.credentials"
done
chown writer "$share"
chmod 755 "$share"
printf '%s\n' 'Synthetic template file' > "$share/template/nested/fixture.txt"
chmod 644 "$share/template/nested/fixture.txt"

cat > "$work/smb.conf" <<CONFIG
[global]
   interfaces = lo
   bind interfaces only = yes
   smb ports = 445
   server min protocol = SMB3
   map to guest = Never
   log level = 0
[fixtures]
   path = $share
   valid users = writer reader
   read only = yes
   write list = writer
CONFIG

setsid smbd --foreground --no-process-group --configfile "$work/smb.conf" &
server=$!
for ((attempt=0; attempt<25; attempt++)); do
  if smbclient //127.0.0.1/fixtures -A "$work/writer.credentials" -c ls >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
smbclient //127.0.0.1/fixtures -A "$work/writer.credentials" -c ls >/dev/null

mount_share() {
  timeout 10 mount -t cifs //127.0.0.1/fixtures "$mount_point" \
    -o "credentials=$work/$1.credentials,vers=3.1.1,nosuid,nodev,noexec"
}


mount_share writer
copy_fixture
cmp "$mount_point/template/nested/fixture.txt" "$mount_point/copy/nested/fixture.txt"
printf '%s\n' 'Client write' > "$mount_point/copy/write.txt"
cmp "$mount_point/copy/write.txt" "$share/copy/write.txt"
umount "$mount_point"

mount_share reader
test -r "$mount_point/template/nested/fixture.txt"
if touch "$mount_point/reader-write.txt" 2>/dev/null; then
  printf '%s\n' 'The read-only SMB account unexpectedly wrote a file.' >&2
  exit 1
fi
umount "$mount_point"
kill "$server"
wait "$server" || true
server=

if mount_share writer 2>/dev/null; then
  printf '%s\n' 'Mount unexpectedly succeeded after the SMB server stopped.' >&2
  exit 1
fi
assert_unmounted

printf '%s\n' 'CIFS read, copy, write, read-only account and missing-mount checks passed.'
