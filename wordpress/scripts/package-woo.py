#!/usr/bin/env python3
"""Package only the active Woo adapter/theme. Vendor ZIPs are verified from the lock."""
import hashlib, json, pathlib, shutil, urllib.request, zipfile
root = pathlib.Path(__file__).resolve().parents[1]
out = root / '.build' / 'woo-release'
out.mkdir(parents=True, exist_ok=True)
lock = json.loads((root / 'woo-dependencies.json').read_text())
for name, dep in lock.items():
    target = out / (name + '.zip')
    if not target.exists() or hashlib.sha256(target.read_bytes()).hexdigest() != dep['sha256']:
        with urllib.request.urlopen(dep['url'], timeout=120) as response:
            data = response.read()
        if hashlib.sha256(data).hexdigest() != dep['sha256']:
            raise SystemExit('Checksum mismatch: ' + name)
        target.write_bytes(data)
for source, filename in [(root/'wp-content/plugins/freeplast-woo','freeplast-woo.zip'), (root/'wp-content/themes/freeplast','freeplast-woo-theme.zip')]:
    with zipfile.ZipFile(out/filename,'w',zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(source.rglob('*')):
            if path.is_file():
                info=zipfile.ZipInfo(str(path.relative_to(source.parent)),(2026,9,5,0,0,0))
                info.external_attr=0o100644 << 16
                info.compress_type=zipfile.ZIP_DEFLATED
                archive.writestr(info,path.read_bytes())
for source in [root/'scripts/migrate-to-woo.php',root/'scripts/verify-woo-state.php',root/'scripts/woo-cart.html',root/'wp-content/mu-plugins/freeplast-staging-mail.php']:
    shutil.copy2(source,out/source.name)
# A separate sealed closure, not a public plugin or an automatic init migration.
migration = out / 'photo-migration'
if migration.exists():
    shutil.rmtree(migration)
migration.mkdir()
shutil.copy2(root/'scripts/migrate-catalog-photos-release.php', migration/'run.php')
for name in ['catalog-photos.php', 'photo-release-state.php']:
    shutil.copy2(root/'scripts/lib'/name, migration/name)
shutil.copytree(root/'data/catalog-photos', migration/'photos')
files = sorted(p for p in out.rglob('*') if p.is_file() and p.name != 'WOO-SHA256SUMS')
(out/'WOO-SHA256SUMS').write_text(''.join(hashlib.sha256(p.read_bytes()).hexdigest()+'  '+str(p.relative_to(out))+'\n' for p in files))
print(out)
