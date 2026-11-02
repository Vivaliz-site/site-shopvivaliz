import {createPublicKey, verify as verifySignature} from 'node:crypto';

const isPlainObject=x=>x!==null&&typeof x==='object'&&!Array.isArray(x);
const encoding=/^[A-Za-z0-9_-]+$/;
function decode(segment) {
  if(typeof segment!=='string'||segment.length<2||segment.length>8192||!encoding.test(segment)) throw Error('jwt_bad_encoding');
  return JSON.parse(Buffer.from(segment,'base64url').toString('utf8'));
}

export function accessConfiguration(env=process.env) {
  const team=String(env.WEB_AGENT_ACCESS_TEAM_DOMAIN||'').trim().toLowerCase();
  const aud=String(env.WEB_AGENT_ACCESS_AUDIENCE||'').trim();
  const allowed=String(env.WEB_AGENT_ACCESS_ALLOWED_EMAILS||'').split(',').map(x=>x.trim().toLowerCase()).filter(Boolean);
  if(!team&&!aud&&!allowed.length)return null;
  if(!/^[a-z0-9][a-z0-9-]{0,62}\.cloudflareaccess\.com$/.test(team))throw Error('access_team_domain_invalid');
  if(!/^[a-zA-Z0-9_-]{16,128}$/.test(aud))throw Error('access_audience_invalid');
  if(!allowed.length||allowed.some(x=>!/^[-\w.+]+@[-\w.]+\.[a-z]{2,}$/.test(x)))throw Error('access_email_allowlist_required');
  return Object.freeze({issuer:'https://'+team,audience:aud,allowedEmails:Object.freeze(allowed),
    certsUrl:'https://'+team+'/cdn-cgi/access/certs'});
}

// Cloudflare Access Managed OAuth sends its signed assertion in
// Cf-Access-Jwt-Assertion. A ChatGPT OAuth bearer is not this JWT.
export function createAccessVerifier(config,{fetchFn=fetch,clock=()=>Math.floor(Date.now()/1000)}={}) {
  if(!config)return async()=>{throw Error('cloudflare_access_not_configured')};
  let cache={until:0,keys:[]};
  async function keys(){
    if(clock()<cache.until&&cache.keys.length)return cache.keys;
    const res=await fetchFn(config.certsUrl,{redirect:'error',signal:AbortSignal.timeout(4000),headers:{accept:'application/json'}});
    if(!res.ok)throw Error('access_jwks_http_failure');
    const body=await res.json();
    if(!isPlainObject(body)||!Array.isArray(body.keys)||!body.keys.length||body.keys.length>50)throw Error('access_jwks_invalid');
    const accepted=body.keys.filter(k=>isPlainObject(k)&&k.kty==='RSA'&&typeof k.kid==='string'&&
      typeof k.n==='string'&&typeof k.e==='string'&&(!k.alg||k.alg==='RS256')&&(!k.use||k.use==='sig'));
    if(!accepted.length)throw Error('access_jwks_no_rsa');
    cache={until:clock()+120,keys:accepted};
    return accepted;
  }
  return async function verifyAccessJwt(token) {
    if(typeof token!=='string'||token.length<64||token.length>12000)throw Error('access_jwt_invalid');
    const segments=token.split('.');
    if(segments.length!==3||segments.some(s=>!s))throw Error('access_jwt_invalid');
    let header,payload;
    try {header=decode(segments[0]);payload=decode(segments[1]);}
    catch {throw Error('access_jwt_invalid');}
    if(!isPlainObject(header)||header.alg!=='RS256'||typeof header.kid!=='string'||!isPlainObject(payload))throw Error('access_jwt_invalid');
    const now=clock();
    if(typeof payload.exp!=='number'||!Number.isSafeInteger(payload.exp)||payload.exp<=now-20)throw Error('access_jwt_expired');
    if(typeof payload.iat!=='number'||!Number.isSafeInteger(payload.iat)||payload.iat>now+20)throw Error('access_jwt_iat_invalid');
    if(payload.nbf!==undefined&&(typeof payload.nbf!=='number'||payload.nbf>now+20))throw Error('access_jwt_not_yet_valid');
    if(payload.iss!==config.issuer)throw Error('access_jwt_issuer_invalid');
    const audiences=Array.isArray(payload.aud)?payload.aud:[payload.aud];
    if(!audiences.includes(config.audience))throw Error('access_jwt_audience_invalid');
    const email=typeof payload.email==='string'?payload.email.toLowerCase():'';
    if(!config.allowedEmails.includes(email))throw Error('access_jwt_principal_denied');
    let key=(await keys()).find(k=>k.kid===header.kid);
    if(!key){cache.until=0;key=(await keys()).find(k=>k.kid===header.kid);}
    if(!key)throw Error('access_jwt_key_not_found');
    let valid=false;
    try {valid=verifySignature('RSA-SHA256',
      Buffer.from(segments[0]+'.'+segments[1]),createPublicKey({key,format:'jwk'}),Buffer.from(segments[2],'base64url'));}
    catch {throw Error('access_jwt_key_error');}
    if(!valid)throw Error('access_jwt_signature_invalid');
    return Object.freeze({email,subject:typeof payload.sub==='string'?payload.sub:''});
  };
}

