"""Build a WordPress-installable zip for the plugin.

Uses Python's stdlib zipfile, which is rigorously ZIP-spec compliant:
- Forward-slash path separators on every platform
- Optional explicit directory entries (some hosts' PclZip needs them
  to create the folder hierarchy on extraction)
- No platform-specific quirks like Compress-Archive's backslash bug

Usage:
    python build-plugin-zip.py [--version 3.2.5]
"""
from __future__ import annotations

import argparse
import os
import re
import sys
import zipfile
from pathlib import Path

PLUGIN_SLUG = "schoolbooth-photo-manager"
MAIN_FILE = f"{PLUGIN_SLUG}.php"

# Things we never want shipped in the plugin zip.
EXCLUDED_DIRS = {".git", ".github", "dist", "node_modules", ".vscode"}
EXCLUDED_FILE_PATTERNS = (
    re.compile(r"^release_notes_.*"),
    re.compile(r"^build-plugin-zip\.(ps1|py)$"),
    re.compile(r".*\.zip$"),
    re.compile(r".*\.tar\.gz$"),
)


def detect_version(repo_root: Path) -> str:
    """Read Version: from the plugin header."""
    header = (repo_root / MAIN_FILE).read_text(encoding="utf-8")
    match = re.search(r"^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)",
                      header, re.MULTILINE)
    if not match:
        raise RuntimeError("Could not detect Version: from plugin header")
    return match.group(1)


def is_excluded(rel_path: str) -> bool:
    parts = rel_path.replace("\\", "/").split("/")
    if parts[0] in EXCLUDED_DIRS:
        return True
    name = parts[-1]
    return any(p.match(name) for p in EXCLUDED_FILE_PATTERNS)


def build_zip(repo_root: Path, out_zip: Path) -> None:
    out_zip.parent.mkdir(parents=True, exist_ok=True)
    if out_zip.exists():
        out_zip.unlink()

    files: list[tuple[str, Path]] = []  # (zip_arcname, source_path)
    dirs: set[str] = set()

    for root, dirnames, filenames in os.walk(repo_root):
        root_path = Path(root)
        rel_root = root_path.relative_to(repo_root)

        # Prune excluded directories so os.walk doesn't recurse into them.
        dirnames[:] = [d for d in dirnames if d not in EXCLUDED_DIRS]

        for fname in filenames:
            rel_file = (rel_root / fname).as_posix() if str(rel_root) != "." else fname
            if is_excluded(rel_file):
                continue
            arcname = f"{PLUGIN_SLUG}/{rel_file}"
            files.append((arcname, root_path / fname))
            # Record every parent dir so we emit explicit dir entries.
            parent = arcname.rsplit("/", 1)[0]
            while "/" in parent:
                dirs.add(parent + "/")
                parent = parent.rsplit("/", 1)[0]
            dirs.add(f"{PLUGIN_SLUG}/")

    # Emit dir entries in sorted order first, then file entries.
    with zipfile.ZipFile(out_zip, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for d in sorted(dirs):
            zi = zipfile.ZipInfo(d)
            zi.external_attr = (0o40755 << 16) | 0x10  # directory bit
            zi.compress_type = zipfile.ZIP_STORED
            zf.writestr(zi, b"")
        for arcname, src in sorted(files):
            zf.write(src, arcname)

    print(f"Built {out_zip} ({out_zip.stat().st_size:,} bytes, "
          f"{len(files)} files, {len(dirs)} dirs)")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--version", help="Override version (default: from plugin header)")
    args = parser.parse_args()

    repo_root = Path(__file__).resolve().parent
    version = args.version or detect_version(repo_root)
    out_zip = repo_root / "dist" / f"{PLUGIN_SLUG}-v{version}.zip"
    build_zip(repo_root, out_zip)

    # Sanity check: re-open and confirm contents.
    with zipfile.ZipFile(out_zip) as zf:
        names = zf.namelist()
        assert any("\\" in n for n in names) is False, \
            "FAIL: zip contains backslash paths"
        assert f"{PLUGIN_SLUG}/{MAIN_FILE}" in names, \
            f"FAIL: main plugin file missing from zip"
    print("Sanity check OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
