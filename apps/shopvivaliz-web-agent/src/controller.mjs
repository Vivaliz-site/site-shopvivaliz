import {randomBytes,randomUUID,createHash,timingSafeEqual} from 'node:crypto';

const sha=value=>createHash('sha256').update(value).digest();
const error=name=>{throw Error(name)};
export class TakeoverController {
  constructor(driver,{now=()=>Date.now(),handoffTtlMs=15*60*1000}={}) {
    this.driver=driver;this.now=now;this.handoffTtlMs=handoffTtlMs;
    this.session=null;this.inflight=Promise.resolve();
  }
  _exclusive(task) {
    const next=this.inflight.catch(()=>{}).then(task);
    this.inflight=next.catch(()=>{});
    return next;
  }
  async open(url) {
    return this._exclusive(async()=>{
      if (this.session?.mode==='human')error('human_takeover_active');
      const page=await this.driver.open(url);
      const id=randomUUID();
      this.session={id,mode:'agent',version:0,capabilityHash:null,expiresAt:0,createdAt:this.now()};
      return {session_id:id,mode:'agent',...page};
    });
  }
  _get(id) {
    if (!this.session || this.session.id!==id)error('session_not_found');
    return this.session;
  }
  status(id) {
    const s=this._get(id);
    const expired=s.mode==='human' && this.now()>s.expiresAt;
    return {session_id:s.id,mode:s.mode,waiting_for_human:expired,version:s.version};
  }
  async inspect(id) {
    return this._exclusive(async()=>{
      const s=this._get(id);
      if(s.mode!=='agent')error('human_takeover_active');
      return {session_id:id,mode:s.mode,...await this.driver.inspect()};
    });
  }
  async click(id,selector) {
    return this._exclusive(async()=>{
      const s=this._get(id);
      if(s.mode!=='agent')error('human_takeover_active');
      return {session_id:id,mode:s.mode,...await this.driver.click(selector)};
    });
  }
  async navigate(id,url) {
    return this._exclusive(async()=>{
      const s=this._get(id);
      if(s.mode!=='agent')error('human_takeover_active');
      return {session_id:id,mode:s.mode,...await this.driver.navigate(url)};
    });
  }
  async back(id) {
    return this._exclusive(async()=>{
      const s=this._get(id);
      if(s.mode!=='agent')error('human_takeover_active');
      return {session_id:id,mode:s.mode,...await this.driver.back()};
    });
  }
  async forward(id) {
    return this._exclusive(async()=>{
      const s=this._get(id);
      if(s.mode!=='agent')error('human_takeover_active');
      return {session_id:id,mode:s.mode,...await this.driver.forward()};
    });
  }
  async scroll(id,deltaY) {
    return this._exclusive(async()=>{
      const s=this._get(id);
      if(s.mode!=='agent')error('human_takeover_active');
      return {session_id:id,mode:s.mode,...await this.driver.scroll(deltaY)};
    });
  }
  async fill(id,selector,value) {
    return this._exclusive(async()=>{
      const s=this._get(id);
      if(s.mode!=='agent')error('human_takeover_active');
      return {session_id:id,mode:s.mode,...await this.driver.fill(selector,value)};
    });
  }
  async key(id,name) {
    return this._exclusive(async()=>{
      const s=this._get(id);
      if(s.mode!=='agent')error('human_takeover_active');
      await this.driver.key(name);
      return {session_id:id,mode:s.mode,...await this.driver.snapshot()};
    });
  }
  async handoff(id) {
    return this._exclusive(async()=>{
      const s=this._get(id);
      if(s.mode==='human' && this.now()<s.expiresAt)error('handoff_already_active');
      if(s.mode!=='agent' && s.mode!=='human')error('invalid_state');
      const capability=randomBytes(32).toString('base64url');
      s.capabilityHash=sha(capability);
      s.expiresAt=this.now()+this.handoffTtlMs;
      s.mode='human';s.version++;
      const site_origin=(await this.driver.snapshot?.().catch(()=>({})))?.url||'';
      return {public:{session_id:s.id,mode:'human',message:'Controle humano ativo. O agente deve aguardar a devolucao.',version:s.version,site_origin},private:{session_id:s.id,capability}};
    });
  }
  _human(id,capability) {
    const s=this._get(id);
    if(s.mode!=='human')error('human_takeover_inactive');
    if(typeof capability!=='string' || capability.length>128)error('invalid_capability');
    const supplied=sha(capability);
    if(!s.capabilityHash || !timingSafeEqual(supplied,s.capabilityHash))error('invalid_capability');
    if(this.now()>s.expiresAt)error('handoff_expired_reissue_required');
    return s;
  }
  async humanFrame(id,capability,includeOrigin=false) {
    return this._exclusive(async()=>{
      this._human(id,capability);
      const frame=await this.driver.screenshot();
      if(!includeOrigin)return frame;
      const location=this.driver.snapshot?await this.driver.snapshot():{};
      return {frame,origin:location.url||''};
    });
  }
  async humanAction(id,capability,action,input={}) {
    return this._exclusive(async()=>{
      this._human(id,capability);
      if(action==='mouse')await this.driver.mouse(input.x,input.y);
      else if(action==='type')await this.driver.type(input.text);
      else if(action==='key')await this.driver.key(input.key);
      else if(action==='scroll')await this.driver.scroll(input.delta_y);
      else if(action==='back')await this.driver.back();
      else if(action==='forward')await this.driver.forward();
      else error('unsupported_action');
      return {ok:true};
    });
  }
  async release(id,capability) {
    return this._exclusive(async()=>{
      const s=this._human(id,capability);
      s.mode='agent';s.version++;s.capabilityHash=null;s.expiresAt=0;
      return {session_id:s.id,mode:'agent',version:s.version};
    });
  }
  async close(id) {
    return this._exclusive(async()=>{
      const s=this._get(id);
      if(s.mode==='human')error('human_takeover_active');
      this.session=null;
      await this.driver.close();
      return {closed:true};
    });
  }
}
