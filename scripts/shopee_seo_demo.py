#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Demo de Otimizacao SEO para Shopee.
Mostra como os titulos sao analisados e otimizados.
"""
import sys

if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

def analyze_seo_score(title: str) -> dict:
    """Calcula score SEO para um titulo."""
    score = 100
    issues = []
    suggestions = []

    # Comprimento ideal: 30-110 caracteres
    length = len(title)
    if length < 10:
        score -= 30
        issues.append(f"Titulo muito curto ({length} chars, minimo 10)")
    elif length < 30:
        score -= 15
        issues.append(f"Titulo poderia ser mais descritivo ({length} chars)")
    elif length > 120:
        score -= 20
        issues.append(f"Titulo muito longo ({length} chars, maximo 120)")
    elif length > 110:
        score -= 5
        issues.append(f"Proximidade do limite (120 chars): {length} chars")

    # Palavras-chave de busca (importante para Shopee)
    title_lower = title.lower()
    keywords_presence = {
        "qualidade": 10,
        "novo": 8,
        "original": 8,
        "lacrado": 8,
        "premium": 7,
        "promocao": 5,
        "desconto": 5,
        "melhor": 3,
        "pronta entrega": 5,
        "frete gratis": 5,
    }

    found_keywords = []
    for kw, weight in keywords_presence.items():
        if kw in title_lower:
            found_keywords.append(kw)
            score = min(100, score + weight)

    if not found_keywords:
        suggestions.append("Adicione palavras-chave: qualidade, novo, original, lacrado")
        score -= 10

    # Especificacoes (marca, modelo, tamanho, cor)
    specs = ["mm", "cm", "polegada", "cor", "tamanho", "capacidade", "potencia", "watts"]
    has_specs = any(s in title_lower for s in specs)

    if not has_specs and length < 80:
        suggestions.append("Adicione especificacoes: tamanho, cor, capacidade")

    # Caracteres especiais desnecessarios
    special_chars = sum(1 for c in title if not c.isalnum() and c not in " /-.")
    if special_chars > 5:
        score -= 5
        suggestions.append(f"Muitos caracteres especiais ({special_chars})")

    # Palavras repetidas
    words = title.lower().split()
    if len(words) != len(set(words)):
        suggestions.append("Evite repetir palavras no titulo")
        score -= 5

    # Limpar score
    score = max(0, min(100, score))

    return {
        "score": score,
        "length": length,
        "issues": issues,
        "suggestions": suggestions,
        "keywords_found": found_keywords,
    }

def generate_improved_title(title: str, category: str = "") -> str:
    """Gera uma versao melhorada do titulo."""
    original = title.strip()

    # Se ja tem boa qualidade, nao mudar
    analysis = analyze_seo_score(original)
    if analysis["score"] >= 75:
        return original

    # Estrategias de melhoria
    improved = original

    # Adicionar palavra-chave se nao tiver
    if "qualidade" not in improved.lower() and len(improved) < 105:
        improved = improved + " - Qualidade Premium"

    if "novo" not in improved.lower() and "lacrado" not in improved.lower():
        if len(improved) < 110:
            improved = improved + " Novo"

    # Limpar espacos extras
    import re
    improved = re.sub(r"\s+", " ", improved).strip()

    # Respeitar limite
    return improved[:120].strip()

def demo():
    """Demonstracao de analise e otimizacao SEO."""
    print("=" * 75)
    print("DEMO: OTIMIZACAO SEO INTELIGENTE PARA SHOPEE")
    print("=" * 75)
    print()

    # Exemplos de titulos
    examples = [
        {
            "titulo": "Rodizio 50MM",
            "categoria": "Ferragens",
            "descricao": "Rodizio de qualidade para moveis"
        },
        {
            "titulo": "Kit Ferramentas Profissional 127 Pecas",
            "categoria": "Ferramentas",
            "descricao": "Conjunto completo de ferramentas para profissionais"
        },
        {
            "titulo": "A",
            "categoria": "Diversos",
            "descricao": "Produto incompleto"
        },
        {
            "titulo": "Produto muito longo que ultrapassa o limite maximo de caracteres permitidos pela plataforma Shopee",
            "categoria": "Teste",
            "descricao": "Titulo grande demais"
        },
        {
            "titulo": "Vasilha Plastico Organizador Cozinha 5L",
            "categoria": "Utensililios",
            "descricao": "Container para armazenar alimentos"
        },
    ]

    total_improved = 0

    for i, example in enumerate(examples, 1):
        titulo = example["titulo"]
        categoria = example["categoria"]
        descricao = example["descricao"]

        print(f"[EXEMPLO {i}]")
        print(f"  Titulo: {titulo}")
        print(f"  Categoria: {categoria}")
        print()

        # Analisar
        analysis = analyze_seo_score(titulo)
        score = analysis["score"]
        length = analysis["length"]
        issues = analysis["issues"]
        suggestions = analysis["suggestions"]
        keywords = analysis["keywords_found"]

        print(f"  ANALISE SEO:")
        print(f"    Score: {score}/100", end="")

        # Barra de progresso visual
        bar_length = 30
        filled = int(bar_length * score / 100)
        bar = "[" + "=" * filled + " " * (bar_length - filled) + "]"
        print(f" {bar}")

        print(f"    Comprimento: {length} caracteres")

        if keywords:
            print(f"    Palavras-chave encontradas: {', '.join(keywords)}")

        if issues:
            print(f"    PROBLEMAS:")
            for issue in issues:
                print(f"      ! {issue}")

        if suggestions:
            print(f"    SUGESTOES:")
            for sugg in suggestions:
                print(f"      + {sugg}")

        # Sugerir melhoria
        if score < 75:
            improved = generate_improved_title(titulo, categoria)
            print()
            print(f"  TITULO SUGERIDO:")
            print(f"    {improved}")

            improved_analysis = analyze_seo_score(improved)
            print(f"    Novo Score: {improved_analysis['score']}/100")
            total_improved += 1

        print()
        print("-" * 75)
        print()

    print(f"RESULTADO: {total_improved}/{len(examples)} titulos podem ser otimizados")
    print()
    print("PROXIMOS PASSOS:")
    print("  1. Para revisar manualmente: python scripts/shopee_seo_browser_optimizer.py")
    print("  2. Para aplicar automaticamente: Configurar SHOPEE_* e executar:")
    print("     python scripts/shopee_title_optimizer.py --method api")
    print()

if __name__ == "__main__":
    demo()
