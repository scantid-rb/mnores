const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');
const { indexedDB, IDBObjectStore } = require('fake-indexeddb');
const root = require('node:path').resolve(__dirname, '../..');
const findings = [];
function load(rel, modules) {
  const js = ts.transpileModule(fs.readFileSync(root + '/' + rel, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
  }).outputText;
  const exports = {};
  vm.runInNewContext(js, { exports, require: n => {
    if (!(n in modules)) throw Error('Unexpected dependency: ' + n);
    return modules[n];
  }, indexedDB, Blob, URL, structuredClone, console: { info() {}, warn() {}, error() {} }, setTimeout, clearTimeout }, { filename: rel });
  return exports;
}
const web = load('frontend/src/database/store.web.ts', {'@/src/utils/id': {newQueueId: () => 'q-' + Math.random()}});
const s = web.localStore;
async function record(name, fn) {
  await s.clearUserData(); await s.clearSession();
  const observation = await fn();
  findings.push({name, ...observation});
}
const input = (id, name = id) => ({local_id:id,boat_id:1,name,reference:'REF',category_id:1,location:'Sala',quantity:5,notes:'nota'});
const part = (id = 1) => ({id,boat_id:1,name:'Servidor',reference:'REF',category_id:1,location:'Sala',quantity:5,notes:'nota',photo_path:null,updated_at:'2026-09-30T12:00:00.000Z',deleted_at:null});
const data = parts => ({parts,boats:[{id:1,name:'Barco'}],categories:[{id:1,name:'General'}],users:[]});
async function seed() {await s.reconcileInventory(data([part()]), []);}
async function raw(key) {
 const db = await new Promise((resolve,reject)=>{const r=indexedDB.open('shipinventory-web',2);r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);});
 return new Promise((resolve,reject)=>{const r=db.transaction('kv').objectStore('kv').get(key);r.onsuccess=()=>resolve(r.result?.value);r.onerror=()=>reject(r.error);});
}
async function main() {
 await record('C01 create→edit offline→ACK', async()=>{
  await s.createPartLocal(input('loc-1'));await s.updatePartLocal('loc-1',{quantity:7});
  const q=await s.getPendingChanges();await s.markSyncing([q[0].queue_id]);await s.applyCreateOk(q[0].queue_id,'loc-1',1,'T1');
  return {pass:(await s.getPendingCount())===0&&(await s.getPart('loc-1')).quantity===7,queuePayload:JSON.parse(q[0].payload)};
 });
 await record('C02 create→delete offline cancels create',async()=>{await s.createPartLocal(input('loc-1'));await s.deletePartLocal('loc-1');return{pass:(await s.getPendingCount())===0&&(await s.getCounts()).parts===0};});
 await record('C03 update quantity→ACK',async()=>{await seed();await s.updatePartLocal('srv-1',{quantity:8});const q=await s.getPendingChanges();await s.applyUpdateOk(q[0].queue_id,1,'T1');return{pass:(await s.getPart('srv-1')).quantity===8&&(await s.getPendingCount())===0};});
 await record('C04 tombstone removes unprotected part',async()=>{await seed();await s.reconcileInventory(data([{...part(),deleted_at:'T1'}]),[]);return{pass:(await s.getCounts()).parts===0};});
 await record('F01 simultaneous creates retain both rows and operations',async()=>{await Promise.all([s.createPartLocal(input('loc-A')),s.createPartLocal(input('loc-B'))]);const parts=await s.searchParts({});const q=await s.getPendingChanges();return{pass:parts.length===2&&q.length===2,expected:{parts:2,queue:2},actual:{parts:parts.length,queue:q.length}};});
 await record('F02 queue abort rolls back the complete local creation',async()=>{
  const orig=IDBObjectStore.prototype.put;
  IDBObjectStore.prototype.put=function(value,...rest){if(value.key==='db.queue') {this.transaction.abort();return undefined;}return orig.call(this,value,...rest);};
  let error=null;try{await s.createPartLocal(input('orphan'));}catch(e){error=e.message;}finally{IDBObjectStore.prototype.put=orig;}
  return{pass:(await s.getCounts()).parts===0,expected:{parts:0,queue:0},actual:{parts:(await s.getCounts()).parts,queue:(await s.getPendingChanges()).length},error};
 });
 await record('F03 reconciliation preserves stable row_uid after create',async()=>{await s.createPartLocal(input('loc-1'));let q=await s.getPendingChanges();await s.applyCreateOk(q[0].queue_id,'loc-1',1,'T1');await s.reconcileInventory(data([part()]),[]);return{pass:!!(await s.getPart('loc-1')),expected:'loc-1 preserved',actual:(await s.searchParts({})).map(p=>p.row_uid)};});
 await record('F04 reconciliation retains pending local photo display',async()=>{await seed();await web.saveWebPhotoBlob('photo-A',new Blob(['A']));await s.setLocalPhoto('srv-1','photo-A');await s.reconcileInventory(data([part()]),await s.getProtectedServerIds());const p=await s.getPart('srv-1');return{pass:p.local_photo_path==='photo-A',actual:{local_photo_path:p.local_photo_path,photoQueue:(await s.getPendingPhotos()).length,blobExists:await web.hasWebPhotoBlob('photo-A')}};});
 await record('F05 old photo ACK preserves newer pending photo',async()=>{await seed();await web.saveWebPhotoBlob('photo-A',new Blob(['A']));await s.setLocalPhoto('srv-1','photo-A');const old=(await s.getPendingPhotos())[0];await s.claimPhoto(old.queue_id);await web.saveWebPhotoBlob('photo-B',new Blob(['B']));await s.setLocalPhoto('srv-1','photo-B');await s.applyPhotoOk(old.queue_id,'T1');return{pass:(await s.getPendingPhotos()).length===1&&await web.hasWebPhotoBlob('photo-B'),expected:'B remains pending with blob',actual:{queue:(await s.getPendingPhotos()).length,B:await web.hasWebPhotoBlob('photo-B')}};});
 await record('F06 failed photos excluded from automatic sending',async()=>{await seed();await web.saveWebPhotoBlob('photo-fail',new Blob(['A']));await s.setLocalPhoto('srv-1','photo-fail');const p=(await s.getPendingPhotos())[0];for(let i=0;i<5;i++)await s.markPhotoRetry(p.queue_id,'HTTP 500');return{pass:(await s.getPendingPhotos()).length===0,actual:{pendingCount:await s.getPendingCount(),returnedForSending:await s.getPendingPhotos()}};});
 await record('F07 clearUserData removes photo blobs',async()=>{await web.saveWebPhotoBlob('private-photo',new Blob(['PRIVATE']));await s.clearUserData();return{pass:!await web.hasWebPhotoBlob('private-photo'),actual:{blobRemains:await web.hasWebPhotoBlob('private-photo')}};});
 await record('F08 persisted failures remain visible on next sync',async()=>{await seed();await s.updatePartLocal('srv-1',{quantity:9});const q=await s.getPendingChanges();await s.markFailed(q[0].queue_id,'srv-1','invalid');return{pass:(await s.getFailedChanges()).length===1,actual:{pendingCount:await s.getPendingCount(),pendingChanges:await s.getPendingChanges(),protectedIds:await s.getProtectedServerIds(),persistedQueue:await raw('db.queue')}};});
 await record('F09 send reads current payload of unsent entries',async()=>{
  await s.saveSession({id:1,username:'chief',role:'chief_engineer',boat_id:1});
  await s.createPartLocal(input('loc-A'));await s.createPartLocal(input('loc-B'));
  const server=[];let call=0;
  class ApiError extends Error{}
  const engine=load('frontend/src/services/sync/syncEngine.ts',{
   '@/src/database/store':{localStore:s},'@/src/services/api/client':{ApiError},
   '@/src/services/photos/photoService':{photoExists:async()=>true,uploadPartPhoto:async()=>({updated_at:'T2'})},
   '@/src/services/api/endpoints':{
    apiPush:async(_token,changes)=>{call++;const c=changes[0];if(call===1)await s.updatePartLocal('loc-B',{quantity:99});const id=call;server.push({...part(id),...c,id});return{ok:true,results:[{action:'create',local_id:c.local_id,id,status:'ok',updated_at:'T1'}]};},
    apiGetSync:async()=>({ok:true,server_time:'T2',parts:server}),apiGetBoats:async()=>data([]).boats,apiGetCategories:async()=>data([]).categories,apiGetUsers:async()=>[]
   }
  });
  await engine.runSync('token');
  return{pass:server.find(p=>p.local_id==='loc-B').quantity===99,expected:{serverQuantity:99},actual:{serverQuantity:server.find(p=>p.local_id==='loc-B').quantity,localQuantity:(await s.getPart('loc-B')).quantity,queue:await s.getPendingChanges()}};
 });
 await record('20 simultaneous creates are all durable', async()=>{
  await Promise.all(Array.from({length:20},(_,i)=>s.createPartLocal(input('parallel-'+i))));
  return {pass:(await s.getCounts()).parts===20&&(await s.getPendingCount())===20};
 });
 await record('edit during an uncertain create survives retry and ACK',async()=>{
  await s.createPartLocal(input('uncertain'));
  const original=(await s.getPendingChanges())[0];await s.claimChange(original.queue_id);
  await s.revertSyncing([original.queue_id]);await s.updatePartLocal('uncertain',{quantity:42});
  const retried=await s.claimChange(original.queue_id);await s.applyCreateOk(original.queue_id,'uncertain',88,'T1');
  const remaining=await s.getPendingChanges();const next=await s.claimChange(remaining[0].queue_id);
  return{pass:JSON.parse(retried.payload).quantity===5&&next.entity_id===88&&JSON.parse(next.payload).quantity===42};
 });
 await record('delete during create is deferred until ACK',async()=>{
  await s.createPartLocal(input('delete-flight'));const create=(await s.getPendingChanges())[0];await s.claimChange(create.queue_id);
  await s.deletePartLocal('delete-flight');await s.applyCreateOk(create.queue_id,'delete-flight',77,'T1');
  const pending=await s.getPendingChanges();const deletion=await s.claimChange(pending[0].queue_id);
  await s.applyDeleteOk(deletion.queue_id,77);return{pass:deletion.action==='delete'&&deletion.entity_id===77&&(await s.getCounts()).parts===0};
 });
 await record('restart recovers an uploading photo',async()=>{
  await seed();await web.saveWebPhotoBlob('recovered',new Blob(['test']));await s.setLocalPhoto('srv-1','recovered');
  const photo=(await s.getPendingPhotos())[0];await s.claimPhoto(photo.queue_id);await s.init();
  return{pass:(await s.getPendingPhotos())[0].status==='pending'};
 });
 await record('discard resets cursor and allows authoritative full refresh',async()=>{
  await seed();await s.saveSession({id:1,username:'chief',role:'chief_engineer',boat_id:1});await s.setLastSyncAt('old');
  await s.updatePartLocal('srv-1',{quantity:99});const change=(await s.getPendingChanges())[0];await s.markFailed(change.queue_id,'srv-1','invalid');
  await s.discardFailed();const reset=(await s.getSession()).last_sync_at===null;
  await s.reconcileAndSetCursor(data([part()]),'new');return{pass:reset&&(await s.getPart('srv-1')).quantity===5&&(await s.getSession()).last_sync_at==='new'&&(await s.getFailedChanges()).length===0};
 });
 await record('definitively invalid create can be corrected and sent',async()=>{
  await s.createPartLocal(input('correct'));const first=(await s.getPendingChanges())[0];await s.claimChange(first.queue_id);
  await s.updatePartLocal('correct',{quantity:8});await s.markFailed(first.queue_id,'correct','invalid');
  await s.updatePartLocal('correct',{quantity:12});const queue=await s.getPendingChanges();
  return{pass:queue.length===1&&queue[0].action==='create'&&queue[0].status==='pending'&&JSON.parse(queue[0].payload).quantity===12};
 });
 await record('retry of uncertain create preserves immutable attempted payload',async()=>{
  await s.createPartLocal(input('uncertain'));const first=(await s.getPendingChanges())[0];await s.claimChange(first.queue_id);
  await s.markFailed(first.queue_id,'uncertain','timeout');await s.retryFailed();await s.updatePartLocal('uncertain',{quantity:19});
  const queue=await s.getPendingChanges();return{pass:queue.length===2&&JSON.parse(queue.find(e=>e.action==='create').payload).quantity===5};
 });
 await record('delete ACK removes failed operations and queued photo blobs',async()=>{
  await seed();await s.updatePartLocal('srv-1',{quantity:8});const first=(await s.getPendingChanges())[0];await s.markFailed(first.queue_id,'srv-1','invalid');
  await web.saveWebPhotoBlob('deleted-photo',new Blob(['a']));await s.setLocalPhoto('srv-1','deleted-photo');
  await s.applyDeleteOk('delete',1);return{pass:(await s.getFailedChanges()).length===0&&(await s.getPendingPhotos()).length===0&&!(await web.hasWebPhotoBlob('deleted-photo'))};
 });
 await record('discard of failed create removes dependent edits and photos',async()=>{
  await s.createPartLocal(input('discard-create'));const first=(await s.getPendingChanges())[0];await s.claimChange(first.queue_id);
  await s.updatePartLocal('discard-create',{quantity:19});await s.markFailed(first.queue_id,'discard-create','invalid');
  await web.saveWebPhotoBlob('orphan',new Blob(['a']));await s.setLocalPhoto('discard-create','orphan');await s.discardFailed();
  return{pass:(await s.getCounts()).parts===0&&(await s.getPendingCount())===0&&(await s.getPendingPhotos()).length===0&&!(await web.hasWebPhotoBlob('orphan'))};
 });
 await record('profile identity update preserves inventory, cursor, queues and photo blobs',async()=>{
  await seed();await s.saveSession({id:1,username:'before',role:'chief_engineer',boat_id:1});await s.setLastSyncAt('cursor-before');
  await s.updatePartLocal('srv-1',{quantity:13});await web.saveWebPhotoBlob('identity-photo',new Blob(['photo']));await s.setLocalPhoto('srv-1','identity-photo');
  const before=JSON.stringify({parts:await s.searchParts({}),changes:await s.getPendingChanges(),photos:await s.getPendingPhotos()});
  await s.updateSessionIdentity({id:1,username:'after',role:'chief_engineer',boat_id:1});
  const session=await s.getSession();
  const after=JSON.stringify({parts:await s.searchParts({}),changes:await s.getPendingChanges(),photos:await s.getPendingPhotos()});
  await s.updateSessionIdentity({id:99,username:'other',role:'admin',boat_id:null});
  return{pass:session.username==='after'&&session.last_sync_at==='cursor-before'&&before===after&&await web.hasWebPhotoBlob('identity-photo')&&(await s.getSession()).id===1};
 });
 await record('forbidden create removes row, dependent edits, photos and blobs through sync',async()=>{
  await s.saveSession({id:1,username:'chief',role:'chief_engineer',boat_id:1});
  await s.createPartLocal(input('forbidden'));const first=(await s.getPendingChanges())[0];await s.claimChange(first.queue_id);
  await s.updatePartLocal('forbidden',{quantity:19});await s.revertSyncing([first.queue_id]);
  await web.saveWebPhotoBlob('forbidden-photo',new Blob(['photo']));await s.setLocalPhoto('forbidden','forbidden-photo');
  await s.createPartLocal(input('retained'));
  const engine=load('frontend/src/services/sync/syncEngine.ts',{
   '@/src/database/store':{localStore:s},'@/src/services/api/client':{ApiError:class extends Error{}},
   '@/src/services/photos/photoService':{photoExists:async()=>true},
   '@/src/services/api/endpoints':{
    apiPush:async(_token,[change])=>({ok:true,results:[{action:'create',local_id:change.local_id,status:change.local_id==='forbidden'?'forbidden':'invalid'}]}),
    apiGetSync:async()=>({ok:true,server_time:'T1',parts:[]}),apiGetBoats:async()=>[],apiGetCategories:async()=>[],apiGetUsers:async()=>[]
   }
  });
  await engine.runSync('token');await s.init();
  return{pass:!(await s.getPart('forbidden'))&&!!(await s.getPart('retained'))&&
   (await raw('db.queue')).every(e=>e.row_uid!=='forbidden')&&(await raw('db.photo.queue')).length===0&&
   !(await web.hasWebPhotoBlob('forbidden-photo'))&&(await s.getFailedChanges()).length===1};
 });
 await record('forbidden update and recoverable create failures retain local work',async()=>{
  await seed();await s.updatePartLocal('srv-1',{quantity:14});let q=await s.getPendingChanges();await s.markFailed(q[0].queue_id,'srv-1','forbidden');
  await s.createPartLocal(input('recoverable'));await web.saveWebPhotoBlob('recoverable-photo',new Blob(['photo']));await s.setLocalPhoto('recoverable','recoverable-photo');
  q=await s.getPendingChanges();await s.markRetry(q[0].queue_id,'HTTP 500');
  return{pass:(await s.getPart('srv-1')).quantity===14&&!!(await s.getPart('recoverable'))&&
   (await s.getFailedChanges()).length===1&&(await s.getPendingChanges()).length===1&&await web.hasWebPhotoBlob('recoverable-photo')};
 });
 await record('forbidden cleanup rolls back atomically on IndexedDB abort',async()=>{
  await s.createPartLocal(input('rollback'));await web.saveWebPhotoBlob('rollback-photo',new Blob(['photo']));await s.setLocalPhoto('rollback','rollback-photo');
  const q=(await s.getPendingChanges())[0];const orig=IDBObjectStore.prototype.put;let rejected=false;
  IDBObjectStore.prototype.put=function(value,...rest){if(value.key==='db.queue'){this.transaction.abort();return undefined;}return orig.call(this,value,...rest);};
  try{await s.markFailed(q.queue_id,'rollback','forbidden');}catch{rejected=true;}finally{IDBObjectStore.prototype.put=orig;}
  return{pass:rejected&&!!(await s.getPart('rollback'))&&(await s.getPendingChanges()).length===1&&await web.hasWebPhotoBlob('rollback-photo')&&(await s.getPendingPhotos()).length===1};
 });
 if (findings.some((result) => !result.pass)) process.exitCode = 1;
 for (const result of findings) console.log(`${result.pass ? 'PASS' : 'FAIL'} ${result.name}`);
}
main().catch(e=>{console.error(e);process.exitCode=1;});
