#!/usr/bin/env python3
import argparse, base64, hashlib, json, os, subprocess, time, urllib.parse, urllib.request

TENANT='cc55b801-12c2-4ea2-a930-d639aa8988a4'
CLIENT='a5e400f0-969e-4fbe-be61-d390cb112517'
SENDER='naoresponda@dev.shopvivaliz.com.br'
CERT='/home/ubuntu/.shopvivaliz/m365/graph-auth.crt'
KEY='/home/ubuntu/.shopvivaliz/m365/graph-auth.key'

def b64u(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).rstrip(b'=').decode()

def token():
    der=subprocess.check_output(['openssl','x509','-in',CERT,'-outform','DER'])
    x5t=b64u(hashlib.sha1(der).digest())
    now=int(time.time())
    header=b64u(json.dumps({'alg':'RS256','typ':'JWT','x5t':x5t},separators=(',',':')).encode())
    payload=b64u(json.dumps({'aud':f'https://login.microsoftonline.com/{TENANT}/oauth2/v2.0/token','iss':CLIENT,'sub':CLIENT,'jti':os.urandom(16).hex(),'nbf':now-60,'exp':now+540},separators=(',',':')).encode())
    unsigned=f'{header}.{payload}'.encode()
    sig=subprocess.check_output(['openssl','dgst','-sha256','-sign',KEY],input=unsigned)
    assertion=unsigned.decode()+'.'+b64u(sig)
    body=urllib.parse.urlencode({'client_id':CLIENT,'scope':'https://graph.microsoft.com/.default','grant_type':'client_credentials','client_assertion_type':'urn:ietf:params:oauth:client-assertion-type:jwt-bearer','client_assertion':assertion}).encode()
    req=urllib.request.Request(f'https://login.microsoftonline.com/{TENANT}/oauth2/v2.0/token',data=body,headers={'Content-Type':'application/x-www-form-urlencoded'})
    data=json.loads(urllib.request.urlopen(req,timeout=30).read())
    claims=json.loads(base64.urlsafe_b64decode(data['access_token'].split('.')[1]+'==='))
    return data['access_token'], claims

def send_test(to_addr):
    access, claims=token()
    msg={'message':{'subject':'Teste Graph app-only servidor ShopVivaliz','body':{'contentType':'HTML','content':'<p>Teste de autenticação Microsoft Graph app-only executado diretamente no servidor ShopVivaliz.</p>'},'toRecipients':[{'emailAddress':{'address':to_addr}}]},'saveToSentItems':True}
    req=urllib.request.Request(f'https://graph.microsoft.com/v1.0/users/{urllib.parse.quote(SENDER)}/sendMail',data=json.dumps(msg).encode(),headers={'Authorization':'Bearer '+access,'Content-Type':'application/json'},method='POST')
    with urllib.request.urlopen(req,timeout=30) as r:
        return {'status':r.status,'roles':claims.get('roles',[])}

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--send-test-to'); args=ap.parse_args()
    access, claims=token()
    result={'status':'success','aud':claims.get('aud'),'roles':claims.get('roles',[]),'mail_send':('Mail.Send' in claims.get('roles',[]))}
    if args.send_test_to:
        result['send_test']=send_test(args.send_test_to)
    print(json.dumps(result,ensure_ascii=False))
if __name__=='__main__': main()
