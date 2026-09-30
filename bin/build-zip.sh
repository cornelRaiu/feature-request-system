#!/usr/bin/env bash
# Builds the release folder dist/feature-request-system/ and dist/feature-request-system.zip.
# The folder is what Plugin Check inspects and what gets deployed to WordPress.org.
set -euo pipefail

slug="feature-request-system"
root="$(cd "$(dirname "$0")/.." && pwd)"
dist="$root/dist"
target="$dist/$slug"

rm -rf "$dist"
mkdir -p "$target"

rsync -a --exclude-from="$root/.distignore" "$root/" "$target/"

# Install runtime dependencies only, with an autoloader that needs no filesystem lookups.
# WordPress.org expects composer.json next to vendor/, so it stays in the release.
cp "$root/composer.json" "$root/composer.lock" "$target/"
composer install --working-dir="$target" --no-dev --classmap-authoritative --no-interaction --no-progress --quiet
rm "$target/composer.lock"

(cd "$dist" && zip -qr "$slug.zip" "$slug")

echo "Built $dist/$slug.zip"
