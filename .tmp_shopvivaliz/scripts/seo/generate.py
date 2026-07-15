def gerar_seo(produto):
    nome = produto["nome"].lower()
    palavras = nome.split()[:6]
    titulo = " ".join(palavras).title()

    descricao = f"{titulo}\nProduto de alta qualidade."

    return {"titulo": titulo, "descricao": descricao}
