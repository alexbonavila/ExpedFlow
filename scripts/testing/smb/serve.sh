#!/usr/bin/env bash
set -euo pipefail
umask 077

share=/srv/expedflow-fixtures
mkdir -p "$share/template/nested" "$share/PLANTILLAS/05/nested/empty" "$share/EXPEDIENTES"
chmod 755 "$share" "$share/template" "$share/template/nested"
for account in writer reader; do
  useradd --system --no-create-home --shell /usr/sbin/nologin "$account"
  password=$(sed -n 's/^password=//p' "/run/expedflow-credentials/$account")
  test -n "$password"
  printf '%s\n%s\n' "$password" "$password" | smbpasswd -s -a "$account" >/dev/null
done
chown writer "$share" "$share/EXPEDIENTES"
chmod 755 "$share/EXPEDIENTES"
chmod -R a+rX "$share/PLANTILLAS"
printf '%s\n' 'Synthetic template file' > "$share/template/nested/fixture.txt"
chmod 644 "$share/template/nested/fixture.txt"
cp "$share/template/nested/fixture.txt" "$share/PLANTILLAS/05/nested/fixture.txt"
chmod 644 "$share/PLANTILLAS/05/nested/fixture.txt"

config=$(mktemp)
cat > "$config" <<CONFIG
[global]
   interfaces = lo
   bind interfaces only = yes
   smb ports = 1445
   server min protocol = SMB3
   map to guest = Never
   log level = 0
[fixtures]
   path = $share
   valid users = writer reader
   read only = yes
   write list = writer
   force create mode = 0644
   force directory mode = 0755
   follow symlinks = no
   wide links = no
CONFIG

exec smbd --foreground --no-process-group --configfile "$config"
