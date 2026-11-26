FROM debian:13-slim@sha256:8aa5a7bf5a1955bddbe7d74fcdd0b91ab30e74362a8b73af672b0481b35b44a0
RUN --mount=type=secret,id=apt_ca,required,mode=0444 sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get -o Acquire::https::CaInfo=/run/secrets/apt_ca -o APT::Update::Error-Mode=any update \
    && apt-get -o Acquire::https::CaInfo=/run/secrets/apt_ca install -y --no-install-recommends ca-certificates cifs-utils samba smbclient \
    && rm -rf /var/lib/apt/lists/*
COPY smoke.sh /usr/local/bin/expedflow-smb-smoke
ENTRYPOINT ["bash", "/usr/local/bin/expedflow-smb-smoke"]
