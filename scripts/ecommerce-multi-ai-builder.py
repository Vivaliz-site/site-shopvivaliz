#!/usr/bin/env python3
"""
ECOMMERCE MULTI-AI BUILDER v1.0
Orquestrador que coordena Claude, Gemini e GPT para construir páginas de e-commerce
com loop de feedback real (não simulado).

Arquitetura:
1. Gemini → Arquiteto: Define spec UX/dados/SQL
2. Claude → Desenvolvedor: Escreve PHP completo
3. GPT → QA: Revisa código, aprova ou devolve com issues
4. Se rejected: Claude reescreve (max 2x)
5. Deploy automático + commit
"""

import json
import os
import sys
from pathlib import Path
from datetime import datetime
from typing import Dict, Optional, List

# Import APIs
try:
    import google.genai
    HAS_GEMINI = True
except ImportError:
    HAS_GEMINI = False

try:
    import anthropic
    HAS_CLAUDE = True
except ImportError:
    HAS_CLAUDE = False

try:
    import openai
    HAS_OPENAI = True
except ImportError:
    HAS_OPENAI = False


class EcommerceBuilder:
    """Orquestrador Multi-IA para construção de e-commerce"""

    def __init__(self):
        self.log_dir = Path('logs')
        self.log_dir.mkdir(parents=True, exist_ok=True)

        self.build_log_file = self.log_dir / 'ecommerce-build-log.json'
        self.build_history = self._load_history()

        # API Keys
        self.gemini_key = os.getenv('GEMINI_API_KEY')
        self.claude_key = os.getenv('ANTHROPIC_API_KEY')
        self.openai_key = os.getenv('OPENAI_API_KEY')

        # Inicializar clientes
        if HAS_GEMINI and self.gemini_key:
            google.genai.configure(api_key=self.gemini_key)
            self.gemini_client = google.genai.Client(api_key=self.gemini_key)
        else:
            self.gemini_client = None

        if HAS_CLAUDE and self.claude_key:
            self.claude_client = anthropic.Anthropic(api_key=self.claude_key)
        else:
            self.claude_client = None

        if HAS_OPENAI and self.openai_key:
            self.openai_client = openai.OpenAI(api_key=self.openai_key)
        else:
            self.openai_client = None

    def _load_history(self) -> List[Dict]:
        """Carregar histórico de compilações"""
        if self.build_log_file.exists():
            try:
                with open(self.build_log_file) as f:
                    return json.load(f)
            except:
                return []
        return []

    def _save_history(self):
        """Salvar histórico de compilações"""
        with open(self.build_log_file, 'w') as f:
            json.dump(self.build_history, f, indent=2, ensure_ascii=False)

    def _log_build(self, page_name: str, status: str, details: Dict):
        """Registrar evento de compilação"""
        entry = {
            'timestamp': datetime.now().isoformat(),
            'page': page_name,
            'status': status,
            'details': details
        }
        self.build_history.append(entry)
        self._save_history()
        print(f"[LOG] {page_name}: {status}")

    # ============================================================================
    # FASE 1: GEMINI - ARQUITETO
    # ============================================================================

    def gemini_architect(self, page_config: Dict) -> Optional[Dict]:
        """
        Gemini define a spec da página (UX, estrutura de dados, queries SQL, componentes)

        Entrada: page_config = {
            'name': 'catalogo',
            'description': 'Catálogo de produtos com filtros',
            'existing_tables': ['products', 'categories'],
            'features': ['search', 'filter', 'pagination']
        }

        Saída: spec = {
            'components': [...],
            'database_queries': [...],
            'layout': {...},
            'forms': [...]
        }
        """
        if not self.gemini_client:
            print("[GEMINI] ❌ Cliente não disponível")
            return None

        page_name = page_config.get('name', 'unknown')
        print(f"\n[GEMINI] 🏗️ Arquitetura de {page_name}...")

        prompt = f"""Você é um arquiteto de UX/DB para um e-commerce PHP.

Página: {page_name}
Descrição: {page_config.get('description', '')}
Tabelas existentes: {', '.join(page_config.get('existing_tables', []))}
Funcionalidades: {', '.join(page_config.get('features', []))}

Forneça uma especificação técnica em JSON com:
1. Componentes HTML (navbar, grid, forms, etc)
2. Queries SQL para carregar dados
3. Layout responsivo (mobile/tablet/desktop)
4. Validações de formulário
5. Estados de erro/carregamento

Exemplo de resposta:
{{"
  "components": [
    {{"name": "product-card", "fields": ["image", "title", "price", "rating"]}}
  ],
  "database_queries": [
    {{"name": "get_products", "sql": "SELECT * FROM products WHERE..."}}
  ],
  "layout": {{"mobile": "1-col", "tablet": "2-col", "desktop": "3-col"}}
}}"""

        try:
            response = self.gemini_client.models.generate_content(
                model=os.getenv('GEMINI_MODEL') or 'gemini-2.5-flash',
                contents=prompt
            )

            text = response.text
            # Extrair JSON da resposta
            import re
            json_match = re.search(r'\{.*\}', text, re.DOTALL)
            if json_match:
                spec = json.loads(json_match.group())
                self._log_build(page_name, 'gemini_ok', {'spec_keys': list(spec.keys())})
                return spec
        except Exception as e:
            print(f"[GEMINI] ❌ Erro: {e}")
            self._log_build(page_name, 'gemini_error', {'error': str(e)})

        return None

    # ============================================================================
    # FASE 2: CLAUDE - DESENVOLVEDOR
    # ============================================================================

    def claude_implement(self, page_config: Dict, spec: Dict, project_context: str = '') -> Optional[str]:
        """
        Claude implementa o código PHP completo baseado na spec do Gemini

        Retorna: código PHP pronto para salvar em arquivo
        """
        if not self.claude_client:
            print("[CLAUDE] ❌ Cliente não disponível")
            return None

        page_name = page_config.get('name', 'unknown')
        print(f"\n[CLAUDE] 💻 Implementando {page_name}...")

        prompt = f"""Você é um desenvolvedor PHP sênior.

Gere um arquivo PHP completo e funcional para: {page_name}

REQUISITOS OBRIGATÓRIOS:
1. Template PADRÃO (vide abaixo)
2. <?php include __DIR__ . '/includes/navbar.php'; ?>
3. Link CSS: <link rel="stylesheet" href="/css/responsive.css">
4. Mobile-first com @media 768px e 1025px
5. Paleta: #667eea (primário), #764ba2 (secundário)
6. Usar config/database.php (MySQLi singleton)
7. Sem erros PHP, sem warnings
8. Comentários apenas no essencial

TEMPLATE BASE:
<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
define('APP_NAME', 'ShopVivaliz');
define('BASE_URL', 'https://shopvivaliz.com.br');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#667eea">
    <title><?php echo APP_NAME; ?> - Titulo</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <main class="container">
        <!-- Conteúdo -->
    </main>
    <footer>
        <div class="container">
            <p>&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
        </div>
    </footer>
</body>
</html>

SPEC DO GEMINI:
{json.dumps(spec, indent=2, ensure_ascii=False)}

Contexto do projeto:
{project_context}

Gere o código PHP completo, pronto para produção:"""

        try:
            response = self.claude_client.messages.create(
                model=os.getenv('ANTHROPIC_MODEL') or 'claude-haiku-4-5-20251001',
                max_tokens=int(os.getenv('ANTHROPIC_MAX_TOKENS') or '2000'),
                messages=[{'role': 'user', 'content': prompt}]
            )

            php_code = response.content[0].text if response.content else None
            if php_code:
                self._log_build(page_name, 'claude_ok', {'code_length': len(php_code)})
                return php_code
        except Exception as e:
            print(f"[CLAUDE] ❌ Erro: {e}")
            self._log_build(page_name, 'claude_error', {'error': str(e)})

        return None

    # ============================================================================
    # FASE 3: GPT - QA / REVISOR
    # ============================================================================

    def gpt_review(self, page_name: str, php_code: str) -> Dict:
        """
        GPT revisa o código PHP e aprova ou devolve com issues

        Retorna: {
            'status': 'approved' | 'rejected',
            'score': 0-100,
            'issues': [...],
            'suggestions': [...]
        }
        """
        if not self.openai_client:
            print("[GPT] ❌ Cliente não disponível")
            return {'status': 'unknown', 'score': 0, 'issues': ['GPT não disponível']}

        print(f"\n[GPT] 🔍 Revisando {page_name}...")

        prompt = f"""Você é um revisor de código PHP sênior.

ARQUIVO: {page_name}
CÓDIGO:
{php_code[:2000]}...

Verificar:
1. Sintaxe PHP válida?
2. Vulnerabilidades (SQL injection, XSS)?
3. Segurança: não expõe erros?
4. Usa config/database.php?
5. Responsive (mobile-first)?
6. Navbar incluída?
7. CSS correto (#667eea, #764ba2)?
8. Sem warnings PHP?

Retorne JSON:
{{
  "status": "approved" | "rejected",
  "score": <0-100>,
  "issues": ["issue1", "issue2"],
  "suggestions": ["sugestão1"]
}}"""

        try:
            response = self.openai_client.chat.completions.create(
                model=os.getenv('OPENAI_MODEL') or 'gpt-4o-mini',
                messages=[{'role': 'user', 'content': prompt}],
                max_tokens=500
            )

            text = response.choices[0].message.content
            import re
            json_match = re.search(r'\{.*\}', text, re.DOTALL)
            if json_match:
                result = json.loads(json_match.group())
                self._log_build(page_name, f'gpt_{result["status"]}',
                               {'score': result.get('score'), 'issues_count': len(result.get('issues', []))})
                return result
        except Exception as e:
            print(f"[GPT] ❌ Erro: {e}")
            self._log_build(page_name, 'gpt_error', {'error': str(e)})

        return {'status': 'error', 'score': 0, 'issues': [str(e)]}

    # ============================================================================
    # FASE 4: LOOP DE FEEDBACK
    # ============================================================================

    def build_page(self, page_config: Dict, max_iterations: int = 2) -> bool:
        """
        Orquestrador principal: Gemini → Claude → GPT → (se rejected) Claude novamente

        Retorna: True se aprovado e salvo, False se falhou
        """
        page_name = page_config.get('name', 'unknown')
        print(f"\n{'='*60}")
        print(f"🚀 CONSTRUINDO: {page_name}")
        print(f"{'='*60}")

        # FASE 1: Gemini arquiteta
        spec = self.gemini_architect(page_config)
        if not spec:
            return False

        # FASE 2-4: Loop Claude → GPT
        for iteration in range(max_iterations):
            print(f"\n--- Iteração {iteration + 1}/{max_iterations} ---")

            # Claude implementa
            php_code = self.claude_implement(page_config, spec)
            if not php_code:
                return False

            # GPT revisa
            review = self.gpt_review(page_name, php_code)

            if review['status'] == 'approved':
                # ✅ APROVADO - Salvar arquivo
                output_path = self._get_output_path(page_name)
                output_path.parent.mkdir(parents=True, exist_ok=True)

                with open(output_path, 'w', encoding='utf-8') as f:
                    f.write(php_code)

                print(f"\n✅ {page_name.upper()} APROVADO E SALVO")
                print(f"   Caminho: {output_path}")
                print(f"   Score: {review.get('score')}%")
                self._log_build(page_name, 'saved', {'path': str(output_path)})
                return True
            else:
                # ❌ REJEITADO - Tentar novamente
                print(f"\n❌ Revisão rejeitou (score: {review.get('score')}%)")
                print(f"   Issues: {review.get('issues')}")

                if iteration < max_iterations - 1:
                    print(f"   → Claude tentará corrigir...")
                else:
                    print(f"   → Max iterações atingido. Falhando.")
                    self._log_build(page_name, 'rejected', {'issues': review.get('issues')})

        return False

    def _get_output_path(self, page_name: str) -> Path:
        """Retornar caminho de output baseado no nome da página"""
        base = Path(__file__).parent.parent

        mapping = {
            'catalogo': base / 'catalogo' / 'index.php',
            'produto': base / 'produto.php',
            'carrinho': base / 'carrinho' / 'index.php',
            'checkout': base / 'checkout' / 'index.php',
            'conta': base / 'conta' / 'index.php',
            'sobre': base / 'sobre' / 'index.php',
            'contato': base / 'contato' / 'index.php',
            'faq': base / 'faq' / 'index.php',
        }

        return mapping.get(page_name, base / f'{page_name}.php')

    def build_all_pages(self) -> Dict:
        """Construir todas as páginas definidas"""
        pages = [
            {
                'name': 'catalogo',
                'description': 'Catálogo de produtos com filtros e busca',
                'existing_tables': ['products', 'categories'],
                'features': ['search', 'filter_by_category', 'pagination']
            },
            {
                'name': 'produto',
                'description': 'Página de detalhe do produto',
                'existing_tables': ['products', 'product_reviews'],
                'features': ['gallery', 'specifications', 'add_to_cart', 'reviews']
            },
            {
                'name': 'carrinho',
                'description': 'Carrinho de compras com sessão PHP',
                'existing_tables': ['products'],
                'features': ['view_items', 'edit_quantity', 'remove', 'checkout_link']
            },
        ]

        results = {}
        for page_config in pages:
            page_name = page_config['name']
            success = self.build_page(page_config)
            results[page_name] = 'success' if success else 'failed'
            print(f"\n{page_name}: {'✅' if success else '❌'}")

        return results


def main():
    """Entrada principal"""
    builder = EcommerceBuilder()

    # Verificar args
    pages_arg = 'catalogo'  # default
    if len(sys.argv) > 1:
        pages_arg = sys.argv[1].replace('--pages=', '')

    print(f"""
    ╔═══════════════════════════════════════════════════╗
    ║  🚀 ECOMMERCE MULTI-AI BUILDER v1.0              ║
    ║  Gemini (Arquiteto) + Claude (Dev) + GPT (QA)     ║
    ║  Construindo: {pages_arg:<30} ║
    ╚═══════════════════════════════════════════════════╝
    """)

    if pages_arg == 'all':
        results = builder.build_all_pages()
    else:
        page_config = {
            'name': pages_arg,
            'description': f'Página {pages_arg} do e-commerce',
            'existing_tables': ['products'],
            'features': []
        }
        success = builder.build_page(page_config)
        results = {pages_arg: 'success' if success else 'failed'}

    # Resultado final
    print(f"\n{'='*60}")
    print("RESULTADO FINAL:")
    for page, status in results.items():
        print(f"  {page}: {status}")
    print(f"{'='*60}\n")


if __name__ == '__main__':
    main()
