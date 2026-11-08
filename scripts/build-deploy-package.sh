#!/usr/bin/env sh
set -eu

project_root="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
output_dir="${1:-$project_root/dist}"

mkdir -p "$output_dir/assets" "$output_dir/_app"
cp \
    "$project_root/public/index.html" \
    "$project_root/public/index.php" \
    "$project_root/public/action.php" \
    "$project_root/public/health.php" \
    "$project_root/public/editor.html" \
    "$output_dir/"
cp -R "$project_root/public/assets/." "$output_dir/assets/"
cp "$project_root/game.php" "$project_root/db_init.php" "$output_dir/_app/"
