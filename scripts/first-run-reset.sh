#!/usr/bin/env bash
# Returns a Linux or macOS test machine to a first-run state for Lando Studio's setup flow.
# Removes the Lando CLI, the PATH line its installer adds to shell profiles, and the app's data and
# install logs (moved to a backup folder, not deleted). Docker is reported, not removed: it is
# removed with the distro's package manager, and other software may depend on it.
# Windows is not covered; its first-run steps are separate.
#
# Usage: scripts/first-run-reset.sh [--dry-run] [--yes]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DRY_RUN=0
ASSUME_YES=0

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --yes) ASSUME_YES=1 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

LANDO_PATHS=("$HOME/.lando" "$HOME/.local/share/lando" "$HOME/.local/bin/lando")
PROFILES=("$HOME/.bashrc" "$HOME/.zshrc" "$HOME/.profile")
APP_DATA="${XDG_CONFIG_HOME:-$HOME/.config}/lando-studio"
BACKUP="$HOME/lando-studio-backup-$(date +%Y%m%d-%H%M%S)"

run() {
  if [[ $DRY_RUN -eq 1 ]]; then
    echo "[dry-run] $*"
  else
    "$@"
  fi
}

present=()
for path in "${LANDO_PATHS[@]}"; do
  if [[ -e "$path" || -L "$path" ]]; then
    present+=("$path")
  fi
done

profiles_with_line=()
for profile in "${PROFILES[@]}"; do
  if [[ -f "$profile" ]] && grep -q '#landopath$' "$profile"; then
    profiles_with_line+=("$profile")
  fi
done

install_logs=()
shopt -s nullglob
for log in "$ROOT"/storage/logs/install_*.log; do
  install_logs+=("$log")
done
shopt -u nullglob

app_data_present=0
[[ -e "$APP_DATA" ]] && app_data_present=1

if [[ ${#present[@]} -eq 0 && ${#profiles_with_line[@]} -eq 0 && $app_data_present -eq 0 && ${#install_logs[@]} -eq 0 ]]; then
  echo "Nothing to remove: no Lando CLI, PATH line, app data, or install logs found."
else
  echo "This will remove:"
  for path in "${present[@]}"; do echo "  Lando: $path"; done
  for profile in "${profiles_with_line[@]}"; do echo "  PATH line in: $profile"; done
  [[ $app_data_present -eq 1 ]] && echo "  App data (moved to $BACKUP): $APP_DATA"
  for log in "${install_logs[@]}"; do echo "  Install log (moved to $BACKUP): $log"; done

  if [[ $DRY_RUN -eq 0 && $ASSUME_YES -eq 0 ]]; then
    read -r -p "Continue? [y/N] " answer
    [[ "$answer" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }
  fi

  if [[ $app_data_present -eq 1 || ${#install_logs[@]} -gt 0 ]]; then
    run mkdir -p "$BACKUP"
  fi
  [[ $app_data_present -eq 1 ]] && run mv "$APP_DATA" "$BACKUP/"
  for log in "${install_logs[@]}"; do run mv "$log" "$BACKUP/"; done

  for path in "${present[@]}"; do run rm -rf "$path"; done
  for profile in "${profiles_with_line[@]}"; do run sed -i '/#landopath$/d' "$profile"; done
fi

echo
if command -v docker >/dev/null 2>&1; then
  echo "Docker is still installed at $(command -v docker). Not removed by this script."
  echo "  Arch/Omarchy: sudo pacman -Rns docker   (check 'ufw-docker' first, it depends on docker)"
  echo "  Debian/Ubuntu: sudo apt remove docker-ce docker-ce-cli containerd.io"
fi

if command -v lando >/dev/null 2>&1; then
  echo "Warning: lando is still on PATH at $(command -v lando)."
else
  echo "lando is not on PATH."
fi
echo "Open a new terminal (or restart Lando Studio) so PATH changes apply."
