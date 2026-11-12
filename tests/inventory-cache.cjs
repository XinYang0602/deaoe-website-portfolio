const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('src/service/inventory.js','utf8');
const chunk=source.slice(source.indexOf('async function call(a)'),source.indexOf('function show(html)'));
let now=20000,reads=0,posts=0,renders=0,fail=false,release=null;
const context={Date:{now:()=>now},data:null,window:{addEventListener(){}},document:{addEventListener(){}},$:()=>({open:false}),toast(){},render(){renders++},fetch:async(url,options)=>{
 if(options.method){posts++;return {ok:true,json:async()=>({})};}
 reads++;if(release)await new Promise(resolve=>{release.resolve=resolve;});
 return {ok:!fail,status:503,json:async()=>({csrf:'synthetic',version:reads})};
}};
vm.createContext(context);vm.runInContext(chunk,context);
(async()=>{
 await context.load();assert.equal(reads,1);
 await context.load({maxAge:15000});assert.equal(reads,1);
 now+=15001;await context.load({maxAge:15000});assert.equal(reads,2);
 await context.call({action:'synthetic'});await context.load({maxAge:15000});assert.equal(reads,3);assert.equal(posts,1);
 await context.load();assert.equal(reads,4,'explicit refresh bypasses TTL');
 release={};const a=context.load(),b=context.load();assert.equal(reads,5,'in-flight reads are shared');release.resolve();release=null;await Promise.all([a,b]);
 now+=16000;fail=true;await assert.rejects(context.load({maxAge:15000}));fail=false;await context.load({maxAge:15000});assert.equal(reads,7,'failed refresh is retried');
 release={};const stale=context.load();await context.call({action:'synthetic'});release.resolve();release=null;await stale;assert.equal(reads,9,'read overlapping mutation must be fetched again');
 assert.equal(context.data.version,9);console.log('PASS snapshot TTL, forced refresh, mutation invalidation, request deduplication, failure retry and write/read race');
})().catch(e=>{console.error(e);process.exitCode=1});
