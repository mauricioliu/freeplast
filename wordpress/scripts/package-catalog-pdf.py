#!/usr/bin/env python3
"""Deterministic, offline catalog DATA bundle; no vendors, downloads or target access."""
import pathlib
import shutil
import sys
import zipfile


def main():
    if sys.argv[1:] in (["--version"], ["-v"], ["-V"]):
        print("1.0.0")
        return
    if sys.argv[1:] == ["--help"]:
        print("Run: python3 wordpress/scripts/package-catalog-pdf.py\nBuilds adapter/theme ZIPs and sealed PDF migration in wordpress/.build/catalog-pdf-release. No arguments or network.")
        return
    if sys.argv[1:]:
        print("error: unknown arguments\nhelp: python3 wordpress/scripts/package-catalog-pdf.py --help")
        raise SystemExit(2)
    root = pathlib.Path(__file__).resolve().parents[1]
    out = root / ".build/catalog-pdf-release"
    # Refuse foreign/symlink outputs rather than deleting a possibly owned build.
    if out.is_symlink() or (out.exists() and not (out / ".catalog-pdf-package").is_file()):
        raise RuntimeError("Output collision; inspect wordpress/.build/catalog-pdf-release")
    out.mkdir(parents=True, exist_ok=True)
    (out / ".catalog-pdf-package").touch()
    for source, name in [(root / "wp-content/plugins/freeplast-woo", "freeplast-woo.zip"), (root / "wp-content/themes/freeplast", "freeplast-woo-theme.zip")]:
        with zipfile.ZipFile(out / name, "w", zipfile.ZIP_DEFLATED) as archive:
            for path in sorted(source.rglob("*")):
                if path.is_symlink():
                    raise RuntimeError("Symlink in release source")
                if path.is_file():
                    info = zipfile.ZipInfo(str(path.relative_to(source.parent)), (1980, 1, 1, 0, 0, 0))
                    info.external_attr = 0o100644 << 16
                    info.compress_type = zipfile.ZIP_DEFLATED
                    archive.writestr(info, path.read_bytes())
    migration = out / "migration"
    if migration.exists():
        shutil.rmtree(migration)
    shutil.copytree(root / "scripts/catalog-pdf-release", migration)
    shutil.copy2(root / "scripts/lib/catalog-pdf.php", migration / "catalog-pdf.php")
    shutil.copy2(root / "data/products.json", migration / "products.json")
    print(out)


if __name__ == "__main__":
    main()
