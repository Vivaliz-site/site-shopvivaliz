import os
from pathlib import Path

from PIL import Image
import torch

_PIPELINE = None


def _safe_name(value):
    return "".join(c if c.isalnum() or c in ("-", "_") else "_" for c in str(value))


def _get_device():
    return "cuda" if torch.cuda.is_available() else "cpu"


def _load_pipeline():
    global _PIPELINE
    if _PIPELINE is not None:
        return _PIPELINE

    model_id = os.getenv("LOCAL_IMAGE_MODEL", "runwayml/stable-diffusion-v1-5")
    device = _get_device()
    dtype = torch.float16 if device == "cuda" else torch.float32

    from diffusers import StableDiffusionImg2ImgPipeline

    pipe = StableDiffusionImg2ImgPipeline.from_pretrained(
        model_id,
        torch_dtype=dtype,
        safety_checker=None,
        requires_safety_checker=False,
    )
    pipe = pipe.to(device)
    if device == "cpu":
        pipe.enable_attention_slicing()
    else:
        pipe.enable_xformers_memory_efficient_attention() if hasattr(pipe, "enable_xformers_memory_efficient_attention") else None

    _PIPELINE = pipe
    return _PIPELINE


def _variant_prompts(produto):
    nome = str(produto.get("nome", "")).strip()
    marca = str(produto.get("marca", "")).strip()
    categoria = str(produto.get("categoria", "")).strip()
    cor = str(produto.get("cor", "")).strip()

    base = f"same exact product photo of {nome}"
    context = []
    if marca:
        context.append(f"brand {marca}")
    if categoria:
        context.append(f"category {categoria}")
    if cor:
        context.append(f"exact original color {cor}")
    context_txt = ", ".join(context)

    prefix = (
        f"{base}, preserve exact shape, dimensions, lid, hinges, edges and color, "
        f"do not change the product itself, only the background and environment, "
        f"keep the same product identity, no redesign, no morphology changes"
    )
    if context_txt:
        prefix = f"{prefix}, {context_txt}"

    return [
        ("studio", f"{prefix}, pure white seamless studio sweep, centered product, soft shadow, minimal commercial catalog"),
        ("hero", f"{prefix}, dark charcoal premium hero background, dramatic rim light, elegant marketplace presentation"),
        ("lifestyle", f"{prefix}, realistic home improvement counter scene, warm neutral tones, contextual but discreet background"),
        ("detail", f"{prefix}, macro close-up on hinge and contour, crisp product detail, shallow depth of field"),
        ("catalog", f"{prefix}, light gray catalog background, floating product isolation, clean e-commerce main image"),
        ("premium", f"{prefix}, sophisticated gradient backdrop, premium ad photography, subtle reflections, upscale retail look"),
    ]


def gerar_imagens(produto):
    base_img_path = produto["imagem_original"]
    if not base_img_path or not Path(base_img_path).exists():
        raise RuntimeError("imagem_original não encontrada")

    out_dir = Path("storage/ia_images")
    out_dir.mkdir(parents=True, exist_ok=True)

    seed = int(os.getenv("LOCAL_IMAGE_SEED", "42"))
    steps = int(os.getenv("LOCAL_IMAGE_STEPS", "18"))
    strength = float(os.getenv("LOCAL_IMAGE_STRENGTH", "0.22"))
    guidance = float(os.getenv("LOCAL_IMAGE_GUIDANCE", "7.0"))
    size = int(os.getenv("LOCAL_IMAGE_SIZE", "768"))

    pipe = _load_pipeline()
    source = Image.open(base_img_path).convert("RGB").resize((size, size))

    style_settings = {
        "studio": {"strength": 0.18, "guidance": 6.5, "steps": 20},
        "hero": {"strength": 0.26, "guidance": 7.5, "steps": 24},
        "lifestyle": {"strength": 0.30, "guidance": 8.0, "steps": 24},
        "detail": {"strength": 0.20, "guidance": 6.8, "steps": 22},
        "catalog": {"strength": 0.16, "guidance": 6.2, "steps": 20},
        "premium": {"strength": 0.28, "guidance": 7.8, "steps": 26},
    }
    negative = (
        "do not change product shape, do not change lid shape, do not change hinge position, "
        "do not change color, no extra parts, no extra product, no duplicate item, no text, no watermark, "
        "no carton, no packaging, no new object, no distortion, no blur"
    )

    results = []
    for idx, (label, prompt) in enumerate(_variant_prompts(produto), start=1):
        settings = style_settings.get(label, {})
        generator = torch.Generator(device=_get_device()).manual_seed(seed + idx)
        image = pipe(
            prompt=prompt,
            negative_prompt=negative,
            image=source,
            strength=float(settings.get("strength", strength)),
            guidance_scale=float(settings.get("guidance", guidance)),
            num_inference_steps=int(settings.get("steps", steps)),
            generator=generator,
        ).images[0]

        filename = f"{_safe_name(produto.get('sku', 'item'))}_{idx}_{label}.jpg"
        out_path = out_dir / filename
        image.save(out_path, quality=95)
        results.append(str(out_path))

    return results
