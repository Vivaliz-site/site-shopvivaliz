# -*- coding: utf-8 -*-
"""
V14: Catalog Quality Engine
Enriquece fallback-products.json com categoria, slug, quality_score e tags.
"""
import json, re, unicodedata, sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).parent.parent
CATALOG_PATH = ROOT / 'api' / 'catalog' / 'fallback-products.json'


def slugify(text: str) -> str:
    nfkd = unicodedata.normalize('NFKD', text)
    s = ''.join(c for c in nfkd if not unicodedata.combining(c))
    s = s.lower()
    s = re.sub(r'[^\w\s-]', '', s)
    s = re.sub(r'[\s_]+', '-', s.strip())
    s = re.sub(r'-+', '-', s)
    return s[:80].strip('-')


def categorize(name: str) -> str:
    n = name.lower()
    if any(x in n for x in ['rodízio', 'rodizio', 'roda girat']):
        return 'Rodízios'
    if any(x in n for x in ['armário', 'armario', 'gabinete', 'gaveteiro', 'organizador']):
        return 'Armários e Organização'
    if any(x in n for x in ['caixa ferramenta', 'caixa de ferramenta', 'maleta ferramenta',
                              'bau reto', 'sanfonada', 'fercar', 'bandeja fercar']):
        return 'Caixas de Ferramentas'
    if any(x in n for x in ['alicate', 'chave de fenda', 'chave philips', 'furadeira',
                              'martelo', 'bucha', 'broca', 'prego', 'rebite', 'gedore']):
        return 'Ferramentas'
    if any(x in n for x in ['parafuso', 'porca ', 'arruela', 'fixador', 'fita perfurada',
                              'abraçadeira', 'abracadeira', 'fita met']):
        return 'Fixação e Ferragem'
    if any(x in n for x in ['assento sanit', 'caixa descarga', 'torneira', 'banheiro',
                              'ducha', 'chuveiro', 'sifão', 'vaso sanit']):
        return 'Banheiro'
    if any(x in n for x in ['cadeado', 'fechadura', 'trinco', 'ferrolho', 'haste longa', 'papaiz']):
        return 'Cadeados e Segurança'
    if any(x in n for x in ['cachepot', 'floreira', 'vaso decorat', 'jardim', 'japi']):
        return 'Floreiras e Jardim'
    if any(x in n for x in ['pet', 'coleira', 'caixa areia', 'ração', 'aquario']):
        return 'Pet'
    if any(x in n for x in ['caixa correio', 'caixa de correio', 'cor5', 'cor2']):
        return 'Caixas de Correio'
    if any(x in n for x in ['dobra', 'puxador', 'alça', 'ferragem']):
        return 'Ferragens'
    if any(x in n for x in ['cabo chupeta', 'bateria ', 'elétrico', 'eletrico',
                              'aquatools', 'volt', 'ampère']):
        return 'Elétrico e Automotivo'
    return 'Utilidades'


def extract_tags(name: str) -> list:
    tags = []
    n = name.lower()
    for mat in ['inox', 'aço', 'galvanizado', 'latão', 'plástico',
                'alumínio', 'gel', 'borracha', 'nylon', 'metal', 'cimento']:
        if mat in n:
            tags.append(mat)
    for brand in ['soprano', 'gedore', 'astra', 'fercar', 'papaiz', 'japi', 'aquatools']:
        if brand in n:
            tags.append(brand)
    if 'com freio' in n or ' freio' in n:
        tags.append('com freio')
    if 'giratório' in n or 'giratorio' in n:
        tags.append('giratório')
    if 'profissional' in n:
        tags.append('profissional')
    if re.match(r'^\d+x\s', n) or re.search(r'\bkit\b', n) or re.search(r'\bconjunto\b', n):
        tags.append('kit')
    return list(dict.fromkeys(tags))


def quality_score(p: dict) -> int:
    score = 0
    name = p.get('name', '').strip()
    if name:
        score += 20
    if len(name) > 20:
        score += 10
    if p.get('image_url', '').strip():
        score += 25
    if int(p.get('images_count', 0) or 0) > 1:
        score += 10
    if p.get('sku', '').strip():
        score += 15
    if p.get('description', '').strip():
        score += 15
    if float(p.get('price', 0) or 0) > 0:
        score += 5
    return score


def quality_label(score: int) -> str:
    if score >= 85:
        return 'excelente'
    if score >= 65:
        return 'bom'
    if score >= 40:
        return 'regular'
    return 'incompleto'


def run():
    with open(CATALOG_PATH, encoding='utf-8') as f:
        products = json.load(f)

    used_slugs = {}
    issues = []

    for p in products:
        name = p.get('name', '').strip()
        pid = str(p.get('olist_product_id') or p.get('id') or '')

        # Categoria
        p['category'] = categorize(name)

        # Slug único
        base_slug = slugify(name)
        slug = base_slug + '-' + pid if pid else base_slug
        if slug in used_slugs:
            slug = slug + '-2'
        used_slugs[slug] = True
        p['slug'] = slug

        # Score de qualidade
        score = quality_score(p)
        p['quality_score'] = score
        p['quality_label'] = quality_label(score)

        # Tags
        p['tags'] = extract_tags(name)

        # Issues
        if not p.get('description', '').strip():
            issues.append({'id': pid, 'issue': 'sem_descricao'})
        if not p.get('image_url', '').strip():
            issues.append({'id': pid, 'issue': 'sem_imagem'})

    with open(CATALOG_PATH, 'w', encoding='utf-8') as f:
        json.dump(products, f, ensure_ascii=False, separators=(',', ':'))

    # Relatório
    cats = Counter(p['category'] for p in products)
    scores = [p['quality_score'] for p in products]
    labels = Counter(p['quality_label'] for p in products)

    print(f'V14 Catalog Quality Engine - {len(products)} produtos processados')
    print(f'Score medio: {sum(scores)/len(scores):.1f}/100')
    print(f'Qualidade: {dict(labels)}')
    print(f'Issues: {len(issues)} (principalmente sem descricao)')
    print('\nCategorias:')
    for cat, count in cats.most_common():
        print(f'  {cat}: {count}')
    print('\nAmostra de slugs:')
    for p in products[:4]:
        print(f'  /produto/{p["slug"]}')

    # Salva relatório de qualidade
    report = {
        'total': len(products),
        'score_medio': round(sum(scores) / len(scores), 1),
        'qualidade': dict(labels),
        'categorias': dict(cats),
        'issues_count': len(issues),
    }
    report_path = ROOT / 'reports' / 'catalog-quality.json'
    report_path.parent.mkdir(exist_ok=True)
    with open(report_path, 'w', encoding='utf-8') as f:
        json.dump(report, f, ensure_ascii=False, indent=2)
    print(f'\nRelatorio salvo em reports/catalog-quality.json')


if __name__ == '__main__':
    run()
