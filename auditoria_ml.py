import os
import requests

# 1. CARREGAR CONFIGURAÇÕES DO AMBIENTE
# Estes nomes correspondem exatamente aos Secrets que você listou no GitHub
CLIENT_ID = os.getenv("ML_CLIENT_ID")
CLIENT_SECRET = os.getenv("ML_CLIENT_SECRET")
REFRESH_TOKEN = os.getenv("ML_REFRESH_TOKEN")

def renovar_token():
    if not all([CLIENT_ID, CLIENT_SECRET, REFRESH_TOKEN]):
        print("ERRO: Variáveis de ambiente (ML_CLIENT_ID, ML_CLIENT_SECRET, ML_REFRESH_TOKEN) não configuradas.")
        return None
    
    url = "https://api.mercadolibre.com/oauth/token"
    payload = {
        'grant_type': 'refresh_token',
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET,
        'refresh_token': REFRESH_TOKEN
    }
    
    response = requests.post(url, data=payload)
    
    if response.status_code == 200:
        dados = response.json()
        print("Token renovado com sucesso!")
        return dados.get('access_token')
    else:
        print(f"Erro ao renovar token: {response.status_code} - {response.text}")
        return None

# 2. FLUXO PRINCIPAL
access_token = renovar_token()

if access_token:
    headers = {"Authorization": f"Bearer {access_token}"}
    base_url = "https://api.mercadolibre.com/users/me/items/search"

    for offset in range(0, 600, 100):
        print(f"Buscando lote com offset {offset}...")
        try:
            response = requests.get(f"{base_url}?limit=100&offset={offset}", headers=headers)
            
            if response.status_code == 200:
                items = response.json().get('results', [])
                print(f"Processado: {len(items)} itens encontrados.")
                # Lógica de auditoria/atualização vai aqui
            else:
                print(f"Erro no lote {offset}: Status {response.status_code} - {response.text}")
                break # Para o loop em caso de erro crítico
        except Exception as e:
            print(f"Erro de conexão no lote {offset}: {e}")
            break
else:
    print("Falha na autenticação. Verifique se o ML_REFRESH_TOKEN está correto e não expirado.")