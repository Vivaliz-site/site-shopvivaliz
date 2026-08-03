import urllib.request
import json

url = "https://shopvivaliz.com.br/api/agent/squad-chat.php"
payload = json.dumps({
    "message": "Olá Liz, quais são os rodízios mais vendidos para móveis?",
    "context": "site-shopvivaliz"
}).encode('utf-8')

headers = {
    'Content-Type': 'application/json',
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
}

req = urllib.request.Request(url, data=payload, headers=headers)

print("💬 Conversando com a Liz como cliente...")
try:
    with urllib.request.urlopen(req) as response:
        res = json.loads(response.read().decode('utf-8'))
        print("✅ Resposta recebida da Liz:")
        print(json.dumps(res, indent=2, ensure_ascii=False))
except Exception as e:
    print(f"❌ Erro ao conversar com a Liz: {e}")
