#!/usr/bin/env python3
"""Build a deterministic local audit candidate. Never publish or overwrite release assets."""
from pathlib import Path
import argparse, hashlib, re, zipfile
parser = argparse.ArgumentParser()
parser.add_argument('--candidate', action='store_true', required=True)
parser.parse_args()
root = Path(__file__).resolve().parents[1]
module = root.name
source = (root / (module + '.php')).read_text()
match = re.search(r"\$this->version\s*=\s*'([^']+)'", source)
if not match:
    match = re.search(r"const VERSION\s*=\s*'([^']+)'", source)
if not match:
    raise SystemExit('Cannot determine declared version')
version = match.group(1)
if not re.fullmatch(r'\d+\.\d+\.\d+', version):
    raise SystemExit('Invalid declared version')
# Explicit root files: never include local PHP configuration or credentials.
roots = [module + '.php', 'index.php', 'README.md', 'LICENSE', 'config.xml', 'config_sk.xml',
         'logo.png', 'logo.svg', 'autoload.php', 'composer.json', 'cron.php']
folders = ['classes', 'controllers', 'views', 'translations', 'sql', 'upgrade', 'src', 'mails', 'lib']
suffixes = {'.php', '.tpl', '.js', '.css', '.svg', '.png', '.jpg', '.jpeg', '.gif', '.webp',
            '.ico', '.json', '.xml', '.xlf', '.sql', '.html', '.txt', '.md', '.woff', '.woff2',
            '.ttf', '.eot', '.wsdl', '.xsd'}
files = [root / n for n in roots if (root / n).is_file()]
for folder in folders:
    for p in (root / folder).rglob('*'):
        if p.is_symlink():
            raise SystemExit('Symlinks are not permitted in runtime trees')
        if not p.is_file() or p.name == '.DS_Store':
            continue
        parts = p.relative_to(root).parts
        if any(x in {'tests', 'docs', 'dist', '__pycache__', 'cache', 'logs', 'tmp'} for x in parts):
            continue
        if p.name == '.htaccess' or (not p.name.startswith('.') and p.suffix.lower() in suffixes):
            files.append(p)
        else:
            raise SystemExit('Unclassified runtime file; review packaging allowlist: ' + str(p.relative_to(root)))
for p in files:
    if p.is_symlink() or p.resolve().parent != root.resolve() and root.resolve() not in p.resolve().parents:
        raise SystemExit('Unsafe package member')
# A missing license is reported rather than silently assigning new legal terms.
if not (root / 'LICENSE').is_file():
    print('WARNING: LICENSE missing; candidate is not approved for publication')
output = root / 'dist' / 'audit-candidate'
output.mkdir(parents=True, exist_ok=True)
archive = output / (module + '-' + version + '.zip')
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for p in sorted(set(files), key=lambda x: x.relative_to(root).as_posix()):
        entry = zipfile.ZipInfo(module + '/' + p.relative_to(root).as_posix(), (2026, 1, 1, 0, 0, 0))
        entry.create_system = 3
        entry.external_attr = 0o100644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(entry, p.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(digest + '  ' + archive.name + '\n')
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    assert module + '/' + module + '.php' in z.namelist()
    assert all(n.startswith(module + '/') for n in z.namelist())
print(str(archive.relative_to(root)) + '  ' + digest)
