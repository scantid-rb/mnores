const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const path=require('node:path');const root=path.resolve(__dirname,'..');const ts=require('typescript');
const source=ts.transpileModule(fs.readFileSync(root+'/src/services/sync/connectivity.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText;
function mount(platform,initialOnline){
 let state,effect,initialized=false,cleanup,nativeListener,unsubscribed=false,fetches=0,subscriptions=0;
 const handlers=new Map(),nav={onLine:initialOnline};
 const net={fetch:async()=>{fetches++;return{isConnected:true,isInternetReachable:null};},addEventListener:fn=>{subscriptions++;nativeListener=fn;return()=>{unsubscribed=true;};}};
 const exports={};const modules={'@react-native-community/netinfo':{__esModule:true,default:net},react:{useState:initial=>{if(!initialized){state=initial;initialized=true;}return[state,value=>{state=value;}];},useEffect:fn=>{effect??=fn;}},'react-native':{Platform:{OS:platform}}};
 vm.runInNewContext(source,{exports,require:n=>modules[n],navigator:nav,window:{addEventListener:(event,fn)=>handlers.set(event,fn),removeEventListener:(event,fn)=>{assert.equal(handlers.get(event),fn);handlers.delete(event);}}});
 const render=()=>exports.useConnectivity();const initial=render();cleanup=effect();return{initial,render,handlers,nav,cleanup,netEvent:state=>nativeListener(state),counts:()=>({fetches,subscriptions,unsubscribed})};
}
(async()=>{
 const web=mount('web',false);assert.equal(web.initial.online,false);assert.equal(web.render().isInternetReachable,false);assert.equal(web.handlers.size,2);assert.equal(web.counts().fetches,0);assert.equal(web.counts().subscriptions,0);
 web.nav.onLine=true;web.handlers.get('online')();assert.equal(web.render().online,true);web.nav.onLine=false;web.handlers.get('offline')();assert.equal(web.render().online,false);web.cleanup();assert.equal(web.handlers.size,0);console.log('PASS web initial offline, online/offline transitions, listener cleanup and no NetInfo calls');
 const online=mount('web',true);assert.equal(online.initial.online,true);online.cleanup();console.log('PASS web initial online allows login connectivity gate');
 for(const platform of ['android','ios']){const native=mount(platform,false);await Promise.resolve();assert.equal(native.handlers.size,0);assert.equal(native.counts().fetches,1);assert.equal(native.counts().subscriptions,1);assert.equal(native.render().online,true);native.netEvent({isConnected:true,isInternetReachable:false});assert.equal(native.render().online,false);native.netEvent({isConnected:false,isInternetReachable:null});assert.equal(native.render().online,false);native.netEvent({isConnected:true,isInternetReachable:null});assert.equal(native.render().online,true);native.cleanup();assert.equal(native.counts().unsubscribed,true);console.log('PASS '+platform+' NetInfo fetch/events, unknown reachability and unsubscribe');}
})().catch(e=>{console.error(e);process.exitCode=1});
