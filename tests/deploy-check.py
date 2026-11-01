"""Validate the actual release ZIP, fresh SQL import, setup and hosted directory layout."""
from pathlib import Path
import http.cookiejar
import json
import os
import secrets
import shutil
import socket
import subprocess
import time
import urllib.request
import urllib.error
import uuid
import zipfile

ROOT=Path(__file__).resolve().parents[1]
PHP=ROOT/'.runtime/php/php.exe'
archive=max((ROOT/'deploy').glob('steamrex-*.zip'),key=lambda p:p.stat().st_mtime)
destination=ROOT/'.runtime'/('release-check-'+uuid.uuid4().hex)
destination.mkdir()
with zipfile.ZipFile(archive) as release:
    names={name.replace('\\','/') for name in release.namelist()}
    for required in ['public_html/.htaccess','public_html/api/index.php','public_html/admin/index.php','public_html/setup/index.php','app/bootstrap.php','database/install.sql','scripts/worker.php','scripts/backup.php','scripts/restore.php','public_html/assets/video/steamrex-spring.mp4','public_html/assets/css/home.css','public_html/assets/js/home.js','public_html/assets/images/steamrex-steam.jpg','app/Homepage.php']:
        assert required in names,required
    assert not any('.env.local' in name or '.runtime/' in name or name.startswith('storage/') or 'admin-access.json' in name for name in names)
    release.extractall(destination)
assert not (destination/'.env.example').read_bytes().startswith(b'\xef\xbb\xbf')
print('PASS: release ZIP has complete public/private/worker/schema layout and no credentials or runtimes')

environment={**os.environ}
for line in (ROOT/'.env.local').read_text(encoding='utf-8').splitlines():
    if '=' in line and not line.lstrip().startswith('#'):
        key,value=line.split('=',1);environment[key]=value.strip().strip('"')
token=secrets.token_hex(32)
environment.update(DB_DATABASE='carcare_restore_test',APP_ENV='local',SITE_URL='http://127.0.0.1:8084',SETUP_TOKEN=token,DEMO_MODE='false')

def run(script,*args):
    result=subprocess.run([str(PHP),str(ROOT/script),*map(str,args)],env=environment,capture_output=True)
    assert result.returncode==0,(script,result.stderr.decode(),result.stdout.decode())

run('tests/fixture.php','empty-restore')
run('tests/fixture.php','import-install',destination/'database/install.sql')
shutil.copy2(ROOT/'tests/hosting-router.php',destination/'hosting-router.php')
log=(destination/'server.log').open('wb')

def start(env):
    process=subprocess.Popen([str(PHP),'-S','127.0.0.1:8084','-t','public_html','hosting-router.php'],cwd=destination,env=env,stdout=log,stderr=log)
    for _ in range(40):
        try:
            with socket.create_connection(('127.0.0.1',8084),timeout=.2):return process
        except OSError:time.sleep(.1)
    process.terminate();raise AssertionError('Packaged server did not start')

jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar));base='http://127.0.0.1:8084'
def call(path,data=None,method='GET',status=200,csrf=None):
    headers={'Content-Type':'application/json'}
    if csrf:headers['X-CSRF-Token']=csrf
    req=urllib.request.Request(base+'/api/v1/'+path,data=json.dumps(data).encode() if data is not None else None,method=method,headers=headers)
    try:response=client.open(req)
    except urllib.error.HTTPError as response:
        assert response.code==status,(path,response.code)
        return json.load(response).get('error')
    assert response.status==status,(path,response.status)
    return json.load(response)['data']

process=start(environment)
try:
    assert call('setup/status')['available']
    setup={'setup_token':token,'name':'Release owner','email':'release@example.test','password':'ReleaseOnly!2026'}
    call('setup/owner',{**setup,'setup_token':'incorrect-token'},'POST',403)
    assert call('setup/owner',setup,'POST')['created']
    assert not call('setup/status')['available']
    call('setup/owner',setup,'POST',403)
    session=call('admin/auth/session');auth=call('admin/auth/login',{'email':setup['email'],'password':setup['password']},'POST',csrf=session['csrf_token'])
    assert auth['user']['role']=='super_admin'
    for path in ['/','/admin/','/setup/','/services.html','/robots.txt','/sitemap.xml','/assets/css/site.css']:
        with client.open(base+path) as response:assert response.status==200,path
    for path in ['/.env.local','/app/bootstrap.php','/database/install.sql','/storage/backups/test.jsonl.gz']:
        try:client.open(base+path);raise AssertionError(path+' became public')
        except urllib.error.HTTPError as error:assert error.code==404,path
    print('PASS: fresh MySQL import, token-protected first owner exactly once, login and public_html rendering')
    settings=call('admin/settings')['values'];call('admin/settings',{'_revision':settings['_revision'],'site_url':'https://steamrex.example','booking_enabled':False},'PUT',csrf=auth['csrf_token'])
finally:process.terminate();process.wait(timeout=10)

production={**environment,'APP_ENV':'production'}
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None
no_redirect=urllib.request.build_opener(NoRedirect())
process=start(production)
try:
    for path in ['/','/admin/','/setup/','/api/v1/health']:
        try:no_redirect.open(base+path);raise AssertionError('Production HTTP was not redirected')
        except urllib.error.HTTPError as error:
            assert error.code==308,(path,error.code)
            assert error.headers['Location']=='https://steamrex.example'+path
    print('PASS: production HTTPS/canonical-host enforcement before admin or setup forms are served')
finally:process.terminate();process.wait(timeout=10);log.close()
print('Packaged deployment checks passed. Apache routing still receives a final smoke test on the actual host.')
