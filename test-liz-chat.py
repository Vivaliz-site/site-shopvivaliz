import urllib.request
import json

url = "https://shopvivaliz.com.br/api/agent/squad-chat.php"
payload = json.dumps({
    "message": "Olá Liz, quais são os produtos mais vendidos de rodízios e utilidades?",
    "context": "site-shopvivaliz"
}).encode('utf-8')

req = urllib.request.Request(url, data=payload, headers={'Content-Type': 'application/json'})

print("💬 Testando conversa com a Liz...")
try:
    with urllib.request.urlopen(req) as response:
        res = json.loads(response.read().decode('utf-8'))
        print("✅ Resposta recebida da Liz:")
        print(json.dumps(res, indent=2, ensure_ascii=False))
except Exception as e:
    print(f"❌ Erro ao conversar com a Liz: {e}")
