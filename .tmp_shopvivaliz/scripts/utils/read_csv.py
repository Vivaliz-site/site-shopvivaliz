import pandas as pd

def carregar_produtos_csv(path):
    df = pd.read_csv(path)
    produtos = []

    for _, row in df.iterrows():
        url = row.get("link imagem") or ""

        if ";" in str(url):
            url = url.split(";")[0]

        produtos.append({
            "sku": str(row.get("Código (SKU)")),
            "nome": str(row.get("Descrição") or ""),
            "descricao": str(row.get("Descrição") or ""),
            "imagem_url": url.strip()
        })

    return produtos
