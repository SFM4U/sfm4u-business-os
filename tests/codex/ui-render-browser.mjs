import {spawn} from 'node:child_process';
import {mkdtemp,readFile,writeFile,readdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
const input=resolve(process.argv[2]);
const profile=await mkdtemp(join(tmpdir(),'sfm4u-ui-chrome-'));
const child=spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',[
 '--headless','--disable-background-networking','--disable-extensions','--no-first-run',
 '--remote-debugging-address=127.0.0.1','--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'],{stdio:'ignore'});
let socket;
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
try {
 let port;
 for(let i=0;i<100;i++) {try {port=(await readFile(join(profile,'DevToolsActivePort'),'utf8')).split('\n')[0];break;}catch{await sleep(100);}}
 if(!port)throw Error('Chrome not ready');
 const targets=await(await fetch('http://127.0.0.1:'+port+'/json/list')).json();
 socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);
 await new Promise((r,j)=>{socket.onopen=r;socket.onerror=j;});
 let serial=0;const pending=new Map();
 socket.onmessage=e=>{const m=JSON.parse(e.data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
 const cdp=(method,params={})=>new Promise((resolve,reject)=>{const id=++serial;pending.set(id,{resolve,reject});socket.send(JSON.stringify({id,method,params}));});
 await cdp('Page.enable');await cdp('Network.enable');await cdp('Network.setBlockedURLs',{urls:['http://*','https://*']});
 const {frameTree}=await cdp('Page.getFrameTree');const results=[];
 for(const file of (await readdir(input)).filter(f=>/^ui-(en_US|bn_BD)-.*\.html$/.test(f)).sort()) {
  const bn=file.includes('bn_BD'); const fragment=await readFile(join(input,file),'utf8');
  for(const width of [390,1280]) {
   await cdp('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
   await cdp('Page.setDocumentContent',{frameId:frameTree.frame.id,html:`<!doctype html><html lang="${bn?'bn':'en'}"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{background:#fff;color:#111;font:14px Arial,sans-serif;margin:20px;line-height:1.5}input,textarea,select{max-width:100%;box-sizing:border-box}table{border-collapse:collapse}th,td{padding:6px;border:1px solid #aaa}label{line-height:1.8}</style><body>${fragment}</body></html>`});
   const {result}=await cdp('Runtime.evaluate',{expression:`({text:document.body.innerText.length,bn:/[\\u0980-\\u09ff]/.test(document.body.innerText),forms:document.forms.length})`,returnByValue:true});
   if(result.value.text<8 || (bn&&!result.value.bn))throw Error(file+' empty or wrong language');
   results.push({screen:file,width,...result.value});
   if(width===390 && /daily-close|vendor-products/.test(file)) {const shot=await cdp('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});await writeFile(join(input,file+'.png'),Buffer.from(shot.data,'base64'));}
  }
 }
 await writeFile(join(input,'ui-browser.json'),JSON.stringify({browser:'Chrome headless; captured local Playground HTML; external requests blocked',checks:results.length,results},null,2));
 console.log('PASS bilingual browser DOM checks: '+results.length);
} finally {socket?.close();child.kill();}
