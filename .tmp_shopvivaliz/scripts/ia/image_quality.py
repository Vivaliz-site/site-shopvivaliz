from PIL import Image

def avaliar_imagem(path):
    try:
        img = Image.open(path)
        w, h = img.size
        if w < 500 or h < 500:
            return "baixa"
        return "ok"
    except:
        return "erro"
