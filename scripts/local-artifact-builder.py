import os
import zipfile
from datetime import datetime
from pathlib import Path

def get_project_version():
    # Tenta ler a versao de config/shopvivaliz-version.php
    version_file = Path('config/shopvivaliz-version.php')
    if version_file.exists():
        with open(version_file, 'r', encoding='utf-8') as f:
            for line in f:
                if "'version'" in line:
                    return line.split('=>')[1].strip().replace("'", '').replace(",", '')
    return datetime.now().strftime('%Y%m%d%H%M%S') # Fallback para timestamp

def create_cumulative_zip(output_dir='artifacts'):
    project_root = Path('.').resolve()
    version = get_project_version()
    output_filename = f'shopvivaliz-cumulative-release-{version}.zip'
    output_path = Path(output_dir) / output_filename

    output_path.parent.mkdir(parents=True, exist_ok=True)

    # Lista de padrões a serem ignorados (regex)
    ignore_patterns = [
        r'\.env$',
        r'\.git/',
        r'\.github/workflows/.*\.yml$', # Excluir workflows (deploy sem autorizacao)
        r'storage/private/',
        r'logs/',
        r'node_modules/',
        r'vendor/',
        r'\.vscode/',
        r'__pycache__/',
        r'\.git-guardian\.json$',
        r'\.gitleaks\.toml$',
        r'\*.log$',
        r'\*.jsonl$',
        r'temp/',
        r'\.tmp_shopvivaliz/',
        r'ai_collaboration_report_.*\.md$', # Relatorios de IA
        r'audit-report\.txt$',
        r'deploy-test\.txt$',
        r'deploy-trigger\.txt$',
        r'install-git-auto-sync\.ps1$', # Scripts de setup
        r'setup_secrets\.py$',
        r'setup-auto-deploy\.ps1$',
        r'setup-auto-deploy\.py$',
        r'setup-github-secrets\.sh$',
        r'setup-all-secrets-complete\.sh$',
        r'setup_github_secrets\.py$',
        r'secrets-to-copy\.txt$',
        r'CUsersusersite-shopvivaliz.*$', # Arquivos de usuario
    ]

    print(f"Iniciando a criação do ZIP cumulativo: {output_path}")
    with zipfile.ZipFile(output_path, 'w', zipfile.ZIP_DEFLATED) as zipf:
        for root, dirs, files in os.walk(project_root):
            # Remover diretorios ignorados da lista de 'dirs' para nao serem percorridos
            dirs[:] = [d for d in dirs if not any(re.search(pattern, str(Path(root) / d)) for pattern in ignore_patterns)]

            for file in files:
                file_path = Path(root) / file
                relative_path = file_path.relative_to(project_root)

                # Ignorar arquivos que correspondem aos padrões
                if any(re.search(pattern, str(relative_path)) for pattern in ignore_patterns):

                    continue

                zipf.write(file_path, arcname=relative_path)



if __name__ == '__main__':
    # Adiciona um import re aqui, que é necessário para a função create_cumulative_zip
    import re
    create_cumulative_zip()