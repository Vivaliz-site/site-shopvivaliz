from dataclasses import dataclass

@dataclass(frozen=True)
class AdapterConfig:
    api_key:str
    secret:str
    passphrase:str

def sanitize_error(message:str,cfg:AdapterConfig)->str:
    out=str(message)
    for secret in (cfg.api_key,cfg.secret,cfg.passphrase):
        if secret: out=out.replace(secret,'[REDACTED]')
    return out
