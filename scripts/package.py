"""Build the installable Tenant Jornada365 WordPress ZIP."""

from pathlib import Path
import re
from tempfile import TemporaryDirectory
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo


ROOT = Path(__file__).resolve().parents[1]
SLUG = "jornada365-tenant-portal"
PLUGIN = ROOT / "wordpress" / SLUG
RELEASE_EXTENSIONS = {".php", ".js", ".css", ".json", ".txt", ".svg", ".jpg", ".webp", ".woff2"}
VERSION = r"([0-9]+\.[0-9]+\.[0-9]+)"


def plugin_version():
    entry = (PLUGIN / f"{SLUG}.php").read_text(encoding="utf-8")
    readme = (PLUGIN / "readme.txt").read_text(encoding="utf-8")
    header = re.search(r"^\s*\*\s*Version:\s*" + VERSION + r"\s*$", entry, re.MULTILINE)
    runtime = re.search(r"define\(\s*'J365TP_VERSION',\s*'" + VERSION + r"'\s*\)", entry)
    stable = re.search(r"^Stable tag:\s*" + VERSION + r"\s*$", readme, re.MULTILINE)
    if not (header and runtime and stable and header[1] == runtime[1] == stable[1]):
        raise ValueError("The plugin header, J365TP_VERSION and Stable tag must have the same version.")
    return header[1]


def add_file(archive, path, name):
    # Fixed metadata keeps builds from the same files independent of local timestamps and permissions.
    member = ZipInfo(f"{SLUG}/{name}", date_time=(1980, 1, 1, 0, 0, 0))
    member.create_system = 3
    member.external_attr = 0o100644 << 16
    archive.writestr(member, path.read_bytes(), compress_type=ZIP_DEFLATED)


def package_plugin():
    dist = ROOT / "dist"
    dist.mkdir(exist_ok=True)
    destination = dist / f"{SLUG}-{plugin_version()}.zip"
    with TemporaryDirectory(dir=dist) as staging:
        temporary_zip = Path(staging) / destination.name
        with ZipFile(temporary_zip, "w") as archive:
            for source in sorted(PLUGIN.rglob("*"), key=lambda path: path.as_posix()):
                if source.is_symlink():
                    raise ValueError(f"Unexpected symlink: {source}")
                if not source.is_file():
                    continue
                if source.suffix.lower() not in RELEASE_EXTENSIONS:
                    raise ValueError(f"Unrecognized plugin file: {source}")
                add_file(archive, source, source.relative_to(PLUGIN).as_posix())
            add_file(archive, ROOT / "LICENSE", "LICENSE")
        with ZipFile(temporary_zip) as archive:
            if archive.testzip() is not None or f"{SLUG}/{SLUG}.php" not in archive.namelist():
                raise RuntimeError(f"Failed ZIP verification: {destination.name}")
        temporary_zip.replace(destination)
    print(destination.relative_to(ROOT).as_posix())


if __name__ == "__main__":
    package_plugin()
