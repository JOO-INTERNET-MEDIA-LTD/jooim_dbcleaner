#!/usr/bin/env python3
"""Build an installable ZIP without credentials, tests or repository metadata."""
import hashlib
from pathlib import Path
import re
import zipfile

root = Path(__file__).resolve().parent.parent
module = 'jooim_dbcleaner'
version = re.search(r"\$this->version = '([^']+)'", (root / (module + '.php')).read_text()).group(1)
xml_version = re.search(r'<version><!\[CDATA\[([^]]+)\]\]></version>', (root / 'config.xml').read_text()).group(1)
if version != xml_version:
    raise SystemExit('PHP and XML versions disagree')
files = [root / name for name in ('LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'SUPPORT.md',
                                  'RELEASE.md', 'config.xml', 'cron.php', 'index.php', module + '.php', 'logo.png')]
for directory in ('classes', 'controllers', 'translations', 'views'):
    files.extend(p for p in (root / directory).rglob('*') if p.is_file() and p.suffix in ('.php', '.tpl', '.png'))
out = root / 'dist'
out.mkdir(exist_ok=True)
archive = out / (module + '-v' + version + '.zip')
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
    for p in sorted(files):
        if p.is_symlink():
            raise SystemExit('Symlinks are not allowed in release files')
        info = zipfile.ZipInfo(module + '/' + p.relative_to(root).as_posix(), date_time=(2026, 10, 14, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        z.writestr(info, p.read_bytes())
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
(out / (archive.name + '.sha256')).write_text(checksum + '  ' + archive.name + '\n')
print(str(archive))
print('SHA-256: ' + checksum)
