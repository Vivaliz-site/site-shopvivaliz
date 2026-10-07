import assert from 'node:assert/strict';
import { validateRequest, mcpResultToProviderResponse } from '../scripts/chatgpt-browser-bridge.mjs';

const valid=validateRequest({
  model:'gpt-5.6-sol',
  effort:'xhigh',
  profile:'okx',
  prompt:'Return JSON only',
  web_search:false,
});
assert.equal(valid.model,'gpt-5.6-sol');
assert.equal(valid.effort,'xhigh');
assert.equal(valid.profile,'okx');
assert.equal(valid.web_search,false);
assert.throws(()=>validateRequest({...valid,model:'gpt-5.6-terra'}),/invalid_model/);
assert.throws(()=>validateRequest({...valid,effort:'high'}),/invalid_effort/);
assert.throws(()=>validateRequest({...valid,profile:'dev'}),/invalid_profile/);
assert.throws(()=>validateRequest({...valid,web_search:true}),/web_search_disabled/);

const response=mcpResultToProviderResponse({
  result:{
    isError:false,
    structuredContent:{
      ok:true,
      text:'{"decision":"HOLD"}',
      model:'gpt-5.6-sol',
      effort:'xhigh',
      transport:'chatgpt_browser',
      profile:'okx',
    },
  },
});
assert.equal(response.ok,true);
assert.equal(response.model,'gpt-5.6-sol');
assert.equal(response.effort,'xhigh');
assert.equal(response.transport,'chatgpt_browser');
assert.equal(response.profile,'okx');
assert.throws(
  ()=>mcpResultToProviderResponse({result:{isError:true,structuredContent:{error:'dev_browser_busy'}}}),
  /dev_browser_busy/,
);
console.log('OKX_CHATGPT_BROWSER_BRIDGE_CONTRACT=PASS');
