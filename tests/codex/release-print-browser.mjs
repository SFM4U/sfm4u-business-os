import {spawn} from 'node:child_process';
import {mkdtemp,readFile,writeFile,mkdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
const input=resolve(process.argv[2]),output=resolve(process.argv[3]);await mkdir(output,{recursive:true});
const profile=await mkdtemp(join(tmpdir(),'sfm4u-print-'));
const child=spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',[
 '--headless','--disable-background-networking','--disable-extensions','--no-first-run',
 '--remote-debugging-address=127.0.0.1','--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'],{stdio:'ignore'});
let socket;const sleep=ms=>new Promise(r=>setTimeout(r,ms));
try{
 let port;for(let i=0;i<100;i++){try{port=(await readFile(join(profile,'DevToolsActivePort'),'utf8')).split('\n')[0];break;}catch{await sleep(100);}}
 if(!port)throw Error('Chrome not ready');
 const targets=await(await fetch('http://127.0.0.1:'+port+'/json/list')).json();
 socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);await new Promise((r,j)=>{socket.onopen=r;socket.onerror=j;});
 let id=0;const pending=new Map();socket.onmessage=e=>{const m=JSON.parse(e.data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
 const cdp=(method,params={})=>new Promise((resolve,reject)=>{const key=++id;pending.set(key,{resolve,reject});socket.send(JSON.stringify({id:key,method,params}));});
 await cdp('Page.enable');await cdp('Network.enable');await cdp('Network.setBlockedURLs',{urls:['http://*','https://*']});
 const {frameTree}=await cdp('Page.getFrameTree');
 await cdp('Page.setDocumentContent',{frameId:frameTree.frame.id,html:await readFile(input,'utf8')});
 await cdp('Runtime.evaluate',{expression:'document.fonts.ready.then(()=>true)',awaitPromise:true});
 const pdf=await cdp('Page.printToPDF',{printBackground:true,preferCSSPageSize:true,displayHeaderFooter:false,scale:1});
 await writeFile(join(output,'print.pdf'),Buffer.from(pdf.data,'base64'));
 console.log('PASS local browser PDF; external requests blocked');
}finally{socket?.close();child.kill();}
