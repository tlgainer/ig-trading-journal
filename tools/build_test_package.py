"""Build and verify an allowlisted, reproducible WordPress development ZIP."""
from pathlib import Path
import hashlib, json, re, zipfile

root = Path(__file__).resolve().parents[1]
version = re.search(r"Version:\s*(\S+)", (root / "ig-trading-journal.php").read_text()).group(1)
schema = int(re.search(r"VERSION = '(\d+)'", (root / "src/Infrastructure/Installer.php").read_text()).group(1))
output = root / "output"
output.mkdir(exist_ok=True)
target = output / f"ig-trading-journal-{version}-test.zip"
if target.exists():
    raise SystemExit("Preserve the existing build: choose a new version or move your own prior candidate explicitly.")
files = [root / "ig-trading-journal.php", root / "README.md"]
for folder in ("src", "includes", "assets"):
    files.extend(p for p in (root / folder).rglob("*") if p.is_file())
sql = sorted((root / "docs").glob("*.sql"))
assert len(sql) == schema
assert [p.name[:3] for p in sql] == [f"{i:03}" for i in range(1, schema + 1)]
files.extend(sql)
for name in (f"test-build-{version}.md", "user-guide.md", "operations.md", "private-images.md", "financial-data-integration.md", "ai-settings.md", "ai-owner-generation.md", "ai-server-evidence.md", "asset-typeahead.md", "tickerbot-implementation.md"):
    path = root / "docs" / name
    if path.is_file():
        files.append(path)
names = ["ig-trading-journal/" + p.relative_to(root).as_posix() for p in files]
assert len(names) == len(set(names))
assert all(p.suffix in (".php", ".js", ".css", ".md", ".sql") for p in files)
manifest = {}
with zipfile.ZipFile(target, "x", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path, name in sorted(zip(files, names), key=lambda item: item[1]):
        content = path.read_bytes()
        info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, content)
        manifest[name] = {"sha256": hashlib.sha256(content).hexdigest(), "bytes": len(content)}
with zipfile.ZipFile(target) as archive:
    assert archive.testzip() is None
    assert set(archive.namelist()) == set(names)
    assert f"Version: {version}" in archive.read("ig-trading-journal/ig-trading-journal.php").decode()
    for path, name in zip(files, names):
        assert archive.read(name) == path.read_bytes()
digest = hashlib.sha256(target.read_bytes()).hexdigest()
target.with_suffix(".zip.sha256").write_text(f"{digest}  {target.name}\n", encoding="ascii")
target.with_suffix(".manifest.json").write_text(json.dumps({"version": version, "schema": schema, "sha256": digest, "files": manifest}, indent=2), encoding="utf-8")
print(json.dumps({"file": str(target), "files": len(files), "migrations": schema, "sha256": digest, "bytes": target.stat().st_size}))
