# Caddy with the Cloudflare DNS module.
#
# The stock image cannot answer a DNS-01 challenge, and DNS-01 is the only way to get
# a wildcard certificate. Workspaces are subdomains, so a wildcard is not optional.
#
# Using a different DNS provider means swapping the module here — the list is at
# https://caddyserver.com/docs/modules/ — and changing the block in the Caddyfile.
FROM caddy:2-builder-alpine AS builder

RUN xcaddy build --with github.com/caddy-dns/cloudflare

FROM caddy:2-alpine

COPY --from=builder /usr/bin/caddy /usr/bin/caddy
