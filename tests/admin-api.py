"""End-to-end HTTP checks against the isolated carcare_test server on port 8081."""
from pathlib import Path
from datetime import datetime, timedelta, timezone
import base64
import gzip
import http.cookiejar
import json
import os
import socketserver
import subprocess
import threading
import uuid
import urllib.error
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
PHP=ROOT/'.runtime/php/php.exe'
BASE='http://127.0.0.1:8081'
ENV={**os.environ,'DB_DATABASE':'carcare_test'}


class Client:
    def __init__(self):
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf=''

    def call(self,path,body=None,method='GET',expected=200,key=None,csrf=True,raw=None,content_type=None):
        headers={'Accept':'application/json'}
        if body is not None:headers['Content-Type']='application/json'
        if key:headers['Idempotency-Key']=key
        if csrf and self.csrf:headers['X-CSRF-Token']=self.csrf
        if content_type:headers['Content-Type']=content_type
        request=urllib.request.Request(BASE+'/api/v1/'+path,data=raw if raw is not None else json.dumps(body).encode() if body is not None else None,headers=headers,method=method)
        try:
            response=self.opener.open(request)
        except urllib.error.HTTPError as response:
            assert response.code==expected,(path,response.code,response.read().decode())
            return json.loads(response.read() or '{}')
        assert response.status==expected,(path,response.status)
        result=json.load(response)
        return result.get('data',result)

    def login(self,email='owner@example.test',password='TestOnlySteamRex!2026'):
        self.csrf=self.call('admin/auth/session')['csrf_token']
        data=self.call('admin/auth/login',{'email':email,'password':password},'POST')
        self.csrf=data['csrf_token']
        return data


def cli(script,*arguments,env=ENV):
    result=subprocess.run([str(PHP),str(ROOT/script),*map(str,arguments)],env=env,capture_output=True,text=True,encoding='utf-8')
    assert result.returncode==0,(script,result.stderr,result.stdout)
    return result.stdout


def webpage(path):
    with urllib.request.urlopen(BASE+path) as response:return response.read().decode('utf-8')


def fresh_day(offset=25):
    day=datetime.now(timezone.utc)+timedelta(days=offset)
    while day.weekday()==6:day+=timedelta(days=1)
    return day.strftime('%Y-%m-%d')


owner=Client()
owner.call('admin/settings',expected=401)
session=owner.call('admin/auth/session')
owner.call('admin/auth/login',{'email':'owner@example.test','password':'wrong'},'POST',expected=419,csrf=False)
owner.csrf=session['csrf_token']
owner.call('admin/auth/login',{'email':'owner@example.test','password':'wrong'},'POST',expected=401)
owner.login()
owner.call('admin/settings',{},'PUT',expected=419,csrf=False)
print('PASS: admin auth, session rotation and CSRF enforcement')

operator=owner.call('admin/users',{'name':'Test operator','email':'operator@example.test','role':'operator','active':True,'password':'OperatorOnly!2026'},'POST')
ops=Client();ops.login('operator@example.test','OperatorOnly!2026')
ops.call('admin/bookings')
ops.call('admin/settings',expected=403)
ops.call('admin/catalogue/services',{'name':'Forbidden'},'POST',expected=403)
owners=owner.call('admin/users')['items'];me=next(a for a in owners if a['email']=='owner@example.test')
owner.call('admin/users/'+str(me['id']),{**me,'active':False,'password':''},'PUT',expected=422)
print('PASS: operator permissions and last-super-admin protection')


def create(resource,payload):return owner.call('admin/catalogue/'+resource,payload,'POST')
def edit(resource,record,**changes):return owner.call('admin/catalogue/'+resource+'/'+str(record['_key']),{**record,**changes},'PUT')

review=create('reviews',{'author':'Review acceptance','quote':'<script>not executable</script> A properly attributed test review.','rating':4,'source_label':'Test source','source_url':'https://steamrex.com/','sort_order':99,'active':True})
assert 'Review acceptance' in webpage('/') and '&lt;script&gt;not executable&lt;/script&gt;' in webpage('/')
review=edit('reviews',review,quote='Updated review acceptance text')
assert 'Updated review acceptance text' in webpage('/')
owner.call('admin/catalogue/reviews/'+review['_key'],{**review,'source_url':'javascript:alert(1)'},'PUT',expected=422)
owner.call('admin/catalogue/reviews/'+review['_key'],{'version':review['version']},'DELETE')
assert 'Updated review acceptance text' not in webpage('/')
review=owner.call('admin/catalogue/reviews/'+review['_key'])
review=edit('reviews',review,active=False)
settings=owner.call('admin/settings')['values']
owner.call('admin/settings',{'_revision':settings['_revision'],'hero_video_enabled':False},'PUT')
assert 'data-enabled="false"' in webpage('/')
settings=owner.call('admin/settings')['values']
owner.call('admin/settings',{'_revision':settings['_revision'],'hero_video':'https://example.test/untrusted.mp4'},'PUT',expected=422)
owner.call('admin/settings',{'_revision':settings['_revision'],'hero_video_enabled':True},'PUT')
assert 'data-enabled="true"' in webpage('/')
print('PASS: owner-managed attributed reviews, safe source URLs, public escaping and homepage video controls')


service=create('services',{'name':'Acceptance detailing','slug':'acceptance-detailing','summary':'Admin-controlled service summary','description':'<script>Never execute this</script>\n\nSafe, editable service text.','exclusions':'Keep personal belongings clear.','image_path':'/assets/images/hero-car.jpg','sort_order':50,'active':True})
vehicle_class=create('vehicle-classes',{'name':'Acceptance class','sort_order':50,'active':True})
make=create('vehicle-makes',{'name':'Acceptance Motors','active':True})
model=create('vehicle-models',{'name':'Acceptance One','make_id':make['id'],'class_id':vehicle_class['id'],'active':True})
addon=create('add-ons',{'name':'Acceptance extra','description':'Optional extra','price_minor':500,'duration_minutes':10,'active':True})
packages=[]
for tier in [1,2,3]:
    packages.append(create('packages',{'name':'Acceptance tier '+str(tier),'service_id':service['id'],'class_id':vehicle_class['id'],'tier':tier,'summary':'Editable package scope','price_minor':4000+tier*1000,'duration_minutes':30,'inclusions':['Inspection','Careful cleaning'],'exclusions':'No engine cleaning','badge':'','add_on_ids':[addon['id']],'active':True}))
area=create('service-areas',{'name':'Acceptance area','postcode':'4321','surcharge_minor':0,'active':True})
override=create('price-overrides',{'package_id':packages[0]['id'],'model_id':model['id'],'price_minor':4200})
extra_override=create('addon-price-overrides',{'add_on_id':addon['id'],'class_id':vehicle_class['id'],'price_minor':700})
selection={'service_id':service['id'],'vehicle_model_id':model['id'],'package_id':packages[0]['id'],'add_on_ids':[addon['id']],'postcode':'4321'}
quote=owner.call('public/quotes',selection,'POST');assert quote['total_minor']==4900
public=owner.call('public/catalogue');assert any(s['id']==service['id'] for s in public['services'])
html=webpage('/services/acceptance-detailing.html');assert 'Admin-controlled service summary' in html and '&lt;script&gt;' in html
assert 'acceptance-detailing.html' in webpage('/sitemap.xml')
owner.call('admin/catalogue/services/'+service['id'],{**service,'name':'Stale write','version':service['version']-1},'PUT',expected=409)
print('PASS: services, vehicles, three packages, add-ons, both override types, live SSR and stale-edit protection')

settings=owner.call('admin/settings')['values']
owner.call('admin/settings',{'_revision':settings['_revision'],'tax_mode':'exclusive'},'PUT')
assert owner.call('public/quotes',selection,'POST')['total_minor']==6125
settings=owner.call('admin/settings')['values'];owner.call('admin/settings',{'_revision':settings['_revision'],'tax_mode':'inclusive'},'PUT')
owner.call('admin/catalogue/service-areas/'+str(area['_key']),{'version':area['version']},'DELETE')
owner.call('public/quotes',selection,'POST',expected=422)
area=edit('service-areas',owner.call('admin/catalogue/service-areas/'+str(area['_key'])))
owner.call('admin/catalogue/packages/'+packages[2]['id'],{'version':packages[2]['version']},'DELETE')
owner.call('public/quotes',selection,'POST',expected=409)
packages[2]=create('packages',{**packages[2],'add_on_ids':[addon['id']]})
assert packages[2]['id'].startswith('packages-')
print('PASS: editable tax, coverage removal/restore and safe three-tier package restoration')

sunday=datetime.now(timezone.utc)+timedelta(days=32)
while sunday.weekday()!=6:sunday+=timedelta(days=1)
sunday=sunday.strftime('%Y-%m-%d')
rule=create('availability-rules',{'weekday':'7','opens_at':'10:00','closes_at':'14:00','interval_minutes':20,'capacity':2,'active':True})
sunday_quote=owner.call('public/quotes',selection,'POST')
sunday_slots=owner.call('public/availability?quote_id='+sunday_quote['quote_id']+'&date='+sunday)['slots'];assert sunday_slots[0]['label']=='10:00'
sunday_body={'quote_id':sunday_quote['quote_id'],'starts_at':sunday_slots[0]['starts_at'],'customer':{'full_name':'Sunday capacity test','email':'sunday@example.test','phone':'+4512345678'},'address':'TEST ONLY Sunday address','consent':True}
sunday_refs=[]
for index in range(2):sunday_refs.append(owner.call('public/bookings',sunday_body,'POST',201,key='sunday-capacity-test-key-'+str(index))['reference'])
owner.call('public/bookings',sunday_body,'POST',409,key='sunday-capacity-test-key-3')
rule=edit('availability-rules',rule,active=False)
assert not owner.call('public/availability?quote_id='+sunday_quote['quote_id']+'&date='+sunday)['slots']
for reference in sunday_refs:assert owner.call('admin/bookings?q='+reference)['items'][0]['status']=='pending_confirmation'
print('PASS: admin-created Sunday hours, start interval, capacity two and existing reservations preserved on closure')

date=fresh_day();quote=owner.call('public/quotes',selection,'POST')
slots=owner.call('public/availability?quote_id='+quote['quote_id']+'&date='+date)['slots'];assert slots
body={'quote_id':quote['quote_id'],'starts_at':slots[0]['starts_at'],'customer':{'full_name':'Acceptance Customer','email':'customer@example.test','phone':'+4512345678'},'address':'TEST ONLY acceptance address','message':'Test request','consent':True,'website':''}
booking=owner.call('public/bookings',body,'POST',201,key='acceptance-booking-key-2026')
replay=owner.call('public/bookings',body,'POST',201,key='acceptance-booking-key-2026');assert replay['reference']==booking['reference']
items=owner.call('admin/bookings?q='+booking['reference'])['items'];assert len(items)==1
record=owner.call('admin/bookings/'+str(items[0]['id']));bid=record['id'];old_start=record['starts_at']
assert record['quote']['total_minor']==4900 and len(record['notifications'])==2
record=owner.call('admin/bookings/'+str(bid),{'version':record['version'],'action':'note','note':'Internal acceptance note'},'PATCH');assert len(record['notes'])==1
record=owner.call('admin/bookings/'+str(bid),{'version':record['version'],'action':'confirm','reason':''},'PATCH');assert record['status']=='confirmed'
extra_override=edit('addon-price-overrides',extra_override,price_minor=900)
amended_quote=owner.call('public/quotes',selection,'POST');assert amended_quote['total_minor']==5100
owner.call('admin/bookings/'+str(bid),{'version':record['version'],'action':'amend','quote_id':amended_quote['quote_id'],'customer_agreed':False,'address':record['address'],'reason':'New treatment agreed'},'PATCH',expected=422)
record=owner.call('admin/bookings/'+str(bid),{'version':record['version'],'action':'amend','quote_id':amended_quote['quote_id'],'customer_agreed':True,'address':record['address'],'reason':'New treatment agreed'},'PATCH');assert record['quote']['total_minor']==5100
extra_override=edit('addon-price-overrides',extra_override,price_minor=700)
owner.call('admin/bookings/'+str(bid),{'version':record['version']-1,'action':'cancel','reason':'Stale cancellation'},'PATCH',expected=409)
owner.call('admin/bookings/'+str(bid),{'version':record['version'],'action':'complete','reason':''},'PATCH',expected=422)
new_date=fresh_day(28)
new_slots=owner.call(f'admin/bookings/{bid}/availability?date={new_date}')['slots']
record=owner.call('admin/bookings/'+str(bid),{'version':record['version'],'action':'propose_reschedule','starts_at':new_slots[0]['starts_at'],'reason':'Customer requested another date'},'PATCH')
assert record['starts_at']==old_start and record['status']=='reschedule_requested'
record=owner.call('admin/bookings/'+str(bid),{'version':record['version'],'action':'accept_reschedule','customer_agreed':True,'reason':'Customer agreement recorded'},'PATCH')
assert record['starts_at']!=old_start and record['status']=='confirmed'
record=owner.call('admin/bookings/'+str(bid),{'version':record['version'],'action':'cancel','reason':'Acceptance test complete'},'PATCH');assert record['status']=='cancelled'
assert any(s['starts_at']==new_slots[0]['starts_at'] for s in owner.call(f'admin/bookings/{bid}/availability?date={new_date}')['slots'])
print('PASS: durable receipts, details/history, confirmation, guarded completion, agreed rescheduling and capacity release')

enquiry=owner.call('public/enquiries',{'customer':body['customer'],'service_id':service['id'],'vehicle':'Unlisted customer vehicle','postcode':'9999','message':'Please advise','consent':True},'POST',201,key='acceptance-enquiry-key-2026')
eid=owner.call('admin/enquiries?q='+enquiry['reference'])['items'][0]['id']
e=owner.call(f'admin/enquiries/{eid}');e=owner.call(f'admin/enquiries/{eid}',{'action':'contacted','version':e['version']},'PATCH')
quote=owner.call('public/quotes',selection,'POST');time=owner.call('public/availability?quote_id='+quote['quote_id']+'&date='+new_date)['slots'][0]['starts_at']
converted=owner.call(f'admin/enquiries/{eid}/convert',{'version':e['version'],'quote_id':quote['quote_id'],'starts_at':time,'address':'TEST ONLY agreed service address','customer_agreed':True,'confirm':True},'POST')
assert converted['booking']['status']=='confirmed' and converted['enquiry']['converted_booking_id']==converted['booking']['id']
owner.call(f'admin/enquiries/{eid}/convert',{'version':converted['enquiry']['version'],'quote_id':quote['quote_id'],'starts_at':time,'address':'TEST ONLY','customer_agreed':True},'POST',expected=409)
cli('tests/fixture.php','past',converted['booking']['id'])
done=owner.call('admin/bookings/'+str(converted['booking']['id']),{'version':converted['booking']['version'],'action':'complete'},'PATCH');assert done['status']=='completed'
owner.call('admin/catalogue/services/'+service['id'],{'version':service['version']},'DELETE')
assert owner.call('admin/bookings/'+str(done['id']))['quote']['total_minor']==4900
try:urllib.request.urlopen(BASE+'/services/acceptance-detailing.html');raise AssertionError('Archived service remained public')
except urllib.error.HTTPError as error:assert error.code==404
service=edit('services',owner.call('admin/catalogue/services/'+service['id']))
print('PASS: enquiry contact/conversion exactly once, completion and immutable history after catalogue removal')

page=create('content',{'id':'acceptance-page','title':'Acceptance page','meta_title':'SEO acceptance title','meta_description':'Server-rendered SEO acceptance description','heading':'Editable heading','summary':'Editable opening','body':'## Editable section\n\nSafe body <script>never execute</script>','image_path':'','published':True})
page_html=webpage('/pages/acceptance-page.html');assert 'SEO acceptance title | SteamRex' in page_html and '&lt;script&gt;' in page_html and 'application/ld+json' in page_html
faq=create('faqs',{'question':'Can administrators edit these answers?','answer':'Yes, this is an admin-managed answer.','sort_order':90,'active':True})
assert 'admin-managed answer' in webpage('/how-it-works.html')
blocked=create('blocked-dates',{'day':fresh_day(35),'reason':'Acceptance holiday','starts_at':'','ends_at':''})
quote=owner.call('public/quotes',selection,'POST');assert not owner.call('public/availability?quote_id='+quote['quote_id']+'&date='+fresh_day(35))['slots']
owner.call('admin/catalogue/blocked-dates/'+blocked['_key'],{'version':blocked['version']},'DELETE')
print('PASS: admin-created crawlable page, metadata, escaped content and blocked-date control')

# MIME/image validation and private upload control.
boundary='steamrex-acceptance-boundary'
def upload(data,name):
    raw=(f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n').encode()+data+f'\r\n--{boundary}--\r\n'.encode()
    return owner.call('admin/media',method='POST',raw=raw,content_type='multipart/form-data; boundary='+boundary,expected=422 if name=='fake.png' else 200)
upload(b'not an image','fake.png')
image=upload(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='),'acceptance.png')
owner.call('admin/media/'+image['id'],{'version':image['version'],'name':'Acceptance image','alt_text':'One-pixel test image'},'PUT')
owner.call('admin/media/'+image['id'],{'version':image['version']+1},'DELETE')
assert urllib.request.urlopen(BASE+image['path']).status==200
print('PASS: safe image upload/edit/removal without breaking existing URLs')

delivery=json.loads(cli('tests/fixture.php','worker','100'));assert delivery['sent']>0
messages=owner.call('admin/notifications')['items'];assert any(n['transport']=='log' and n['status']=='sent' for n in messages)
print('PASS: after-commit notification queue and local email capture')

# A local fake SMTP server verifies the transport. Nothing is sent to real addresses.
received=[]
class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 local test\r\n');auth=0;data=False;content=[]
        while True:
            line=self.rfile.readline()
            if not line:break
            if data:
                if line==b'.\r\n':received.append(b''.join(content));self.wfile.write(b'250 accepted\r\n');data=False
                else:content.append(line)
                continue
            if auth:
                auth-=1;self.wfile.write(b'334 UGFzc3dvcmQ6\r\n' if auth else b'235 authenticated\r\n')
            elif line.startswith(b'EHLO'):self.wfile.write(b'250-localhost\r\n250 AUTH LOGIN\r\n')
            elif line.startswith(b'AUTH'):auth=2;self.wfile.write(b'334 VXNlcm5hbWU6\r\n')
            elif line.startswith(b'DATA'):data=True;content=[];self.wfile.write(b'354 message\r\n')
            elif line.startswith(b'QUIT'):break
            else:self.wfile.write(b'250 ok\r\n')
class Server(socketserver.TCPServer):allow_reuse_address=True
server=Server(('127.0.0.1',0),SMTP);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
settings=owner.call('admin/settings')['values']
owner.call('admin/settings',{'_revision':settings['_revision'],'mail_transport':'smtp','smtp_host':'127.0.0.1','smtp_port':server.server_address[1],'smtp_security':'none','smtp_username':'local-test','smtp_password':'LocalFakePassword!2026'},'PUT')
safe=owner.call('admin/settings');assert 'LocalFakePassword!2026' not in json.dumps(safe) and safe['values']['smtp_password_configured']
e=owner.call('public/enquiries',{'customer':body['customer'],'service_id':service['id'],'vehicle':'Test','message':'SMTP acceptance','consent':True},'POST',201,key='smtp-acceptance-key-2026')
delivery=json.loads(cli('tests/fixture.php','worker','100'));assert delivery['sent']>=2 and len(received)>=2
server.shutdown();server.server_close()
failure=owner.call('public/enquiries',{'customer':body['customer'],'service_id':service['id'],'vehicle':'Test','message':'Retry acceptance','consent':True},'POST',201,key='smtp-failure-key-2026')
failed_delivery=json.loads(cli('tests/fixture.php','worker','100'));assert failed_delivery['failed']>=2
failed_messages=[m for m in owner.call('admin/notifications')['items'] if m['status']=='pending' and m['last_error']]
assert failed_messages
assert owner.call('admin/enquiries?q='+failure['reference'])['total']==1
for message in failed_messages:owner.call('admin/notifications/'+str(message['id'])+'/retry',{},'POST')
settings=owner.call('admin/settings')['values'];owner.call('admin/settings',{'_revision':settings['_revision'],'mail_transport':'log','smtp_security':'starttls'},'PUT')
assert json.loads(cli('tests/fixture.php','worker','100'))['sent']>=2
assert 'LocalFakePassword!2026' not in json.dumps(owner.call('admin/audit'))
print('PASS: SMTP loopback delivery, outage persistence, retry/recovery, encrypted password and secret redaction')

owner.call('admin/bookings/'+str(bid),{'version':record['version']},'DELETE')
owner.call('admin/bookings/'+str(bid),expected=404)
cli('tests/fixture.php','erasure-check',bid)
assert 'erase_personal_data' in json.dumps(owner.call('admin/audit'))
owner.call('admin/auth/logout',{},'POST')
owner.call('admin/bookings',expected=401)
print('PASS: terminal-request erasure, immutable audit and logout invalidation')

backup=ROOT/('.runtime/acceptance-backup-'+uuid.uuid4().hex+'.jsonl.gz');cli('scripts/backup.php',backup)
counts=json.loads(cli('tests/fixture.php','counts'))
restore_env={**ENV,'DB_DATABASE':'carcare_restore_test'}
cli('tests/fixture.php','empty-restore',env=restore_env)
cli('scripts/restore.php','--file='+str(backup),'--confirm-database=carcare_restore_test',env=restore_env)
assert json.loads(cli('tests/fixture.php','counts',env=restore_env))==counts
html=cli('tests/fixture.php','production-preview',env={**ENV,'APP_ENV':'production'})
assert 'content="index, follow"' in html and 'https://steamrex.example/' in html and 'application/ld+json' in html
staging_html=cli('tests/fixture.php','production-preview',env={**ENV,'APP_ENV':'staging'})
assert 'content="noindex, nofollow"' in staging_html
# Restore development flags after the isolated production-render check.
owner.login();settings=owner.call('admin/settings')['values'];owner.call('admin/settings',{'_revision':settings['_revision'],'demo_mode':True,'search_indexing':False,'site_url':BASE},'PUT')
print('PASS: consistent backup/restore drill and production HTML canonical/indexing/structured-data rendering')
print('All admin/API acceptance scenarios passed. No external email was sent.')
