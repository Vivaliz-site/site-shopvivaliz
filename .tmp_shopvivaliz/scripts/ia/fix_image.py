from ia.generate import gerar_imagens

def corrigir_imagem(produto, erro):
    print("Corrigindo imagem...")
    produto["force_prompt"] = True
    return gerar_imagens(produto)
