"""Build an uploadable archive from an explicit allowlist, excluding live secrets."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import argparse

ROOT = Path(__file__).resolve().parents[1]
FILES = [
    "index.php", ".htaccess", "config.example.php", "schema.sql", "README.md",
    "app/.htaccess", "app/functions.php", "app/views.php",
    "assets/app.css", "assets/app.js", "assets/favicon.svg",
]

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--update", action="store_true", help="Preserve existing config and cPanel .htaccess rules")
    args = parser.parse_args()
    files = [name for name in FILES if name != "config.example.php" and not name.endswith(".htaccess")] if args.update else FILES
    target = ROOT / "dist" / ("link-rovidito-frissites.zip" if args.update else "link-rovidito-cpanel.zip")
    target.parent.mkdir(exist_ok=True)
    with ZipFile(target, "w", ZIP_DEFLATED) as archive:
        for filename in files:
            archive.write(ROOT / filename, filename)
    with ZipFile(target) as archive:
        assert archive.testzip() is None
        assert set(archive.namelist()) == set(files)
        assert "config.php" not in archive.namelist()
    print(f"Csomag elkészült: {target} ({target.stat().st_size:,} bájt)")
