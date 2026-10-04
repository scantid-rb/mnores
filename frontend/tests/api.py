import os, shutil, subprocess, urllib.request, urllib.error, urllib.parse, http.cookiejar, re, json, time, tempfile, socket
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
mkdir(BACKUP_DIR,0755,true);file_put_contents(AUTOBACKUP_LAST,(string)time());
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
 result('P09 boat with only part tombstones soft-deletes safely',status==200,{'status':status,'body':body})
 # Regression fixtures exist only in this temporary database.
 sql="""INSERT INTO boats(id,name,name_norm,registration,registration_norm,is_active,deleted_at) VALUES
 (2,'Active','active','ACT','act',1,NULL),(3,'Inactive','inactive','INA','ina',0,NULL),(4,'Deleted','deleted','DEL','del',0,'deleted');
 INSERT INTO parts(id,boat_id,name,name_norm,category_id,location,location_norm,quantity,notes) VALUES(200,4,'Historical','historical',1,'Sala','sala',2,'preserved');"""
 subprocess.run(['php','-r',"require 'src/config.php';require 'src/db.php';db()->exec("+json.dumps(sql)+");"],cwd=SITE,check=True)
 user={'action':'create','username':'new-active','first_name':'New','last_name':'User','password':'fixture-password','password2':'fixture-password','role':'mechanic','boat_id':2,'is_active':True}
 status,body=req('/api/users',user);new_user=body.get('user',{}).get('id')
 result('T01 active boat accepts user assignment',status==200 and new_user is not None,body)
 status,body=req('/api/users',{**user,'username':'new-inactive','boat_id':3})
 result('T02 inactive nondeleted user assignment retains policy',status==200,body)
 status,body=req('/api/users',{**user,'username':'new-deleted','boat_id':4})
 result('T03 manipulated API user create rejects tombstoned boat',status==422 and 'Barco no válido' in body.get('error',''),body)
 status,body=req('/api/users',{**user,'action':'update','id':new_user,'boat_id':4})
 result('T04 manipulated API user edit rejects tombstoned boat',status==422,body)
 status,body=req('/api/parts/push',{'changes':[{**c,'local_id':'active-case','boat_id':2}]})
 active_part=body['results'][0].get('id');result('T05 active boat accepts part creation',body['results'][0]['status']=='ok',body)
 status,body=req('/api/parts/push',{'changes':[{**c,'local_id':'inactive-case','boat_id':3}]})
 result('T06 inactive nondeleted API part creation retains policy',body['results'][0]['status']=='ok',body)
 status,body=req('/api/parts/push',{'changes':[{**c,'local_id':'deleted-case','boat_id':4}]})
 result('T07 API create rejects tombstoned boat',body['results'][0]['status']=='forbidden',body)
 status,body=req('/api/parts/push',{'changes':[{'action':'update','id':200,'quantity':88}]})
 result('T08 quantity update rejects historical part on tombstoned boat',body['results'][0]['status']=='forbidden',body)
 status,body=req('/api/parts/push',{'changes':[{**c,'action':'update','id':200,'boat_id':4}]})
 result('T09 full update rejects historical part on tombstoned boat',body['results'][0]['status']=='forbidden',body)
 status,body=req('/api/parts/push',{'changes':[{**c,'action':'update','id':active_part,'boat_id':4}]})
 result('T10 manipulated reassignment cannot target deleted boat',body['results'][0]['status']=='invalid',body)
 status,body=req('/api/parts/push',{'changes':[{'action':'delete','id':200}]})
 result('T23 delete cannot mutate historical inventory on deleted boat',body['results'][0]['status']=='forbidden',body)
 status,body=req('/api/photos/200',multipart=multipart)
 result('T11 API photo upload rejects tombstoned boat',status==403,body)
 status,body=req('/api/boats')
 result('T12 API catalog excludes deleted and retains inactive boats',status==200 and {b['id'] for b in body['boats']}=={2,3},body)
 # Test real HTML POST validation using a cookie session and genuine CSRF token.
 browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def web(path,form=None):
  request=urllib.request.Request('http://127.0.0.1:'+str(PORT)+path,data=urllib.parse.urlencode(form).encode() if form is not None else None)
  try: response=browser.open(request)
  except urllib.error.HTTPError as e:response=e
  return response.code,response.read().decode()
 def csrf(html):return re.search(r'name="csrf_token" value="([^"]+)"',html).group(1)
 _,html=web('/login');web('/login',{'csrf_token':csrf(html),'username':'admin','password':'fixture-password'})
 _,html=web('/users/new');result('T13 HTML user selector hides deleted boats',not re.search(r'<option value="4"[^>]*>\s*Deleted',html) and bool(re.search(r'<option value="2"[^>]*>\s*Active',html)),html[:100])
 status,html=web('/users',{**user,'username':'web-deleted','boat_id':4,'csrf_token':csrf(html)})
 result('T14 manipulated HTML user create rejects deleted boat','Barco no válido' in html,html[:100])
 _,html=web('/users/'+str(new_user)+'/edit')
 status,html=web('/users/'+str(new_user)+'/edit',{**user,'boat_id':4,'csrf_token':csrf(html)})
 result('T15 manipulated HTML user edit rejects deleted boat','Barco no válido' in html,html[:100])
 _,html=web('/parts/new');result('T16 HTML new-part selector excludes deleted boats','>Deleted<' not in html and '>Active<' in html,html[:100])
 status,html=web('/parts',{**c,'boat_id':4,'csrf_token':csrf(html),'confirm_duplicate':'1'})
 result('T17 manipulated HTML create rejects deleted boat','Barco no válido' in html,html[:100])
 _,html=web('/parts/'+str(active_part)+'/edit')
 status,html=web('/parts/'+str(active_part)+'/edit',{**c,'boat_id':4,'csrf_token':csrf(html)})
 result('T18 manipulated HTML edit rejects deleted target','Barco no válido' in html,html[:100])
 # Historical users retain assignments, but chiefs/mechanics cannot write there.
 subprocess.run(['php','-r',"require 'src/config.php';require 'src/db.php';db()->exec('UPDATE users SET boat_id=4 WHERE id IN (2,3)');"],cwd=SITE,check=True)
 for role in ['chief_engineer','mechanic']:
  status,body=req('/api/parts/push',{'changes':[{'action':'update','id':200,'quantity':99}]},role=role)
  result('T19 '+role+' cannot write tombstoned assigned boat',body['results'][0]['status']=='forbidden',body)
 status,body=req('/api/users',{**user,'username':'chief-deleted','boat_id':2},role='chief_engineer')
 result('T20 chief cannot create mechanic on historical deleted boat',status==422,body)
 _,sync=req('/api/sync');historical=next(p for p in sync['parts'] if p['id']==200)
 result('T21 rejected writes leave historical inventory untouched',historical['quantity']==2 and historical['notes']=='preserved',historical)
 _,body=req('/api/users');result('T22 historical user assignments are not rewritten',all(u['boat_id']==4 for u in body['users'] if u['id'] in [2,3]),body)

finally:
 server.terminate();server.wait(timeout=10);log.close()
 if any(not r['pass'] for r in results):
  print((SITE/'server.log').read_text())
 for r in results:print(('PASS ' if r['pass'] else 'FAIL ')+r['name'])
 temporary.cleanup()
 if any(not r['pass'] for r in results):raise SystemExit(1)
