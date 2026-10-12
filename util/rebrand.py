#!/usr/bin/env python3
"""Acacia Radio rebrand: re-applies the product name over the source tree.

Upstream AzuraCast merges bring "AzuraCast" back into strings, so the rebrand is this script rather than a
one-off edit. Run it after every upstream merge, review the diff, commit:

    util/rebrand.py            # apply
    util/rebrand.py --check    # list what's left (exit 1 if anything user-visible remains); for CI

What it changes (phase 1, everything users see; docs/retail/REBRAND.md):
  - the product name in UI text, messages, e-mails, logs, CLI output, docs and translations (msgid and msgstr
    together, so existing translations keep matching)
  - the panel header wordmark
  - links to azuracast.com sites in the UI (removed or pointed at this server)

What it deliberately leaves alone:
  - LICENSE.md, NOTICE and the attribution in docs/retail/ATTRIBUTION.md: AGPL-3.0 requires keeping the
    copyright and licence notices of the original work
  - CHANGELOG.md (upstream's release history) and third-party code (vendor/, node_modules/)
  - code identifiers (class and function names, env vars, paths, the azuracast user): phase 2
"""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

BRAND = "Acacia Radio"
WORDMARK = "acacia<strong>radio</strong>"

# Never touched: legal notices, upstream history, third-party code, binary/generated files.
EXCLUDE = re.compile(
    r"^("
    r"LICENSE\.md|.*/LICENSE|.*/NOTICE|CHANGELOG\.md|README\.md|docs/retail/ATTRIBUTION\.md|docs/retail/REBRAND\.md"
    r"|util/rebrand\.py|composer\.lock|package-lock\.json"
    r"|vendor/.*|node_modules/.*|web/static/vite_dist/.*|frontend/entities/ApiInterfaces\.ts"
    r")$"
)
TEXT_SUFFIXES = {
    ".php", ".phtml", ".twig", ".vue", ".ts", ".js", ".mjs", ".cjs", ".json", ".scss", ".css", ".html",
    ".md", ".txt", ".po", ".pot", ".yml", ".yaml", ".neon", ".xml", ".xsl", ".liq", ".sh", ".tmpl", ".conf",
    ".ini", ".env", ".sql", ".svg", "",
}

# (pattern, replacement), applied in order. The product name is replaced as a word only: identifiers such as
# AzuraCastCentral, useAzuraCast, \AzuraCast\ or github.com/AzuraCast are left for phase 2 / the URL rules.
RULES: list[tuple[re.Pattern[str], str]] = [
    # Panel header wordmark.
    (re.compile(r"azura<strong>cast</strong>"), WORDMARK),
    # User-Agent product tokens can't contain a space.
    (re.compile(r"(['\"])AzuraCast(/| )(?=['\"]\s*\.)"), r"\1AcaciaRadio\2"),
    (re.compile(r"'User-Agent' => 'AzuraCast"), "'User-Agent' => 'AcaciaRadio"),
    # "Powered by AzuraCast" style links to the upstream site lose the link, keep plain text.
    (re.compile(r"<a[^>]*href=\"https?://(?:www\.)?azuracast\.com/?\"[^>]*>\s*AzuraCast\s*</a>"), BRAND),
    # The product name as a word.
    (re.compile(r"(?<![\w@/\\.-])AzuraCast(?![\w-])"), BRAND),
    (re.compile(r"(?<![\w@/\\.-])AZURACAST(?![\w-])"), BRAND.upper()),
    # References to the original project read wrongly once renamed: "upstream AzuraCast" means "upstream".
    (re.compile(r"\b[Aa] (?:stock|upstream) Acacia Radio\b"), "an upstream"),
    (re.compile(r"\b(?:stock|upstream) Acacia Radio\b"), "upstream"),
    # A version number after the name is the upstream release this is based on.
    (re.compile(r"\bAcacia Radio (?=\d+\.\d+)"), "upstream "),
    (re.compile(r"\bAcacia Radio Central\b"), "the upstream update service"),
    # The fork's old name.
    (re.compile(r"Acacia Radio Retail"), BRAND),
    # Translators' spellings ("Azuracast", "d'Azuracast").
    (re.compile(r"(?<![\w@/\\.-])Azuracast(?![\w-])"), BRAND),
]

# Phase 1 is done when no product name (any capitalised spelling, as a word) and no link to an azuracast.com
# site is left. Lowercase identifiers (paths, users, env vars, container names) are phase 2.
LEFTOVER = re.compile(
    r"(?<![\w@/\\.-])Azura[Cc]ast(?![\w-])|AZURACAST(?![\w_-])|azuracast\.com(?![a-z])",
)


def tracked_files() -> list[Path]:
    out = subprocess.run(["git", "ls-files", "-z"], cwd=ROOT, capture_output=True, check=True).stdout
    files = []
    for name in out.decode().split("\0"):
        if not name or EXCLUDE.match(name):
            continue
        path = ROOT / name
        if path.suffix.lower() in TEXT_SUFFIXES and path.is_file() and not path.is_symlink():
            files.append(path)
    return files


def apply(text: str) -> tuple[str, int]:
    total = 0
    for pattern, replacement in RULES:
        text, n = pattern.subn(replacement, text)
        total += n
    return text, total


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--check", action="store_true", help="only report remaining mentions")
    args = parser.parse_args()

    changed_files = 0
    replacements = 0
    leftovers: list[str] = []

    for path in tracked_files():
        try:
            original = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        if "zuracast" not in original.lower():
            continue

        updated, n = apply(original)
        if n and not args.check:
            path.write_text(updated, encoding="utf-8")
            changed_files += 1
            replacements += n

        for lineno, line in enumerate((original if args.check else updated).splitlines(), 1):
            if LEFTOVER.search(line):
                leftovers.append(f"{path.relative_to(ROOT)}:{lineno}: {line.strip()[:160]}")

    if not args.check:
        print(f"Rebranded {replacements} mentions in {changed_files} files.")
    print(f"{len(leftovers)} user-visible mentions left.")
    for line in leftovers[:200]:
        print("  " + line)
    return 1 if args.check and leftovers else 0


if __name__ == "__main__":
    sys.exit(main())
