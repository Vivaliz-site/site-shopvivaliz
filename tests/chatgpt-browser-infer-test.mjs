import assert from 'node:assert/strict';
import {
  validateInferenceRequest,
  isExtraHighLabel,
  isSolResolvedModel,
  chooseExtraHighCandidate,
} from '../scripts/chatgpt-continuity/chatgpt-browser-infer.mjs';

const valid=validateInferenceRequest({model:'gpt-5.6-sol',effort:'xhigh',prompt:'Return JSON'});
assert.equal(valid.model,'gpt-5.6-sol');
assert.equal(valid.effort,'xhigh');
assert.equal(valid.prompt,'Return JSON');
assert.throws(()=>validateInferenceRequest({...valid,model:'gpt-5.6-terra'}),/invalid_model/);
assert.throws(()=>validateInferenceRequest({...valid,effort:'high'}),/invalid_effort/);
assert.equal(isExtraHighLabel('Extra High'),true);
assert.equal(isExtraHighLabel('Extra alto'),true);
assert.equal(isExtraHighLabel('Medium'),false);
assert.equal(isSolResolvedModel('gpt-5-6-thinking'),true);
assert.equal(isSolResolvedModel('gpt-5-6-sol'),true);
assert.equal(isSolResolvedModel('gpt-5-6-luna'),false);
assert.equal(chooseExtraHighCandidate([
  {label:'High',visible:true,disabled:false,index:0},
  {label:'Extra High',visible:true,disabled:false,index:1},
]).index,1);
console.log('CHATGPT_BROWSER_INFER_CONTRACT=PASS');
