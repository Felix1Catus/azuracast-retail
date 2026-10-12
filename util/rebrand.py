#!/usr/bin/env python3
"""Acacia Radio rebrand: re-applies the product name over the source tree.

Upstream merges bring the original name back, so the rebrand is this script rather than a one-off edit. Run it
after every upstream merge, review the diff, commit:

    util/rebrand.py            # apply (text and file names)
    util/rebrand.py --check    # list what's left; exit 1 if anything is (for CI)

Phase 1, everything users see: the product name in UI text, messages, e-mails, logs, CLI output, docs and
translations (msgid and msgstr together), the panel wordmark, links to the original project's sites.
Phase 2, internal names: paths (/var/acacia), the acacia user and database defaults, ACACIA_* variables,
acacia_cli and acacia:* commands, Docker image/container/project names, Liquidsoap function names, code
identifiers, and file names. docs/retail/REBRAND.md lists what existing installs migrate and how.

Never changed:
  - legal notices: LICENSE.md, docs/retail/ATTRIBUTION.md (AGPL-3.0 requires keeping them)
  - names of external projects: composer packages azuracast/*, images ghcr.io/azuracast/*, upstream GitHub URLs,
    the GitHub repositories Felix1Catus/azuracast-retail and azuracast-tracker-plugin (until they're renamed)
  - CHANGELOG.md (upstream history), README.md, third-party code
  - any line containing the marker  rebrand:keep  (migration code that must name the old paths and names)
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
KEEP_MARKER = "rebrand:keep"

EXCLUDE = re.compile(
    r"^("
    r"LICENSE\.md|.*/LICENSE|.*/NOTICE|CHANGELOG\.md|README\.md|docs/retail/ATTRIBUTION\.md|docs/retail/REBRAND\.md"
    r"|util/rebrand\.py|composer\.lock|package-lock\.json"
    r"|vendor/.*|node_modules/.*|web/static/vite_dist/.*"
    r")$"
)
TEXT_SUFFIXES = {
    ".php", ".phtml", ".twig", ".vue", ".ts", ".js", ".mjs", ".cjs", ".json", ".scss", ".css", ".html",
    ".md", ".txt", ".po", ".pot", ".yml", ".yaml", ".neon", ".xml", ".xsl", ".liq", ".sh", ".tmpl", ".conf",
    ".ini", ".env", ".sql", ".svg", ".py", ".toml", ".cfg", ".dist", "",
}

# Names of external projects, swapped out before the rules run and restored afterwards.
PROTECTED = re.compile(
    r"github\.com/AzuraCast/[\w./#?=&%-]*"
    r"|raw\.githubusercontent\.com/AzuraCast/[\w./#?=&%-]*"
    r"|github\.com/orgs/AzuraCast[\w./-]*"
    r"|ghcr\.io/azuracast/[\w.:@-]*"
    r"|\bazuracast/(?:nowplaying|doctrine-entity-normalizer|updater|icecast-ac)\b"
    r"|Felix1Catus/azuracast-retail(?:\.git)?"
    r"|azuracast-tracker-plugin"
    r"|upstream-baseline-[0-9a-f]+",
    re.IGNORECASE,
)

PHASE1: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"azura<strong>cast</strong>"), WORDMARK),
    # User-Agent product tokens can't contain a space.
    (re.compile(r"(['\"])AzuraCast(/| )(?=['\"]\s*\.)"), r"\1AcaciaRadio\2"),
    (re.compile(r"'User-Agent' => 'AzuraCast"), "'User-Agent' => 'AcaciaRadio"),
    # "Powered by AzuraCast" links to the upstream site lose the link, keep plain text.
    (re.compile(r"<a[^>]*href=\"https?://(?:www\.)?azuracast\.com/?\"[^>]*>\s*AzuraCast\s*</a>"), BRAND),
    # The product name as a word.
    (re.compile(r"(?<![\w@/\\.-])AzuraCast(?![\w-])"), BRAND),
    (re.compile(r"(?<![\w@/\\.-])AZURACAST(?![\w-])"), BRAND.upper()),
    # References to the original project read wrongly once renamed.
    (re.compile(r"\b[Aa] (?:stock|upstream) Acacia Radio\b"), "an upstream"),
    (re.compile(r"\b(?:stock|upstream) Acacia Radio\b"), "upstream"),
    (re.compile(r"\bAcacia Radio (?=\d+\.\d+)"), "upstream "),
    (re.compile(r"\bAcacia Radio Central\b"), "the upstream update service"),
    (re.compile(r"Acacia Radio Retail"), BRAND),
    # Translators' spellings ("Azuracast", "d'Azuracast").
    (re.compile(r"(?<![\w@/\\.-])Azuracast(?![\w-])"), BRAND),
    # Inflected forms in translations: compounds ("AzuraCast-Installation" -> "Acacia-Radio-Installation") and
    # case endings ("AzuraCastu" -> "Acacia Radio").
    (re.compile(r"(?<![\w@/\\.-])Azura[Cc]ast(?=-)"), "Acacia-Radio"),
    (re.compile(r"(?<![\w@/\\.-])Azura[Cc]ast[a-z\u00e0-\u024f]{1,4}(?![\w-])"), BRAND),
    # Directly followed by non-Latin script (Korean particles, Chinese): "AzuraCast가", "AzuraCast设置".
    (re.compile(r"(?<![\w@/\\.-])Azura[Cc]ast(?=[^\x00-\u024f])"), BRAND),
    (re.compile(r"(?<=[^\x00-\u024f])Azura[Cc]ast"), BRAND),
]

PHASE2: list[tuple[re.Pattern[str], str]] = [
    # Source references in translation files point at the upstream CI checkout.
    (re.compile(r"/home/runner/work/AzuraCast/AzuraCast/"), ""),
    (re.compile(r"AzuraCastastic"), "Acacia-tastic"),
    # Package metadata.
    (re.compile(r'"name": "azuracast/azuracast"'), '"name": "acacia/acacia-radio"'),
    # Paths, CLI.
    (re.compile(r"/var/azuracast\b"), "/var/acacia"),
    (re.compile(r"\bazuracast_cli\b"), "acacia_cli"),
    # Environment variables (the retail image pair gets shorter names).
    (re.compile(r"\bAZURACAST_RETAIL_PREVIOUS_IMAGE\b"), "ACACIA_PREVIOUS_IMAGE"),
    (re.compile(r"\bAZURACAST_RETAIL_IMAGE\b"), "ACACIA_IMAGE"),
    (re.compile(r"\bAZURACAST_(?=[A-Z0-9])"), "ACACIA_"),
    # Docker images, containers, the source checkout.
    (re.compile(r"\bazuracast-retail-music-downloader\b"), "acacia-radio-music-downloader"),
    (re.compile(r"\bazuracast-music-downloader\b"), "acacia-music-downloader"),
    (re.compile(r"\bazuracast-retail\b"), "acacia-radio"),
    (re.compile(r"\bazuracast_music_downloader"), "acacia_music_downloader"),
    # File names: azuracast.env, azuracast.sample.env, azuracast.liq, azuracast.conf.tmpl, vendor/azuracast.ts ...
    (re.compile(r"\bazuracast(?=\.(?:env|sample\.env|dev\.env|liq|conf|tmpl|ts|js|php)\b)"), "acacia"),
    (re.compile(r"(?<=/vendor/)azuracast\b"), "acacia"),
    # Liquidsoap: settings.azuracast.* and the azuracast.* function namespace.
    (re.compile(r"\bsettings\.azuracast\b"), "settings.acacia"),
    (re.compile(r"(?<=[\"'])azuracast_"), "acacia_"),
    (re.compile(r"\bazuracast\.(?=[a-z_])"), "acacia."),
    # Console command namespace (azuracast:setup, azuracast:sync:run, ...). "azuracast:azuracast" (chown) too.
    (re.compile(r"\bazuracast:(?=[a-z])"), "acacia:"),
    # Code identifiers: AzuraCastCentral, useAzuraCast, azuracastEnv, AZURACAST_API_NAME ...
    (re.compile(r"AzuraCast(?=[A-Z])"), "AcaciaRadio"),
    (re.compile(r"(?<=[a-z0-9])AzuraCast"), "AcaciaRadio"),
    (re.compile(r"\bazuracast(?=[A-Z])"), "acacia"),
    (re.compile(r"\bazuracast_(?=[a-z])"), "acacia_"),
    (re.compile(r"(?<=[a-z0-9_])azuracast\b"), "acacia"),
    # Hyphenated names (azuracast-player-popup, my-azuracast, your-azuracast-site) and paths (/path/to/azuracast).
    (re.compile(r"\bazuracast(?=-[a-z])"), "acacia"),
    (re.compile(r"(?<=[a-z]-)azuracast\b"), "acacia"),
    (re.compile(r"(?<=/)azuracast\b(?!/)"), "acacia"),
    (re.compile(r"(?<=:-)azuracast\b"), "acacia"),
    # Remaining word: the Linux user and group, database defaults, compose project and container name, metrics
    # namespace, translation headers.
    (re.compile(r"(?<![\w./@-])azuracast(?![\w/-])"), "acacia"),
]

LEFTOVER = re.compile(r"zuracast", re.IGNORECASE)


def tracked_files() -> list[str]:
    out = subprocess.run(["git", "ls-files", "-z"], cwd=ROOT, capture_output=True, check=True).stdout
    return [n for n in out.decode().split("\0") if n]


def is_text(name: str) -> bool:
    path = ROOT / name
    return (not EXCLUDE.match(name) and path.suffix.lower() in TEXT_SUFFIXES
            and path.is_file() and not path.is_symlink())


def apply_line(line: str) -> tuple[str, int]:
    if KEEP_MARKER in line or "zuracast" not in line.lower():
        return line, 0
    saved: list[str] = []

    def protect(m: re.Match[str]) -> str:
        saved.append(m.group(0))
        return f"\x00{len(saved) - 1}\x00"

    text = PROTECTED.sub(protect, line)
    total = 0
    for pattern, replacement in PHASE1 + PHASE2:
        text, n = pattern.subn(replacement, text)
        total += n
    text = re.sub(r"\x00(\d+)\x00", lambda m: saved[int(m.group(1))], text)
    return text, total


def apply_text(text: str) -> tuple[str, int]:
    total = 0
    lines = text.split("\n")
    for i, line in enumerate(lines):
        lines[i], n = apply_line(line)
        total += n
    return "\n".join(lines), total


def new_name(name: str) -> str:
    if EXCLUDE.match(name):
        return name
    parts = name.split("/")
    renamed = []
    for part in parts:
        part = re.sub(r"AzuraCast", "AcaciaRadio", part)
        part = re.sub(r"azuracast", "acacia", part)
        renamed.append(part)
    return "/".join(renamed)


def leftover_lines(name: str, text: str) -> list[str]:
    found = []
    for lineno, line in enumerate(text.splitlines(), 1):
        if KEEP_MARKER in line or not LEFTOVER.search(line):
            continue
        if not LEFTOVER.search(PROTECTED.sub("", line)):
            continue
        found.append(f"{name}:{lineno}: {line.strip()[:160]}")
    return found


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--check", action="store_true", help="only report remaining mentions")
    args = parser.parse_args()

    files = tracked_files()
    changed = replacements = 0
    leftovers: list[str] = []

    for name in files:
        if not is_text(name):
            continue
        path = ROOT / name
        try:
            original = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        if "zuracast" not in original.lower():
            continue
        updated, n = apply_text(original)
        if n and not args.check:
            path.write_text(updated, encoding="utf-8")
            changed += 1
            replacements += n
        leftovers += leftover_lines(name, original if args.check else updated)

    renames = [(n, new_name(n)) for n in files if new_name(n) != n]
    if args.check:
        leftovers += [f"{old}: file name (would become {new})" for old, new in renames]
    else:
        for old, new in renames:
            (ROOT / new).parent.mkdir(parents=True, exist_ok=True)
            subprocess.run(["git", "mv", old, new], cwd=ROOT, check=True)
        print(f"Rebranded {replacements} mentions in {changed} files; renamed {len(renames)} files.")

    print(f"{len(leftovers)} mentions left.")
    for line in leftovers[:300]:
        print("  " + line)
    return 1 if args.check and leftovers else 0


if __name__ == "__main__":
    sys.exit(main())
