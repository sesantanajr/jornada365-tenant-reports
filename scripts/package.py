"""Build installable WordPress ZIPs from the source tree."""

from pathlib import Path
import re
from zipfile import ZIP_DEFLATED, ZipFile


ROOT = Path(__file__).resolve().parents[1]
PLUGINS = (
    ("jornada365-tenant-portal", "jornada365-tenant-portal.php"),
    ("j365-bridge", "jornada365-bridge.php"),
)
RELEASE_EXTENSIONS = {".php", ".js", ".css", ".json", ".txt", ".svg", ".webp", ".woff2"}


def package_plugin(slug, entry):
    plugin = ROOT / "wordpress" / slug
    header = (plugin / entry).read_text(encoding="utf-8")
    version = re.search(r"(?m)^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$", header)
    if version is None:
        raise ValueError(f"Missing version header: {entry}")
    dist = ROOT / "dist"
    dist.mkdir(exist_ok=True)
    destination = dist / f"{slug}-{version[1]}.zip"
    with ZipFile(destination, "w", ZIP_DEFLATED) as archive:
        for file in sorted(plugin.rglob("*")):
            if file.is_symlink():
                raise ValueError(f"Unexpected symlink: {file}")
            if file.is_file() and file.suffix.lower() in RELEASE_EXTENSIONS:
                archive.write(file, file.relative_to(plugin.parent).as_posix())
        archive.write(ROOT / "LICENSE", f"{slug}/LICENSE")
    with ZipFile(destination) as archive:
        if archive.testzip() is not None or f"{slug}/{entry}" not in archive.namelist():
            raise RuntimeError(f"Failed ZIP verification: {destination.name}")
    print(destination.relative_to(ROOT))


if __name__ == "__main__":
    for plugin_slug, entrypoint in PLUGINS:
        package_plugin(plugin_slug, entrypoint)
