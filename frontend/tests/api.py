import os, shutil, subprocess, urllib.request, urllib.error, json, time, tempfile, socket
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
temporary=tempfile.TemporaryDirectory(prefix='shipinventory-api-')
SITE=Path(temporary.name)
SITE.mkdir(exist_ok=True)
shutil.copytree(ROOT/'src',SITE/'src',dirs_exist_ok=True)
shutil.copy(ROOT/'index.php',SITE/'index.php')
(SITE/'data').mkdir(exist_ok=True)
(SITE/'seed.php').write_text('''<?php
require __DIR__.'/src/config.php'; require __DIR__.'/src/db.php';
$pdo=db();db_init_schema($pdo);
$pdo->exec("INSERT INTO boats(id,name,name_norm,registration,registration_norm) VALUES (1,'Barco','barco','REG','reg')");
foreach (['admin','chief_engineer','mechanic'] as $i=>$role) {
 $id=$i+1;
 $pdo->prepare('INSERT INTO users(id,username,username_norm,first_name,last_name,password_hash,role,boat_id,is_primary_admin) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$role,$role,'Test','Test',password_hash('fixture-password',PASSWORD_DEFAULT),$role,1,$id===1?1:0]);
 $pdo->prepare('INSERT INTO api_tokens(user_id,token_hash,created_at) VALUES(?,?,?)')->execute([$id,hash('sha256','fixture-'.$role),gmdate('c')]);
}
touch(INSTALL_LOCK);
''')
shutil.copy(ROOT/'router.php',SITE/'test-router.php')
subprocess.run(['php',str(SITE/'seed.php')],check=True,capture_output=True)
log=open(SITE/'server.log','w')
with socket.socket() as sock:
 sock.bind(('127.0.0.1',0));PORT=sock.getsockname()[1]
server=subprocess.Popen(['php','-d','session.save_path='+str(SITE),'-S','127.0.0.1:'+str(PORT),str(SITE/'test-router.php')],cwd=SITE,stdout=log,stderr=log)
def req(path,body=None,role='admin',multipart=None):
 headers={'Authorization':'Bearer fixture-'+role}
 payload=None
 if body is not None: payload=json.dumps(body).encode();headers['Content-Type']='application/json'
 if multipart is not None: payload=multipart;headers['Content-Type']='multipart/form-data; boundary=TESTBOUNDARY'
 request=urllib.request.Request('http://127.0.0.1:'+str(PORT)+path,data=payload,headers=headers)
 try: response=urllib.request.urlopen(request)
 except urllib.error.HTTPError as e:response=e
 text=response.read().decode(errors='replace')
 try:content=json.loads(text)
 except json.JSONDecodeError:content=text
 return response.code,content
results=[]
def result(name,passed,actual):results.append({'name':name,'pass':passed,'actual':actual})
try:
 for _ in range(50):
  try:req('/api/handshake');break
  except urllib.error.URLError:time.sleep(.1)
 (SITE/'pwa').mkdir();(SITE/'pwa/index.html').write_text('fixture-pwa-shell');(SITE/'pwa/app.js').write_text('fixture-static')
 status,body=req('/pwa/part/123');result('R01 PWA deep route returns exported shell',status==200 and body=='fixture-pwa-shell',body)
 status,body=req('/pwa/app.js');result('R02 PWA physical asset is served',status==200 and body=='fixture-static',body)
 status,body=req('/data/app.sqlite');result('R03 local router denies private database',status==403,status)
 c={'action':'create','local_id':'fixture-1','boat_id':1,'name':'Filtro','reference':'REF','category_id':1,'location':'Sala','quantity':5,'notes':'original'}
 status,body=req('/api/parts/push',{'changes':[c]});
 if 'results' not in body:print(status,body,(SITE/'server.log').read_text());raise RuntimeError('Missing push results')
 pid=body['results'][0]['id']
 result('P01 valid create',status==200 and body['results'][0]['status']=='ok',{'status':status,'result':body})
 status,body=req('/api/parts/push',{'changes':[c]});result('P02 retry create is idempotent',body['results'][0]['id']==pid,body)
 status,body=req('/api/parts/push',{'changes':[{'action':'update','id':pid,'quantity':8}]},role='mechanic')
 _,sync=req('/api/sync');p=next(p for p in sync['parts'] if p['id']==pid)
 result('P03 mechanic quantity-only preserves fields',status==200 and p['name']=='Filtro' and p['notes']=='original' and p['quantity']==8,p)
 status,body=req('/api/parts/push',{'changes':[{'action':'update','id':pid,'notes':'nueva nota'}]})
 result('P04 incomplete update is rejected without SQL failure',status==200 and body['results'][0]['status']=='invalid',{'status':status,'body':body})
 status,body=req('/api/parts/push',{'changes':[{'action':'update','id':pid,'name':'Nombre nuevo','category_id':1}]})
 _,sync=req('/api/sync');p=next(p for p in sync['parts'] if p['id']==pid)
 result('P05 incomplete update preserves all fields',status==200 and body['results'][0]['status']=='invalid' and p['name']=='Filtro' and p['reference']=='REF' and p['location']=='Sala' and p['quantity']==8 and p['notes']=='original',{'status':status,'part':p})
 status,body=req('/api/parts/push',{'changes':[{**c,'local_id':'no-category','category_id':None}]})
 result('P06 missing category returns invalid without SQL error',status==200 and body['results'][0]['status']=='invalid',{'status':status,'body':body})
 status,body=req('/api/parts/push',{'changes':[{**c,'action':'update','id':pid,'quantity':11,'notes':'valid full update'}]})
 _,sync=req('/api/sync');p=next(p for p in sync['parts'] if p['id']==pid)
 result('P10 valid full update preserves other fields',body['results'][0]['status']=='ok' and p['quantity']==11 and p['notes']=='valid full update' and p['reference']=='REF',p)
 status,body=req('/api/parts/push',{'changes':[{**c,'action':'update','id':pid}]},role='mechanic')
 result('P11 mechanic cannot update all fields',body['results'][0]['status']=='forbidden',body)
 status,body=req('/api/parts/push',{'changes':[None, 'invalid', {'action':'update','id':pid,'quantity':-1}]})
 result('P12 malformed entries and negative quantity are rejected',status==200 and all(r['status']=='invalid' for r in body['results']),body)
 req('/api/parts/push',{'changes':[{'action':'delete','id':pid}]})
 _,sync=req('/api/sync');result('P07 deleted part appears as tombstone',bool(next(p for p in sync['parts'] if p['id']==pid)['deleted_at']),{'parts':sync['parts']})
 # Valid 1x1 JPEG generated with PHP GD; no actual user photograph.
 subprocess.run(['php','-r',"$im=imagecreatetruecolor(1,1); imagejpeg($im,'"+str(SITE/'fixture.jpg')+"'); imagedestroy($im);"],check=True)
 jpeg=(SITE/'fixture.jpg').read_bytes()
 multipart=b'--TESTBOUNDARY\r\nContent-Disposition: form-data; name="photo"; filename="photo.jpg"\r\nContent-Type: image/jpeg\r\n\r\n'+jpeg+b'\r\n--TESTBOUNDARY--\r\n'
 status,body=req('/api/photos/'+str(pid),multipart=multipart)
 result('P08 photo upload to deleted part is rejected',status==404,{'status':status,'body':body})
 # Remove fixture user assignments to isolate a boat with tombstones only.
 subprocess.run(['php','-r',"require 'src/config.php';require 'src/db.php';db()->exec('UPDATE users SET boat_id=NULL');"],cwd=SITE,check=True)
 status,body=req('/api/boats',{'action':'delete','id':1})
 result('P09 boat with tombstones returns explicit conflict',status==409,{'status':status,'body':body})
finally:
 server.terminate();server.wait(timeout=10);log.close()
 if any(not r['pass'] for r in results):
  print((SITE/'server.log').read_text())
 for r in results:print(('PASS ' if r['pass'] else 'FAIL ')+r['name'])
 temporary.cleanup()
 if any(not r['pass'] for r in results):raise SystemExit(1)
