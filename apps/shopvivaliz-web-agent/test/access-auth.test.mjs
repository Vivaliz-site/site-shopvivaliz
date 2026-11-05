import {test} from 'node:test';
import assert from 'node:assert/strict';
import {generateKeyPairSync,sign} from 'node:crypto';
import {accessConfiguration,createAccessVerifier} from '../src/access-auth.mjs';

const {privateKey,publicKey}=generateKeyPairSync('rsa',{modulusLength:2048});
const jwk={...publicKey.export({format:'jwk'}),kid:'owner-test-key',alg:'RS256',use:'sig'};
const env={WEB_AGENT_ACCESS_TEAM_DOMAIN:'vivaliz-test.cloudflareaccess.com',
  WEB_AGENT_ACCESS_AUDIENCE:'audience_identifier_test_123456789', // gitleaks:allow test fixture, not a credential
  WEB_AGENT_ACCESS_ALLOWED_EMAILS:'owner@example.com'};
const config=accessConfiguration(env);
const now=1800000000;
const fetchFn=async()=>({ok:true,json:async()=>({keys:[jwk]})});
function jwt(patch={},key=privateKey){
  const a=Buffer.from(JSON.stringify({alg:'RS256',typ:'JWT',kid:'owner-test-key'})).toString('base64url');
  const b=Buffer.from(JSON.stringify({iss:config.issuer,aud:[config.audience],email:'owner@example.com',
    sub:'owner-123',iat:now-20,exp:now+300,...patch})).toString('base64url');
  return a+'.'+b+'.'+sign('RSA-SHA256',Buffer.from(a+'.'+b),key).toString('base64url');
}
test('requires explicit locked Access configuration',()=>{
  assert.equal(accessConfiguration({}),null);
  assert.throws(()=>accessConfiguration({...env,WEB_AGENT_ACCESS_ALLOWED_EMAILS:''}),/allowlist/);
  assert.throws(()=>accessConfiguration({...env,WEB_AGENT_ACCESS_TEAM_DOMAIN:'http://localhost'}),/domain/);
  assert.throws(()=>accessConfiguration({...env,WEB_AGENT_ACCESS_AUDIENCE:'a'}),/audience/);
});
test('verifies Cloudflare Access JWT RS256, issuer, audience, email and expiry',async()=>{
  const v=createAccessVerifier(config,{fetchFn,clock:()=>now});
  assert.equal((await v(jwt())).email,'owner@example.com');
  for(const [name,claims] of Object.entries({
    bad_issuer:{iss:'https://evil.example'},bad_audience:{aud:['other']},
    unknown_identity:{email:'intruder@example.org'},expired:{exp:now-30},
    future:{nbf:now+360},iat_future:{iat:now+360}
  })){await assert.rejects(()=>v(jwt(claims)),undefined,name);}
  await assert.rejects(()=>v(jwt().slice(0,-2)+'aa'),/signature/);
  await assert.rejects(()=>v('not-a-token'),/invalid/);
});
test('prevents unsigned token and arbitrary key acceptance',async()=>{
  const v=createAccessVerifier(config,{fetchFn,clock:()=>now});
  const {privateKey:other}=generateKeyPairSync('rsa',{modulusLength:2048});
  await assert.rejects(()=>v(jwt({},other)),/signature/);
  const parts=jwt().split('.');
  parts[0]=Buffer.from(JSON.stringify({alg:'none',kid:'owner-test-key'})).toString('base64url');
  await assert.rejects(()=>v(parts.join('.')),/invalid/);
});

