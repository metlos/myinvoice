#!/usr/bin/env bash
# Přepočítá fixed-output (FOD) hashe ve flake.nix — pnpm deps (frontend) a
# composer vendorHash (backend) — a zapíše je zpět. Lokální obdoba CI workflow
# .github/workflows/update-nix-hashes.yml; pusť ji, když změníš lockfile
# (web/pnpm-lock.yaml / api/composer.lock) a nechceš čekat na auto-PR.
#
# Jak to funguje: hash se nastaví na FAKE placeholder, `nix build` proto vždy
# selže a vypíše ten správný v "got: sha256-…"; ten se přečte a zapíše zpět.
#
# Použití:
#   bash cmd/update-nix-hashes.sh           — přepočítá oba hashe + ověří build
#   bash cmd/update-nix-hashes.sh web       — jen pnpm deps hash
#   bash cmd/update-nix-hashes.sh vendor    — jen composer vendorHash
#
# Pouze Linux/macOS — Nix na nativním Windows neběží (jen přes WSL2, což je
# Linux). Proto záměrně bez .cmd/.ps1 varianty, stejně jako release-bundle.sh.
#
# Bez Nixe lokálně: nastav proměnnou NIX na podman příkaz, který spustí nix
# v kontejneru nixos/nix-flakes (mount projektu na stejné absolutní cestě):
#   export NIX_CONFIG="sandbox = false"
#   NIX="podman run --rm \
#     -v /home/lukas/Projects/myinvoice:/home/lukas/Projects/myinvoice:z \
#     --network host \
#     -e NIX_CONFIG \
#     nixos/nix-flakes nix" \
#   bash cmd/update-nix-hashes.sh
# Přepínač :z — SELinux relabel (Fedora); --network host — přístup k cache.nixos.org;
# NIX_CONFIG exportovaný z hostitele, -e NIX_CONFIG předá hodnotu bez word-split problémů;
# sandbox = false — kontejner nemůže vnořit Linux namespaces.

set -euo pipefail

NIX="${NIX:-nix}"

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FLAKE="${PROJECT_ROOT}/flake.nix"
FAKE="sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="

if [ "${NIX}" = "nix" ] && ! command -v nix >/dev/null 2>&1; then
  echo "✗ nix není v PATH — nastav NIX='podman run ...' nebo nainstaluj Nix." >&2
  exit 1
fi

# Pro daný flake atribut a komentářový marker: nastav hash na FAKE (build pak
# vždy nahlásí ten správný), přečti "got:" z chyby a zapiš zpět.
refresh() {
  local attr="$1" marker="$2" got
  echo "→ ${attr} (${marker})"
  sed -i -E "s|\"sha256-[A-Za-z0-9+/=]+\";( # ${marker})|\"${FAKE}\";\1|" "$FLAKE"
  got="$(${NIX} build "${PROJECT_ROOT}#${attr}" --no-link 2>&1 >/dev/null |
    grep -oE 'got: +sha256-[A-Za-z0-9+/=]+' | awk '{print $2}' | head -n1 || true)"
  if [ -z "${got}" ]; then
    echo "  ✗ nepodařilo se zjistit hash pro .#${attr}" >&2
    exit 1
  fi
  sed -i -E "s|\"sha256-[A-Za-z0-9+/=]+\";( # ${marker})|\"${got}\";\1|" "$FLAKE"
  echo "  ✓ ${got}"
}

# Bez argumentu přepočítej oba; jinak jen vyžádané atributy.
targets=("$@")
if [ ${#targets[@]} -eq 0 ]; then
  targets=(web vendor)
fi

for attr in "${targets[@]}"; do
  case "$attr" in
  web) refresh web '@pnpm-deps-hash' ;;
  vendor) refresh vendor '@composer-vendor-hash' ;;
  *)
    echo "✗ neznámý atribut '${attr}' (povoleno: web, vendor)" >&2
    exit 1
    ;;
  esac
done

# Ověř, že kompletní balík s novými hashi opravdu postaví.
echo "→ ověření: nix build .#default"
${NIX} build "${PROJECT_ROOT}#default" --no-link --print-build-logs

echo
echo "Hotovo. Hashe ve flake.nix aktuální."
