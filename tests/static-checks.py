"""Static HTML/link, source syntax and isolated production SEO checks. No network dependencies."""
from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit, unquote
import json
import re
import shutil
import subprocess
import sys
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
PUBLIC = ROOT / 'public'
PHP = ROOT / '.runtime/php/php.exe'
errors = []


class Document(HTMLParser):
    def __init__(self):
        super().__init__()
        self.h1 = 0
        self.main = 0
        self.title = ''
        self.in_title = False
        self.description = ''
        self.links = []
        self.canonical = []
        self.robots = ''

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'h1': self.h1 += 1
        if tag == 'main': self.main += 1
        if tag == 'title': self.in_title = True
        if tag == 'meta' and attrs.get('name') == 'description': self.description = attrs.get('content', '')
        if tag == 'meta' and attrs.get('name') == 'robots': self.robots = attrs.get('content', '')
        if tag == 'link' and attrs.get('rel') == 'canonical': self.canonical.append(attrs.get('href'))
        for attribute in ['src', 'href']:
            if attrs.get(attribute): self.links.append(attrs[attribute])
        if tag == 'img' and not all(key in attrs for key in ['alt', 'width', 'height']):
            errors.append('Image missing alt or dimensions')

    def handle_endtag(self, tag):
        if tag == 'title': self.in_title = False

    def handle_data(self, data):
        if self.in_title: self.title += data


titles = set()
for page in PUBLIC.rglob('*.html'):
    document = Document()
    document.feed(page.read_text(encoding='utf-8'))
    assert document.h1 == 1 and document.main == 1, page
    assert document.title and document.description, page
    assert 'SteamRex' in document.title, page
    assert 'Car Care' not in page.read_text(encoding='utf-8'), page
    assert document.title not in titles, page
    titles.add(document.title)
    assert document.robots == 'noindex, nofollow', 'Development page must remain noindex: ' + str(page)
    for link in document.links:
        parsed = urlsplit(link)
        if parsed.scheme or parsed.netloc or not parsed.path:
            continue
        target = (PUBLIC / unquote(parsed.path).lstrip('/')) if parsed.path.startswith('/') else (page.parent / unquote(parsed.path))
        if target.is_dir(): target = target / 'index.html'
        assert target.is_file(), f'{page.name}: missing {link}'
print('PASS: static HTML metadata, one H1/main, development noindex and local links/assets')

for page in [ROOT/'README.md', *ROOT.glob('docs/*.md')]:
    source = page.read_text(encoding='utf-8')
    assert source.count('```') % 2 == 0, page
    for target in re.findall(r'\]\(([^)]+)\)', source):
        if re.match(r'^[A-Za-z][A-Za-z0-9+.-]*:', target) or target.startswith('#'): continue
        assert (page.parent / target.split('#')[0]).exists(), f'{page}: missing {target}'
print('PASS: documentation links and fenced code blocks')

for page in [*ROOT.glob('app/*.php'), *ROOT.glob('public/**/*.php'), *ROOT.glob('scripts/*.php'), *ROOT.glob('tests/*.php'), ROOT/'router.php']:
    result = subprocess.run([str(PHP), '-l', str(page)], capture_output=True)
    assert result.returncode == 0, result.stderr
for script in PUBLIC.glob('assets/js/*.js'):
    result = subprocess.run(['node', '--input-type=module', '--check'], input=script.read_bytes(), capture_output=True)
    assert result.returncode == 0, result.stderr
print('PASS: PHP and JavaScript syntax')


assert not errors, errors
print('PASS: source validation; dynamic production SEO is exercised by admin-api.py')
