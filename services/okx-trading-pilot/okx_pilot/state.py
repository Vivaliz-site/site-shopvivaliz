import json
import sqlite3
from decimal import Decimal
from pathlib import Path
from .domain import Mode

class PilotStateStore:
    def __init__(self,path:Path):
        self.conn=sqlite3.connect(path)
        self.conn.execute('create table if not exists kv(k text primary key,v text not null)')
        self.conn.execute('create table if not exists intents(id text primary key)')
        self.conn.execute('create table if not exists events(seq integer primary key autoincrement, kind text not null, payload text not null)')
        if self.conn.execute("select 1 from kv where k='mode'").fetchone() is None:
            self.conn.execute("insert into kv(k,v) values('mode',?)",(Mode.SHADOW.value,)); self.conn.commit()

    def close(self): self.conn.close()
    def set_mode(self,mode:Mode):
        self.conn.execute("insert into kv(k,v) values('mode',?) on conflict(k) do update set v=excluded.v",(mode.value,)); self.conn.commit()
    def get_mode(self):
        return Mode(self.conn.execute("select v from kv where k='mode'").fetchone()[0])
    def reserve_order_intent(self,intent_id:str)->bool:
        try:
            self.conn.execute('insert into intents(id) values(?)',(intent_id,)); self.conn.commit(); return True
        except sqlite3.IntegrityError:
            return False
    def promote_live(self,explicit_operator_event:bool)->bool:
        if not explicit_operator_event: return False
        self.set_mode(Mode.LIVE_PILOT); return True
    def set_baseline(self,name:str,value:str)->None:
        if name not in {'daily','pilot'}: raise ValueError('unknown baseline')
        Decimal(value)
        self.conn.execute("insert into kv(k,v) values(?,?) on conflict(k) do update set v=excluded.v",(f'baseline:{name}',str(value))); self.conn.commit()
    def get_baseline(self,name:str)->Decimal|None:
        row=self.conn.execute('select v from kv where k=?',(f'baseline:{name}',)).fetchone()
        return None if row is None else Decimal(row[0])
    def append_event(self,kind:str,payload:dict)->None:
        data=json.dumps(payload,separators=(',',':'),sort_keys=True)
        self.conn.execute('insert into events(kind,payload) values(?,?)',(kind,data)); self.conn.commit()
    def events(self):
        rows=self.conn.execute('select kind,payload from events order by seq').fetchall()
        return [{'kind':kind,'payload':json.loads(payload)} for kind,payload in rows]
