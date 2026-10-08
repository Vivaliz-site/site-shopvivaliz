import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {validateRequest,buildMcpCall,parseMcpInferenceResult} from '../scripts/chatgpt-browser-bridge.mjs';

const request=validateRequest({model:'gpt-5.6-sol',effort:'xhigh',profile:'dev',prompt:'Return JSON only',web_search:false});
assert.equal(request.profile,'dev');
const call=buildMcpCall(request);
assert.equal(call.method,'tools/call');
assert.equal(call.params.name,'browser_chatgpt_infer');
assert.deepEqual(call.params.arguments,{model:'gpt-5.6-sol',effort:'xhigh',prompt:'Return JSON only'});
const parsed=parseMcpInferenceResult({
  result:{content:[{type:'text',text:JSON.stringify({ok:true,text:'{}',model:'gpt-5.6-sol',effort:'xhigh',transport:'chatgpt_browser',profile:'dev'})}]}
});
assert.equal(parsed.transport,'chatgpt_browser');
assert.equal(parsed.profile,'dev');
assert.throws(()=>parseMcpInferenceResult({result:{content:[{type:'text',text:'{}'}]}}),/invalid_mcp_inference_result/);
console.log('OKX_CHATGPT_MCP_BRIDGE_CONTRACT=PASS');

const source=readFileSync(fileURLToPath(new URL('../scripts/chatgpt-browser-bridge.mjs',import.meta.url)),'utf8');
assert.equal(source.includes('serializedInference'),false,'fallback bridge must not queue stale model work');
