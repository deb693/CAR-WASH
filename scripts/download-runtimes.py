"""Optional Windows-local runtimes. No system install or PATH changes. Python 3 required."""
from pathlib import Path, PurePosixPath
import hashlib
import json
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1] / '.runtime'
ROOT.mkdir(exist_ok=True)


def download(url, target):
    print('Downloading', target.name, flush=True)
    with urllib.request.urlopen(url, timeout=120) as response, target.open('wb') as output:
        while block := response.read(1024 * 1024):
            output.write(block)


if not (ROOT / 'php/php.exe').exists():
    with urllib.request.urlopen('https://downloads.php.net/~windows/releases/releases.json', timeout=45) as response:
        release = json.load(response)['8.3']['nts-vs16-x64']['zip']
    archive = ROOT / 'php.zip'
    download('https://downloads.php.net/~windows/releases/' + release['path'], archive)
    if hashlib.sha256(archive.read_bytes()).hexdigest() != release['sha256']:
        raise RuntimeError('PHP archive checksum mismatch')
    with zipfile.ZipFile(archive) as zipped:
        for item in zipped.infolist():
            if '..' in PurePosixPath(item.filename).parts or item.filename.startswith('/'):
                raise RuntimeError('Unsafe archive path')
        zipped.extractall(ROOT / 'php')

(ROOT / 'php/php.ini').write_text(
    'extension_dir="ext"\nextension=pdo_mysql\nextension=mbstring\nextension=openssl\n'
    'date.timezone=UTC\ndisplay_errors=Off\nlog_errors=On\n', encoding='utf-8')

if not (ROOT / 'mysql/bin/mysqld.exe').exists():
    archive = ROOT / 'mysql.zip'
    download('https://cdn.mysql.com/Downloads/MySQL-8.0/mysql-8.0.46-winx64.zip', archive)
    with zipfile.ZipFile(archive) as zipped:
        for item in zipped.infolist():
            parts = PurePosixPath(item.filename).parts
            if '..' in parts or item.filename.startswith('/'):
                raise RuntimeError('Unsafe archive path')
            if len(parts) < 2:
                continue
            relative = Path(*parts[1:])
            destination = ROOT / 'mysql' / relative
            if item.is_dir():
                destination.mkdir(parents=True, exist_ok=True)
            else:
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(zipped.read(item))

print('Project-local PHP and MySQL are ready. Run .\\scripts\\start-local.ps1')

