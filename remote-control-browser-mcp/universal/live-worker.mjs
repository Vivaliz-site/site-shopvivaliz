import { BrowserSession } from './live-browser.mjs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const moduleRoot=dirname(fileURLToPath(import.meta.url));
const root=process.env.SHOPVIVALIZ_BROWSER_UNIVERSAL_DATA_DIR||moduleRoot;
const profileDir=process.env.SHOPVIVALIZ_BROWSER_UNIVERSAL_PROFILE_DIR||resolve(root,'isolated-profile');
const session=new BrowserSession({root,profileDir});
let chain=Promise.resolve();

function safeError(error){
  return String(error?.message||'browser_error').split('\n')[0].replace(/https?:\/\/\S+/gi,'[url]').slice(0,240);
}

process.on('message', message=>{
  chain=chain.then(async ()=>{
    if(!Number.isSafeInteger(message?.id)||typeof message?.action!=='string')return;
    try {
      const result=await session.execute(message.action,message.args||{});
      process.send?.({id:message.id,result});
    }catch(e){
      process.send?.({id:message.id,error:safeError(e)});
      if(/Target closed|Browser closed|browser has been closed/i.test(e?.message||'')){
        await session.close().catch(()=>{});
        process.exit(2);
      }
    }
  }).catch(()=>{});
});

const exit=()=>{session.close().catch(()=>{}).finally(()=>process.exit(0));};
process.on('SIGTERM',exit);
process.on('disconnect',exit);
