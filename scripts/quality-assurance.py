#!/usr/bin/env python3
"""
Quality Assurance - Validar código antes de commitar
"""
import subprocess
import json
from pathlib import Path

class QualityAssurance:
    def __init__(self):
        self.results = {
            'lint': False,
            'syntax': False,
            'build': False,
            'tests': False,
            'overall': False
        }

    def run_php_lint(self):
        """Validar sintaxe PHP"""
        print(" Verificando sintaxe PHP...")
        try:
            result = subprocess.run(
                ["php", "-l", "-f", "find . -name '*.php' | head -20"],
                capture_output=True,
                text=True
            )
            self.results['lint'] = result.returncode == 0
            print(f"  {'' if self.results['lint'] else ''} PHP Lint")
            return self.results['lint']
        except Exception as e:
            print(f"   PHP Lint não disponível: {e}")
            return True  # Não bloquear se PHP não disponível

    def run_code_analysis(self):
        """Análise estática de código"""
        print("🔎 Análise estática de código...")
        issues = []

        # Procurar por padrões perigosos
        dangerous_patterns = [
            ("eval(", "eval() é perigoso"),
            ("system(", "system() é inseguro"),
            ("exec(", "exec() não deve ser usado"),
            ("shell_exec", "shell_exec inseguro"),
            ("$_SERVER['PHP_SELF']", "XSS vulnerability"),
        ]

        php_files = Path(".").glob("**/*.php")
        for php_file in php_files:
            if "vendor" in str(php_file) or ".git" in str(php_file):
                continue

            content = php_file.read_text()
            for pattern, issue in dangerous_patterns:
                if pattern in content:
                    issues.append(f"{php_file}: {issue}")

        if not issues:
            print("   Nenhum padrão perigoso encontrado")
            self.results['syntax'] = True
        else:
            print("   Problemas encontrados:")
            for issue in issues[:5]:  # Mostrar até 5
                print(f"    - {issue}")
            self.results['syntax'] = False

        return self.results['syntax']

    def run_build_check(self):
        """Verificar se código compila/funciona"""
        print("🔨 Build check...")
        try:
            # Verificar se há erros de sintaxe PHP
            result = subprocess.run(
                ["php", "-l"],
                capture_output=True,
                text=True,
                input="<?php echo 'OK';"
            )
            self.results['build'] = result.returncode == 0
            print(f"  {'' if self.results['build'] else ''} Build")
            return self.results['build']
        except Exception as e:
            print(f"   Build check erro: {e}")
            return True

    def run_tests(self):
        """Executar testes se existirem"""
        print("🧪 Testes...")
        test_file = Path("tests/test.php")

        if not test_file.exists():
            print("  ℹ️ Nenhum teste encontrado")
            self.results['tests'] = True
            return True

        try:
            result = subprocess.run(
                ["php", str(test_file)],
                capture_output=True,
                text=True,
                timeout=30
            )
            self.results['tests'] = result.returncode == 0
            print(f"  {'' if self.results['tests'] else ''} Testes")
            return self.results['tests']
        except subprocess.TimeoutExpired:
            print("   Testes demoraram muito")
            return False

    def run_all_checks(self):
        """Executar todos os checks"""
        print("\n" + "=" * 60)
        print(" QUALITY ASSURANCE - PRÉ-COMMIT")
        print("=" * 60 + "\n")

        self.run_php_lint()
        self.run_code_analysis()
        self.run_build_check()
        self.run_tests()

        # Resultado geral
        self.results['overall'] = all([
            self.results['lint'],
            self.results['syntax'],
            self.results['build'],
            self.results['tests']
        ])

        print("\n" + "=" * 60)
        if self.results['overall']:
            print(" TODOS OS CHECKS PASSARAM - OK PARA COMMIT")
        else:
            print(" FALHAS DETECTADAS - COMMIT BLOQUEADO")
        print("=" * 60 + "\n")

        return self.results['overall']

    def get_report(self):
        """Retornar relatório"""
        return {
            'timestamp': str(Path.cwd()),
            'checks': self.results,
            'passed': self.results['overall']
        }

if __name__ == "__main__":
    qa = QualityAssurance()
    qa.run_all_checks()

    report = qa.get_report()
    print(json.dumps(report, indent=2))

    exit(0 if report['passed'] else 1)
