#!/usr/bin/env python3
"""
Otimizacao das imagens dos anuncios do Mercado Livre que ainda nao venderam.

Mesmo recorte do ml-seo-optimizer.py: sold_quantity == 0, status active,
catalog_listing == false.

O que este script faz e o que NAO faz
------------------------------------
A capa (primeira foto) e a miniatura que decide o clique na busca, e o ML a recorta
em quadrado. Uma capa larga tipo banner (ex: 1199x468) e cortada e perde metade do
produto; uma capa com texto/selo promocional viola as regras de foto do ML.

Diagnostico do acervo em 2026-08-13: 154 dos 158 anuncios tem capa abaixo de 1200px
(limiar de zoom do ML) e 11 abaixo de 500px, mas a maioria dos anuncios tem 10-12
fotos. Ou seja: quase sempre ja existe no proprio anuncio uma foto melhor que a capa
atual. Entao a otimizacao possivel via API e REORDENAR, promovendo a melhor foto a
capa.

Este script SO REORDENA (`PUT /items/{id}` com `pictures`). Nunca apaga foto: a
ordem anterior fica no relatorio e o rollback e reenviar a lista antiga. Fotos que
precisam ser refeitas (todas de baixa resolucao, ou capa com texto) entram no
relatorio para acao manual, porque isso exige material novo que a API nao tem.

A avaliacao de cada foto e feita pelo Claude olhando a imagem, e a escolha final
passa por regras deterministicas aqui (`pick_order`).

Uso:
    python3 ml-image-optimizer.py --dry-run --limit 5
    python3 ml-image-optimizer.py --apply
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from importlib import import_module

_seo = import_module("ml-seo-optimizer".replace("-", "_")) if False else None  # noqa: E501

# Reaproveita o cliente ML/env do otimizador de SEO sem depender do nome com hifen.
import importlib.util

_spec = importlib.util.spec_from_file_location(
    "ml_seo_optimizer", os.path.join(os.path.dirname(os.path.abspath(__file__)), "ml-seo-optimizer.py")
)
_seo = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_seo)

ML, load_env, log, norm = _seo.ML, _seo.load_env, _seo.log, _seo.norm
MODEL = _seo.MODEL
REPORT_DIR = os.environ.get("ML_IMG_REPORT_DIR", "/home/ubuntu/shopvivaliz-deploy/shared/logs/ml-seo")

MAX_PICS_ANALYZED = 12
GOOD_RES = 1200  # a partir daqui o ML habilita zoom
MIN_RES = 500  # abaixo disso o ML considera baixa qualidade

VISION_PROMPT = """Voce avalia fotos de anuncio do Mercado Livre Brasil para escolher qual delas deve ser a CAPA (primeira foto).

Regras de foto do Mercado Livre que importam aqui:
- A capa deve mostrar o produto inteiro, sozinho, ocupando bem o quadro, sobre fundo branco ou neutro liso.
- A capa NAO pode ter texto, selo, logo de loja, marca d'agua, moldura, borda colorida, colagem de varias fotos, preco, "frete gratis" nem qualquer arte promocional.
- A capa nao deve ser foto de ambiente/uso, montagem com varios produtos, foto de detalhe/close, tabela de medidas ou infografico. Esses tipos sao otimos como fotos SECUNDARIAS, so nao servem de capa.
- O ML recorta a miniatura em quadrado. Foto muito larga ou muito alta perde parte do produto no recorte, entao vale menos como capa.

Voce recebera as fotos de UM anuncio, na ordem atual, numeradas a partir de 0, junto com a resolucao de cada uma.

Para cada foto, classifique:
- tipo: "produto_fundo_neutro" | "produto_em_ambiente" | "detalhe" | "infografico_ou_texto" | "colagem" | "embalagem" | "outro"
- tem_texto_ou_marca_dagua: true/false
- serve_de_capa: true/false (so true para produto inteiro, sozinho, fundo neutro, sem texto)
- nota_capa: 0 a 10

Depois devolva `ordem_recomendada`: os indices de TODAS as fotos recebidas, sem repetir e sem omitir nenhuma, na ordem que voce recomenda. Coloque a melhor capa primeiro, depois as demais fotos de produto, depois detalhes e fotos de uso, e por ultimo infograficos, tabelas e colagens.

Se NENHUMA foto servir de capa, ainda assim devolva a ordem (a menos ruim primeiro) e explique em `observacao`."""

SCHEMA = {
    "type": "object",
    "properties": {
        "fotos": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "indice": {"type": "integer"},
                    "tipo": {"type": "string"},
                    "tem_texto_ou_marca_dagua": {"type": "boolean"},
                    "serve_de_capa": {"type": "boolean"},
                    "nota_capa": {"type": "integer"},
                },
                "required": [
                    "indice",
                    "tipo",
                    "tem_texto_ou_marca_dagua",
                    "serve_de_capa",
                    "nota_capa",
                ],
                "additionalProperties": False,
            },
        },
        "ordem_recomendada": {"type": "array", "items": {"type": "integer"}},
        "observacao": {"type": "string"},
    },
    "required": ["fotos", "ordem_recomendada", "observacao"],
    "additionalProperties": False,
}


def dims(pic: dict) -> tuple[int, int]:
    try:
        w, h = (pic.get("max_size") or "0x0").split("x")
        return int(w), int(h)
    except ValueError:
        return 0, 0


def squareness(w: int, h: int) -> float:
    """1.0 = quadrada. Quanto mais longe de 1, pior o recorte da miniatura."""
    if not w or not h:
        return 0.0
    return min(w, h) / max(w, h)


def analyze(client, pics: list[dict]) -> dict:
    content: list[dict] = []
    for idx, pic in enumerate(pics):
        w, h = dims(pic)
        content.append({"type": "text", "text": f"Foto {idx} — resolucao {w}x{h}"})
        content.append({"type": "image", "source": {"type": "url", "url": pic["secure_url"]}})
    content.append(
        {
            "type": "text",
            "text": f"Avalie as {len(pics)} fotos acima e devolva a ordem recomendada.",
        }
    )
    resp = client.messages.create(
        model=MODEL,
        max_tokens=8000,
        system=[{"type": "text", "text": VISION_PROMPT, "cache_control": {"type": "ephemeral"}}],
        output_config={"effort": "medium", "format": {"type": "json_schema", "schema": SCHEMA}},
        messages=[{"role": "user", "content": content}],
    )
    if resp.stop_reason == "refusal":
        raise RuntimeError("claude recusou a analise das imagens")
    return json.loads(next(b.text for b in resp.content if b.type == "text"))


def pick_order(pics: list[dict], verdict: dict) -> tuple[list[int], list[str]]:
    """Decide a ordem final. A IA opina; a regra deterministica decide a capa."""
    notes: list[str] = []
    n = len(pics)
    by_idx = {f["indice"]: f for f in verdict.get("fotos", []) if 0 <= f.get("indice", -1) < n}

    def cover_score(i: int) -> tuple:
        pic = pics[i]
        w, h = dims(pic)
        face = by_idx.get(i, {})
        eligible = bool(face.get("serve_de_capa")) and not face.get("tem_texto_ou_marca_dagua")
        return (
            1 if eligible else 0,
            1 if min(w, h) >= MIN_RES else 0,
            round(squareness(w, h), 2),
            1 if min(w, h) >= GOOD_RES else 0,
            int(face.get("nota_capa") or 0),
            min(w, h),
        )

    ranked_cover = sorted(range(n), key=cover_score, reverse=True)
    cover = ranked_cover[0]

    # Ordem sugerida pela IA, saneada: sem repetidos, sem indice invalido, completada.
    proposed = []
    for i in verdict.get("ordem_recomendada", []):
        if isinstance(i, int) and 0 <= i < n and i not in proposed:
            proposed.append(i)
    missing = [i for i in range(n) if i not in proposed]
    if missing:
        notes.append(f"IA omitiu {len(missing)} foto(s); reanexadas ao final")
    proposed += missing

    order = [cover] + [i for i in proposed if i != cover]
    if cover != proposed[0]:
        notes.append(
            f"capa da IA (foto {proposed[0]}) trocada pela foto {cover} por resolucao/proporcao"
        )
    return order, notes


def optimize_pictures(ml: ML, client, item_id: str, apply: bool) -> dict:
    out = {"id": item_id, "status": "skipped", "problems": [], "notes": []}
    status, item = ml.call("GET", f"/items/{item_id}")
    if status != 200:
        out["problems"].append(f"GET item {status}")
        return out
    if (item.get("sold_quantity") or 0) != 0 or item.get("catalog_listing"):
        out["problems"].append("fora do recorte (tem venda ou e anuncio de catalogo)")
        return out

    pics = (item.get("pictures") or [])[:MAX_PICS_ANALYZED]
    out["title"] = item.get("title")
    out["before"] = [{"id": p["id"], "max_size": p.get("max_size")} for p in pics]
    if len(pics) < 2:
        out["status"] = "sem_alternativa"
        out["problems"].append("anuncio tem menos de 2 fotos; nada a reordenar")
        return out

    w0, h0 = dims(pics[0])
    out["cover_before"] = {"max_size": pics[0].get("max_size"), "quadratura": round(squareness(w0, h0), 2)}

    try:
        verdict = analyze(client, pics)
    except Exception as exc:  # noqa: BLE001
        out["problems"].append(f"analise de imagem falhou: {exc}")
        return out

    order, notes = pick_order(pics, verdict)
    out["notes"] += notes
    out["vision"] = verdict.get("fotos")
    out["ai_observacao"] = verdict.get("observacao")

    best = pics[order[0]]
    bw, bh = dims(best)
    out["cover_after"] = {"max_size": best.get("max_size"), "quadratura": round(squareness(bw, bh), 2)}

    # Sinaliza o que a API nao resolve: acervo inteiro fraco pede foto nova.
    if max(min(*dims(p)) for p in pics) < MIN_RES:
        out["acao_manual"] = "todas as fotos abaixo de 500px; refazer o material"
    elif min(bw, bh) < GOOD_RES:
        out["acao_manual"] = f"melhor foto disponivel tem {best.get('max_size')}; ideal e 1200x1200"
    if not any(f.get("serve_de_capa") for f in verdict.get("fotos", [])):
        out["acao_manual"] = "nenhuma foto serve de capa pelas regras do ML; produzir foto em fundo branco"

    if order == list(range(len(pics))):
        out["status"] = "ja_otimizado"
        return out
    out["order"] = order
    out["changes"] = {
        "de": [p["id"] for p in pics],
        "para": [pics[i]["id"] for i in order],
    }
    if not apply:
        out["status"] = "dry-run"
        return out

    payload = {"pictures": [{"id": pics[i]["id"]} for i in order]}
    # Fotos alem do limite analisado continuam no anuncio, no fim da lista.
    for extra in (item.get("pictures") or [])[MAX_PICS_ANALYZED:]:
        payload["pictures"].append({"id": extra["id"]})
    st, resp = ml.call("PUT", f"/items/{item_id}", body=payload)
    if st not in (200, 201):
        out["problems"].append(f"PUT pictures {st}: {json.dumps(resp, ensure_ascii=False)[:300]}")
        out["status"] = "erro"
        return out
    out["status"] = "aplicado"
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--only", action="append", default=[])
    ap.add_argument("--workers", type=int, default=4)
    args = ap.parse_args()
    apply = args.apply and not args.dry_run

    import anthropic

    key = load_env("ANTHROPIC_API_KEY")
    if not key:
        log("erro: ANTHROPIC_API_KEY nao encontrada")
        return 1
    client = anthropic.Anthropic(api_key=key, max_retries=4, timeout=600.0)

    ml = ML()
    ids = args.only or [i["id"] for i in _seo.select_targets(ml)]
    if args.limit:
        ids = ids[: args.limit]
    log(f"[run] imagens: {'APLICANDO' if apply else 'DRY-RUN'} em {len(ids)} anuncios")

    done = [0]

    def work(item_id: str):
        try:
            res = optimize_pictures(ml, client, item_id, apply)
        except Exception as exc:  # noqa: BLE001
            res = {"id": item_id, "status": "erro", "problems": [repr(exc)]}
        done[0] += 1
        extra = ""
        if res.get("cover_before") and res.get("cover_after"):
            extra = f" | capa {res['cover_before']['max_size']} -> {res['cover_after']['max_size']}"
        log(f"  [{done[0]}/{len(ids)}] {item_id} {res['status']}{extra}")
        return res

    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as pool:
        results = list(pool.map(work, ids))

    os.makedirs(REPORT_DIR, exist_ok=True)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    path = os.path.join(REPORT_DIR, f"ml-imagens-{'apply' if apply else 'dryrun'}-{stamp}.json")
    with open(path, "w", encoding="utf-8") as fh:
        json.dump({"generated_at": stamp, "applied": apply, "results": results}, fh,
                  ensure_ascii=False, indent=1)

    counts: dict[str, int] = {}
    for res in results:
        counts[res["status"]] = counts.get(res["status"], 0) + 1
    manual = [r for r in results if r.get("acao_manual")]
    log("\n=== RESUMO IMAGENS ===")
    log("  " + json.dumps(counts, ensure_ascii=False))
    log(f"  anuncios que precisam de foto nova (acao manual): {len(manual)}")
    log(f"  relatorio: {path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
