"""
Validação de qualidade de imagem IA.
Detecta: imagem em branco, dimensões insuficientes, arquivo corrompido.
"""
from pathlib import Path

try:
    from PIL import Image
    _PIL_AVAILABLE = True
except ImportError:
    _PIL_AVAILABLE = False


MIN_WIDTH = 800
MIN_HEIGHT = 800
MIN_FILE_SIZE = 20_000  # 20 KB — imagem muito pequena indica falha


def is_valid(image_path: Path | str) -> tuple[bool, str]:
    """
    Retorna (ok, reason).
    ok=True: imagem passou em todas as verificações.
    """
    path = Path(image_path)

    if not path.exists():
        return False, "arquivo não existe"

    size = path.stat().st_size
    if size < MIN_FILE_SIZE:
        return False, f"arquivo muito pequeno ({size} bytes)"

    if not _PIL_AVAILABLE:
        return True, "ok (PIL não disponível, validação básica)"

    try:
        with Image.open(path) as img:
            img.verify()
    except Exception as e:
        return False, f"imagem corrompida: {e}"

    try:
        with Image.open(path) as img:
            w, h = img.size
            if w < MIN_WIDTH or h < MIN_HEIGHT:
                return False, f"dimensões insuficientes ({w}x{h})"

            # Detectar imagem quase toda branca (geração falhou)
            if img.mode != "RGB":
                img = img.convert("RGB")
            sample = img.resize((50, 50))
            pixels = list(sample.getdata())
            white_count = sum(1 for r, g, b in pixels if r > 240 and g > 240 and b > 240)
            white_ratio = white_count / len(pixels)
            if white_ratio > 0.97:
                return False, f"imagem praticamente branca ({white_ratio:.0%})"
    except Exception as e:
        return False, f"erro ao ler imagem: {e}"

    return True, "ok"


def validate_batch(images: dict[str, Path | None]) -> dict[str, tuple[bool, str]]:
    """Valida todas as imagens de um produto. images = {tipo: path}"""
    return {
        img_type: is_valid(path) if path else (False, "não gerada")
        for img_type, path in images.items()
    }


def needs_regeneration(validation: dict[str, tuple[bool, str]]) -> list[str]:
    """Retorna lista dos tipos que precisam ser regerados."""
    return [t for t, (ok, _) in validation.items() if not ok]
