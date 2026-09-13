import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source=readFileSync(new URL('../assets/app-version.js',import.meta.url),'utf8');
async function run({windows=0,dialog=false,visible=true,path='/index.php',same=false,offline=false,dirty=false}={}) {
  let reloads=0, mutation, interval;
  const events={}, children=[];
  const document={currentScript:{dataset:{releaseUrl:'/app-release.php'}},visibilityState:visible?'visible':'hidden',
    querySelector(s){if(s.includes('vox-build'))return {content:'a'.repeat(64)};if(s.includes('vox-version'))return {content:'13096.01'};return dialog?{}:null;},
    querySelectorAll(){return Array(windows).fill({});},
    createElement(){return {setAttribute(){},isConnected:false};},
    addEventListener(name,fn){events[name]=fn;},
    body:{append(el){children.push(el);el.isConnected=true;}}
  };
  const window={};window.top=window;
  vm.runInNewContext(source,{document,window,location:{pathname:path,reload(){reloads++;}},
    MutationObserver:class{constructor(fn){mutation=fn;}observe(){}},
    setInterval(fn,ms){interval=ms;return 1;},setTimeout(){return 1;},clearTimeout(){},AbortController,
    fetch:async()=>{if(offline)throw Error();return {ok:true,redirected:false,json:async()=>({build:(same?'a':'b').repeat(64),version:'13096.02'})};}
  });
  if(dirty)events.input();
  await new Promise(resolve=>setImmediate(resolve));
  return {get reloads(){return reloads;},interval,children,close(){windows=0;dialog=false;mutation();}};
}
test('new release reloads idle desktop; checks every minute',async()=>{const r=await run();assert.equal(r.reloads,1);assert.equal(r.interval,60000);});
test('open/minimized window blocks until closed',async()=>{const r=await run({windows:1});assert.equal(r.reloads,0);r.close();assert.equal(r.reloads,1);});
test('dialog blocks until closed',async()=>{const r=await run({dialog:true});assert.equal(r.reloads,0);r.close();assert.equal(r.reloads,1);});
test('hidden tab, standalone form, changed form, unchanged release, offline do not reload',async()=>{for(const options of [{visible:false},{path:'/patient-form.php'},{dirty:true},{same:true},{offline:true}])assert.equal((await run(options)).reloads,0);});
