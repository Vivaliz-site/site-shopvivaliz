import {lookup as resolveDns} from 'node:dns/promises';
import {isIP} from 'node:net';

// Fail closed for private and special-use destinations. Apply network egress
// enforcement before enabling an untrusted public endpoint (DNS can rebind).
export function globalIPv4(ip) {
  if (isIP(ip) !== 4) return false;
  const a=ip.split('.').map(Number);
  const [x,y,z]=a;
  if (x === 0 || x === 10 || x === 127 || x >= 224) return false;
  if (x === 100 && y >= 64 && y <= 127) return false;
  if (x === 169 && y === 254) return false;
  if (x === 172 && y >= 16 && y <= 31) return false;
  if (x === 192 && (y === 168 || y === 0 || (y===88 && z===99))) return false;
  if (x === 198 && (y === 18 || y === 19 || (y === 51 && z === 100))) return false;
  if (x === 203 && y === 0 && z === 113) return false;
  if (x === 192 && y === 0 && z === 2) return false;
  if (x === 192 && y === 88 && z === 99) return false;
  if (x === 255) return false;
  // Reserved TEST-NET + multicast as well as historical special-use blocks.
  if (x === 192 && y === 0) return false;
  return true;
}

export function globalIp(ip) {
  const version=isIP(ip);
  if(version===4)return globalIPv4(ip);
  if(version!==6)return false;
  const parts=ip.toLowerCase().split(':');
  const first=parseInt(parts[0],16);
  if(!(first>=0x2000 && first<0x4000))return false;
  // Refuse IANA protocol-assignment space, documentation ranges and 6to4.
  if(first===0x2001){
    const second=parseInt(parts[1]||'0',16);
    if(second<0x200 || second===0xdb8)return false;
  }
  return first!==0x2002 && first!==0x3fff;
}

export async function safePublicUrl(raw, dnsLookup=resolveDns) {
  if (typeof raw !== 'string' || raw.length > 2048) throw Error('invalid_url');
  let url;
  try {url=new URL(raw);} catch {throw Error('invalid_url');}
  if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password) throw Error('unsupported_url');
  if (!['','80','443'].includes(url.port)) throw Error('port_not_allowed');
  const hostname=url.hostname.toLowerCase().replace(/^\[|\]$/g, '').replace(/\.$/, '');
  if (!hostname || hostname === 'localhost' || hostname.endsWith('.localhost') || hostname.endsWith('.local') || hostname.endsWith('.internal') || hostname.endsWith('.test')) throw Error('local_address_blocked');
  if (isIP(hostname)) {
    if (!globalIp(hostname)) throw Error('private_address_blocked');
  } else {
    let addresses;
    try {addresses=await dnsLookup(hostname, {all:true,verbatim:true});} catch {throw Error('dns_unavailable');}
    if (!Array.isArray(addresses) || !addresses.length || addresses.some(a=>!globalIp(a.address))) throw Error('private_address_blocked');
  }
  url.hash='';
  return url.toString();
}

export function sanitizedLocation(raw) {
  try {const url=new URL(raw);return url.protocol+'//'+url.host+'/';}
  catch {return '';}
}
