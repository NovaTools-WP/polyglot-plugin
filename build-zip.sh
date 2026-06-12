#!/usr/bin/env bash
#
# Build a production-ready ZIP of NovaTools Polyglot plugin.
# Uses Python's zipfile module (no zip CLI needed).
#
# Usage: ./build-zip.sh [output-dir]
#
set -euo pipefail

# ── Config ──────────────────────────────────────────────────────────────────
PLUGIN_NAME="novatools-polyglot"
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
OUTPUT_DIR="${1:-$PLUGIN_DIR}"

# ── Validate ────────────────────────────────────────────────────────────────
if [ ! -f "$PLUGIN_DIR/novatools-polyglot.php" ]; then
    echo "ERROR: Run this script from the plugin root (or it must live there)."
    exit 1
fi

# ── Version from plugin header ──────────────────────────────────────────────
VERSION="$(grep -m1 'Version:' "$PLUGIN_DIR/novatools-polyglot.php" | awk '{print $NF}')"
ZIP_NAME="${PLUGIN_NAME}-${VERSION}.zip"
ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"

echo "Building ${ZIP_NAME} ..."

# ── Create ZIP via Python ───────────────────────────────────────────────────
python3 - "$PLUGIN_DIR" "$ZIP_PATH" "${PLUGIN_NAME}" <<'PYEOF'
import os, sys, zipfile

plugin_dir = sys.argv[1]
zip_path   = sys.argv[2]
prefix     = sys.argv[3] + "/"        # folder name inside ZIP

# Top-level dirs to skip (only matched at depth 1)
skip_toplevel_dirs = {
    ".git",
    "node_modules",
    "src",
    ".vscode",
}

# Filenames to skip at any depth
skip_filenames = {
    ".editorconfig",
    ".gitignore",
    ".prettierignore",
    ".prettierrc.json",
    "package.json",
    "pnpm-lock.yaml",
    "pnpm-workspace.yaml",
    "postcss.config.cjs",
    "tailwind.config.js",
    "vite.admin.config.js",
    "composer.json",
    "composer.lock",
    "build-zip.sh",
    "benchmark.php",
}

# Filename suffixes to skip
skip_suffixes = (".log", ".code-workspace", ".zip")

def should_skip(rel, is_dir=False):
    """Return True if this file/dir should be excluded."""
    parts = rel.split("/")
    # Top-level dirs: only match at depth 1
    if is_dir and len(parts) == 1 and parts[0] in skip_toplevel_dirs:
        return True
    # Filename match at any depth
    if not is_dir and parts[-1] in skip_filenames:
        return True
    # Suffix match
    for s in skip_suffixes:
        if parts[-1].endswith(s):
            return True
    return False

count = 0
with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(plugin_dir):
        # Prune excluded directories in-place so os.walk skips them
        dirs[:] = [d for d in dirs if not should_skip(os.path.relpath(os.path.join(root, d), plugin_dir), is_dir=True)]

        for f in files:
            full = os.path.join(root, f)
            rel  = os.path.relpath(full, plugin_dir)
            if should_skip(rel):
                continue
            arcname = prefix + rel          # novatools-polyglot/includes/...
            zf.write(full, arcname)
            count += 1

print(f"  {count} files added")
PYEOF

# ── Done ────────────────────────────────────────────────────────────────────
SIZE="$(du -h "$ZIP_PATH" | cut -f1)"
echo ""
echo "✅  ${ZIP_NAME} created (${SIZE})"
echo "    → ${ZIP_PATH}"
echo ""
echo "Upload via: WordPress Admin → Plugins → Add New → Upload Plugin"
