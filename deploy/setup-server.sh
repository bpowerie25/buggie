#!/usr/bin/env bash
set -euo pipefail

# ──────────────────────────────────────────────────────────
# Buggie — Server Setup
# Target: Ubuntu 24.04 or 26.04 LTS (Hetzner CX22 or better: 2 vCPU / 4 GB)
# Run as root, once:  bash setup-server.sh
# ──────────────────────────────────────────────────────────

DEPLOY_USER="deploy"
SWAP_SIZE="2G"
APP_DIR="/srv/buggie"
# buggie.eu deploys the private platform repository, which carries the public one as
# an upstream remote. A self-hoster puts their own clone URL here, or just clones the
# public repository by hand.
REPO="${BUGGIE_REPO:-git@github.com:bpowerie25/buggie-platform.git}"

echo "🚀 Setting up the Buggie production server..."

# ── 1. System ──
apt update && apt upgrade -y
apt install -y ca-certificates curl git ufw fail2ban unattended-upgrades

# ── 2. Deploy user ──
# NOTE: this copies root's authorized_keys to the deploy user. If you reached this
# box with a password rather than a key, that file is empty, and step 5 disabling
# password authentication would lock you out. The script refuses rather than risk it.
# Everything after this runs unprivileged. Docker needs group membership rather
# than sudo, so the deploy script never asks for a password.
if [ ! -s /root/.ssh/authorized_keys ]; then
    echo "❌ /root/.ssh/authorized_keys is empty or missing."
    echo "   Password authentication is disabled below, so this would lock you out."
    echo "   From your own machine, run:"
    echo "     ssh-copy-id -i ~/.ssh/id_ed25519_hetzner.pub root@\$(hostname -I | awk '{print \$1}')"
    echo "   then run this script again."
    exit 1
fi

if ! id "$DEPLOY_USER" &>/dev/null; then
    adduser --disabled-password --gecos "" "$DEPLOY_USER"
    usermod -aG sudo "$DEPLOY_USER"
    mkdir -p "/home/$DEPLOY_USER/.ssh"
    cp /root/.ssh/authorized_keys "/home/$DEPLOY_USER/.ssh/"
    chown -R "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
    chmod 700 "/home/$DEPLOY_USER/.ssh"
    chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"
    echo "$DEPLOY_USER ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/$DEPLOY_USER"
fi

# ── 3. Swap ──
# 4 GB is enough until a composer install and a vite build overlap, at which point
# the OOM killer takes PHP-FPM and the deploy fails in a confusing way.
if [ ! -f /swapfile ]; then
    fallocate -l "$SWAP_SIZE" /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    sysctl vm.swappiness=10
    echo 'vm.swappiness=10' >> /etc/sysctl.conf
fi

# ── 4. Docker ──
if ! command -v docker &>/dev/null; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list
    apt update
    apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

usermod -aG docker "$DEPLOY_USER"

# ── 5. SSH ──
#
# Key-only, and NOT moved off port 22. Moving it is mild security theatre — it stops
# nothing that fail2ban and key-only authentication do not already stop — and on this
# distribution it is genuinely dangerous to automate. `ListenStream=2222` on a
# socket-activated sshd binds IPv6 ONLY, so IPv4 clients have nowhere to go while the
# override has already cleared port 22. That locked the author out of a fresh box and
# needed the rescue console to undo. The shipped unit lists 0.0.0.0 and [::]
# separately for exactly this reason.
#
# Editing sshd_config directly does not work either, for anything cloud-init already
# set: drop-ins are Included at the TOP of sshd_config and OpenSSH takes the FIRST
# value it sees, so /etc/ssh/sshd_config.d/50-cloud-init.conf beats the main file.
# A drop-in sorting before it is the only way to win, hence 10-.
cat > /etc/ssh/sshd_config.d/10-buggie-hardening.conf <<EOF
# Managed by deploy/setup-server.sh. Sorts before 50-cloud-init.conf, which sets
# PasswordAuthentication yes; first match wins, so this one does.
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOF

sshd -t || { echo "❌ sshd config is invalid; refusing to restart it."; exit 1; }

systemctl restart ssh.socket 2>/dev/null || systemctl restart ssh

# ── 6. Firewall ──

ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# Docker publishes ports by writing iptables rules that bypass ufw entirely, so a
# container binding 0.0.0.0 is reachable whatever ufw says. Only Caddy publishes
# anything, and it is meant to be public — but the gap is worth knowing about.

systemctl enable --now fail2ban

# ── 7. Application directory ──
mkdir -p "$APP_DIR"
chown "$DEPLOY_USER:$DEPLOY_USER" "$APP_DIR"

cat <<EOF

✅ Server ready.

Next, as $DEPLOY_USER (ssh $DEPLOY_USER@this-host):

  git clone $REPO $APP_DIR
  cd $APP_DIR
  cp deploy/env.production.example .env
  \$EDITOR .env                  # every value marked CHANGE ME
  docker compose -f deploy/docker-compose.prod.yml run --rm \\
      --entrypoint php app artisan key:generate --show   # paste into .env
  bash deploy/deploy.sh --first-run

⚠ Password authentication is now off. Confirm you can still get in with a key from
  a second terminal before closing this session:

      ssh $DEPLOY_USER@\$(hostname -I | awk '{print \$1}')
EOF
