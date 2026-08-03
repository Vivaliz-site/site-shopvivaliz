#!/usr/bin/env python3
"""Generate a deterministic inventory and hygiene report from the Git index."""

from __future__ import annotations

import argparse
import collections
import re
import subprocess
from pathlib import Path


AREA_BY_ROOT = {
    ".github": "Governanca e integracao continua",
    "admin": "Administracao autenticada",
    "agent-bridge": "Ponte de agentes",
    "agents": "Agentes e instrucoes operacionais",
    "api": "Endpoints HTTP e webhooks",
    "assets": "Assets de frontend",
    "auth": "Autenticacao e contas",
    "automacoes": "Automacoes em portugues",
    "automation": "Automacao",
    "automations": "Automacao",
    "blog": "Blog publico e publicacao",
    "catalogo": "Catalogo publico",
    "checkout-v2": "Checkout ativo v2",
    "checkout-legacy": "Checkout legado",
    "carrinho-legacy": "Carrinho legado",
    "config": "Configuracao e seguranca",
    "core": "Nucleo da aplicacao",
    "css": "Estilos",
    "database": "Banco de dados",
    "deploy": "Deploy e runtime",
    "docs": "Documentacao",
    "erp": "Integracoes ERP",
    "includes": "Bibliotecas PHP compartilhadas",
    "js": "Scripts de frontend",
    "migrations": "Migracoes de banco",
    "olist": "Integracao Olist/Tiny",
    "public": "Arquivos publicos",
    "public_html": "Document root compativel",
    "scripts": "Ferramentas e rotinas de manutencao",
    "tests": "Testes",
    "tools": "Ferramentas de desenvolvimento",
    "utils": "Utilitarios",
    "web": "Aplicacao web complementar",
}

TOP_LEVEL_APP_PURPOSE = {
    "404.php": "Pagina publica de erro 404",
    "500.php": "Pagina publica de erro 500",
    "blog.php": "Pagina publica do blog",
    "carrinho.php": "Pagina publica do carrinho",
    "catalogo.php": "Pagina publica do catalogo",
    "checkout.php": "Pagina publica do checkout",
    "checkout-return.php": "Retorno publico do checkout",
    "contato.php": "Pagina publica de contato",
    "google-ads-api-app.php": "Pagina publica da integracao Google Ads",
    "google-merchant-feed.php": "Feed publico do Google Merchant",
    "home.php": "Vitrine publica alternativa",
    "index.php": "Vitrine principal do site",
    "login.php": "Pagina de autenticacao",
    "minha-conta.php": "Area autenticada do cliente",
    "meus-pedidos.php": "Historico autenticado de pedidos",
    "politica-privacidade.php": "Pagina publica de privacidade",
    "politica-devolucoes.php": "Pagina publica da politica de devolucoes",
    "politica-entrega.php": "Pagina publica da politica de entrega",
    "produto.php": "Pagina publica de produto",
    "sitemap.php": "Sitemap XML publico",
    "termos.php": "Pagina publica de termos",
}

DOC_EXTENSIONS = {".md", ".mdx", ".txt"}
SOURCE_EXTENSIONS = {".php", ".js", ".mjs", ".cjs", ".ts", ".tsx", ".jsx"}
CODE_EXTENSIONS = SOURCE_EXTENSIONS | {".py", ".sh", ".ps1"}
GENERATED_OUTPUTS = {
    "docs/audits/repository-hygiene.md",
    "docs/knowledge/repository-file-index.md",
}
PROTECTED_PATTERNS = (
    re.compile(r"^storage/orders/"),
    re.compile(r"^storage/codex-bridge/state\.json$"),
    re.compile(r"^storage/orchestrator/queue\.json$"),
    re.compile(r"^\.agent-heartbeats/"),
    re.compile(r"^\.git-sync\.lock$"),
)
HYGIENE_PATH = re.compile(
    r"(^|/)(scratch|tmp-gh-email-artifact|\.tmp_shopvivaliz|logs|uploads|dist)(/|$)",
    re.IGNORECASE,
)
LEGACY_PATH = re.compile(r"(^|/)(carrinho-legacy|checkout-legacy)(/|$)", re.IGNORECASE)
TEST_PATH = re.compile(r"(^|/)(tests?|specs?)(/|$)|(^|[-_.])(test|spec)([-_.]|$)", re.IGNORECASE)
SECRET_PATTERNS = (
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{20,}", re.IGNORECASE),
    re.compile(r"github_pat_[A-Za-z0-9_]{40,}", re.IGNORECASE),
    re.compile(r"(?<![A-Za-z0-9_-])sk-(?:proj-)?[A-Za-z0-9_-]{24,}(?![A-Za-z0-9_-])"),
    re.compile(r"xox[baprs]-[A-Za-z0-9-]{20,}", re.IGNORECASE),
    re.compile(r"eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}", re.IGNORECASE),
)
FUNCTION_PATTERN = re.compile(r"\bfunction\s+&?\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\(")


def git(repo: Path, *args: str, check: bool = True) -> bytes:
    completed = subprocess.run(
        ["git", "-C", str(repo), *args],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if check and completed.returncode != 0:
        message = completed.stderr.decode("utf-8", "replace").strip()
        raise RuntimeError(f"git {' '.join(args)} failed ({completed.returncode}): {message}")
    return completed.stdout


def tracked_entries(repo: Path) -> list[tuple[str, str]]:
    raw = git(repo, "ls-files", "-s", "-z")
    entries: list[tuple[str, str]] = []
    for record in raw.split(b"\0"):
        if not record:
            continue
        try:
            header, raw_path = record.split(b"\t", 1)
            _mode, blob_id, _stage = header.split(b" ", 2)
        except ValueError as exc:
            raise RuntimeError(f"unexpected git index record: {record!r}") from exc
        entries.append((blob_id.decode("ascii"), raw_path.decode("utf-8")))
    return sorted(entries, key=lambda item: item[1])


def markdown_code(value: str) -> str:
    longest = max((len(match.group(0)) for match in re.finditer(r"`+", value)), default=0)
    delimiter = "`" * (longest + 1)
    padding = " " if value.startswith("`") or value.endswith("`") else ""
    return f"{delimiter}{padding}{value}{padding}{delimiter}"


def safe_path(path: str) -> str:
    if path.startswith("storage/orders/"):
        return "storage/orders/<REDACTED_ORDER_ID>.json"
    safe = path
    for pattern in SECRET_PATTERNS:
        safe = pattern.sub("<REDACTED_SECRET>", safe)
    return safe


def area_for(path: str) -> str:
    if "/" not in path and path in TOP_LEVEL_APP_PURPOSE:
        return "Aplicacao web publica"
    return AREA_BY_ROOT.get(path.split("/", 1)[0], "Suporte, configuracao ou artefato de projeto")


def file_purpose(path: str) -> str:
    leaf = Path(path).name
    extension = Path(leaf).suffix.lower()
    if "/" not in path and path in TOP_LEVEL_APP_PURPOSE:
        return f"Aplicacao web publica - {TOP_LEVEL_APP_PURPOSE[path]}."

    is_test = bool(TEST_PATH.search(path))
    if is_test:
        role = "Teste automatizado"
    elif extension in DOC_EXTENSIONS:
        role = "Documentacao ou instrucao"
    elif extension == ".php":
        role = "Codigo PHP"
    elif extension in {".js", ".mjs", ".cjs", ".ts", ".tsx", ".jsx"}:
        role = "Codigo JavaScript/TypeScript"
    elif extension in {".css", ".scss", ".sass"}:
        role = "Estilo visual"
    elif extension in {".yml", ".yaml"}:
        role = "Configuracao YAML/CI"
    elif extension == ".json":
        role = "Configuracao ou dados JSON"
    elif extension == ".sql":
        role = "Schema, migracao ou consulta SQL"
    elif extension in {".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".ico"}:
        role = "Imagem ou icone"
    elif extension in {".csv", ".xlsx", ".ods"}:
        role = "Planilha ou dados tabulares"
    elif extension in {".sh", ".ps1", ".py"}:
        role = "Script operacional"
    else:
        role = "Artefato de suporte"

    if not is_test and extension not in DOC_EXTENSIONS and extension in SOURCE_EXTENSIONS:
        if re.search(r"webhook|callback", path, re.IGNORECASE):
            role = "Endpoint de callback ou webhook"
        elif re.search(r"migration|migrate", path, re.IGNORECASE):
            role = "Migracao de banco de dados"
    if re.search(r"readme|changelog|contributing", leaf, re.IGNORECASE):
        role = "Documentacao de projeto"
    return f"{area_for(path)} - {role}."


def is_protected(path: str) -> bool:
    return any(pattern.search(path) for pattern in PROTECTED_PATTERNS)


def classification(path: str) -> str:
    if is_protected(path):
        return "violacao de politica - dado operacional nao deve ser versionado"
    if LEGACY_PATH.search(path):
        return "legado declarado - revisar rota e telemetria antes de remover"
    if HYGIENE_PATH.search(path):
        return "artefato/candidato a higiene - validar uso e politica de retencao"
    return "ativo ou ainda nao classificado como legado"


def directories(paths: list[str]) -> list[str]:
    result: set[str] = set()
    for path in paths:
        parts = path.split("/")
        for index in range(1, len(parts)):
            result.add("/".join(parts[:index]))
    return sorted(result)


def parse_null_delimited_grep(output: bytes) -> list[tuple[str, str]]:
    """Parse `git grep -n -z` without confusing source text with filenames."""
    records: list[tuple[str, str]] = []
    cursor = 0
    while cursor < len(output):
        path_end = output.find(b"\0", cursor)
        line_end = output.find(b"\0", path_end + 1)
        content_end = output.find(b"\n", line_end + 1)
        if path_end < 0 or line_end < 0:
            raise RuntimeError("unexpected NUL-delimited git grep output")
        if content_end < 0:
            content_end = len(output)
        raw_path = output[cursor:path_end]
        raw_line_number = output[path_end + 1 : line_end]
        if not raw_line_number.isdigit():
            raise RuntimeError(f"unexpected git grep line number: {raw_line_number!r}")
        records.append(
            (
                raw_path.decode("utf-8"),
                output[line_end + 1 : content_end].decode("utf-8", "replace"),
            )
        )
        cursor = content_end + 1
    return records


def repeated_function_names(repo: Path) -> dict[str, list[str]]:
    names: dict[str, set[str]] = collections.defaultdict(set)
    output = git(
        repo,
        "grep",
        "--cached",
        "-I",
        "-n",
        "-z",
        "-E",
        r"function[[:space:]]+&?[[:space:]]*[A-Za-z_$][A-Za-z0-9_$]*[[:space:]]*\(",
        "--",
        "*.php",
        "*.js",
        "*.mjs",
        "*.cjs",
        "*.ts",
        "*.tsx",
        "*.jsx",
        check=False,
    )
    for path, source_line in parse_null_delimited_grep(output):
        for match in FUNCTION_PATTERN.finditer(source_line):
            names[match.group(1)].add(path)
    return {
        name: sorted(found_paths)
        for name, found_paths in sorted(names.items(), key=lambda item: item[0].lower())
        if len(found_paths) > 1
    }


def render(repo: Path) -> tuple[str, str]:
    entries = tracked_entries(repo)
    paths = [path for _blob, path in entries]
    directory_list = directories(paths)
    groups: dict[str, list[str]] = collections.defaultdict(list)
    for blob_id, path in entries:
        if path in GENERATED_OUTPUTS:
            continue
        groups[blob_id].append(path)
    duplicate_groups = sorted(
        ((blob_id, sorted(group_paths)) for blob_id, group_paths in groups.items() if len(group_paths) > 1),
        key=lambda item: (-len(item[1]), item[0]),
    )
    code_duplicate_groups = [
        (blob_id, group_paths)
        for blob_id, group_paths in duplicate_groups
        if any(Path(path).suffix.lower() in CODE_EXTENSIONS for path in group_paths)
    ]
    repeated_functions = repeated_function_names(repo)
    protected = [path for path in paths if is_protected(path)]
    review_candidates = [path for path in paths if classification(path) != "ativo ou ainda nao classificado como legado"]

    index_lines = [
        "# Indice completo do repositorio",
        "",
        (
            f"Gerado deterministicamente a partir de {len(entries)} arquivos versionados no indice Git. "
            "O inventario nao le nem replica valores de segredos; a funcao e inferida por caminho, extensao e convencoes do repositorio."
        ),
        "",
        "## Diretorios",
        "",
        "| Diretorio | Funcao predominante |",
        "|---|---|",
    ]
    for directory in directory_list:
        index_lines.append(f"| {markdown_code(directory)} | {area_for(directory)} |")
    index_lines.extend(["", "## Arquivos", "", "| Arquivo | Funcao | Classificacao |", "|---|---|---|"])
    for path in paths:
        displayed = safe_path(path).replace("|", r"\|")
        index_lines.append(
            f"| {markdown_code(displayed)} | {file_purpose(path)} | {classification(path)} |"
        )

    hygiene_lines = [
        "# Relatorio de higiene do repositorio",
        "",
        "Gerado deterministicamente do indice Git. Este relatorio aponta candidatos; nao autoriza exclusao automatica.",
        "",
        "## Resumo",
        "",
        f"- Arquivos versionados: {len(entries)}",
        f"- Diretorios versionados: {len(directory_list)}",
        f"- Grupos de conteudo identico: {len(duplicate_groups)}",
        f"- Grupos identicos que incluem codigo: {len(code_duplicate_groups)}",
        f"- Nomes de funcao encontrados em mais de um arquivo: {len(repeated_functions)}",
        f"- Arquivos em diretorios explicitamente legados: {sum(bool(LEGACY_PATH.search(path)) for path in paths)}",
        f"- Violacoes de dados operacionais versionados: {len(protected)}",
        "",
        "## Violacoes de politica",
        "",
    ]
    if protected:
        displayed_counts = collections.Counter(safe_path(path) for path in protected)
        for displayed, count in sorted(displayed_counts.items()):
            suffix = f" ({count} arquivos)" if count > 1 else ""
            hygiene_lines.append(f"- {markdown_code(displayed)}{suffix} - {classification(displayed)}")
    else:
        hygiene_lines.append("Nenhum dado operacional proibido esta versionado.")

    hygiene_lines.extend(["", "## Arquivos identicos", ""])
    if not duplicate_groups:
        hygiene_lines.append("Nenhum grupo de arquivos identicos foi encontrado.")
    for blob_id, group_paths in duplicate_groups:
        hygiene_lines.append(f"### Git blob ID {markdown_code(blob_id)}")
        for displayed in sorted(set(safe_path(path) for path in group_paths)):
            hygiene_lines.append(f"- {markdown_code(displayed)}")
        hygiene_lines.append("")

    hygiene_lines.extend(["## Funcoes declaradas em mais de um arquivo", ""])
    hygiene_lines.append(
        "Varredura sintatica reproduzivel de declaracoes nomeadas `function` em PHP/JavaScript/TypeScript. "
        "Nomes repetidos nao provam duplicacao semantica; metodos e escopos podem ser independentes."
    )
    hygiene_lines.append("")
    if not repeated_functions:
        hygiene_lines.append("Nenhum nome de funcao foi encontrado em mais de um arquivo.")
    for name, function_paths in repeated_functions.items():
        rendered_paths = ", ".join(markdown_code(safe_path(path)) for path in function_paths)
        hygiene_lines.append(f"- {markdown_code(name)}: {rendered_paths}")

    hygiene_lines.extend(["", "## Candidatos a revisao manual", ""])
    if not review_candidates:
        hygiene_lines.append("Nenhum candidato foi encontrado.")
    displayed_candidates: set[tuple[str, str]] = set()
    for path in review_candidates:
        displayed_candidates.add((safe_path(path), classification(path)))
    for displayed, candidate_classification in sorted(displayed_candidates):
        hygiene_lines.append(f"- {markdown_code(displayed)} - {candidate_classification}")

    hygiene_lines.extend(["", "## Pastas paralelas", ""])
    for name in ("automacoes", "automation", "automations"):
        count = sum(path.startswith(f"{name}/") for path in paths)
        hygiene_lines.append(
            f"- {markdown_code(name + '/')}: {count} arquivos. Comparar chamadas e fluxos antes de consolidar."
        )

    return "\n".join(index_lines) + "\n", "\n".join(hygiene_lines) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repository-root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--check", action="store_true", help="fail if committed outputs are stale")
    args = parser.parse_args()
    repo = args.repository_root.resolve()
    index, hygiene = render(repo)
    outputs = {
        repo / "docs/knowledge/repository-file-index.md": index,
        repo / "docs/audits/repository-hygiene.md": hygiene,
    }
    stale = [path for path, content in outputs.items() if not path.exists() or path.read_text("utf-8") != content]
    if args.check:
        if stale:
            print("stale generated files:")
            for path in stale:
                print(f"- {path.relative_to(repo)}")
            return 1
        print(f"repository inventory is current: {len(tracked_entries(repo))} tracked files")
        return 0
    for path, content in outputs.items():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8", newline="\n")
    print(f"generated {len(outputs)} reports from {len(tracked_entries(repo))} tracked files")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
