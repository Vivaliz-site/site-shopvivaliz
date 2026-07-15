.PHONY: help install setup cli dashboard status logs sync task clean test docs

help:
	@echo "🎮 ShopVivaliz - Comandos Disponíveis"
	@echo ""
	@echo "Setup:"
	@echo "  make install         - Instalar dependências"
	@echo "  make setup           - Setup completo do projeto"
	@echo ""
	@echo "Desenvolvimento:"
	@echo "  make cli             - Abrir CLI (shopvivaliz-cli)"
	@echo "  make dashboard       - Abrir dashboard web (porta 8888)"
	@echo "  make test            - Rodar testes"
	@echo ""
	@echo "Monitoramento:"
	@echo "  make status          - Ver status de todas estações"
	@echo "  make logs            - Ver logs em tempo real"
	@echo "  make sync            - Forçar sincronização"
	@echo ""
	@echo "MCP:"
	@echo "  make mcp-server      - Iniciar MCP Server"
	@echo "  make mcp-health      - Health check de MCP Servers"
	@echo ""
	@echo "Limpeza:"
	@echo "  make clean           - Limpar arquivos temporários"
	@echo "  make docs            - Gerar documentação"

install:
	@echo "📦 Instalando dependências..."
	pip install -r requirements.txt

setup: install
	@echo "⚙️  Configurando projeto..."
	@mkdir -p logs
	@mkdir -p .claude
	@echo "✅ Projeto configurado!"
	@make help

cli:
	@echo "🎮 Abrindo ShopVivaliz CLI..."
	python scripts/shopvivaliz-cli.py

dashboard:
	@echo "📊 Iniciando Dashboard (http://localhost:8888)..."
	python scripts/shopvivaliz-cli.py dashboard --port 8888

status:
	@python scripts/shopvivaliz-cli.py status

logs:
	@python scripts/shopvivaliz-cli.py logs all --follow

sync:
	@python scripts/shopvivaliz-cli.py sync --parallel

task:
	@python scripts/shopvivaliz-cli.py task --list

mcp-server:
	@echo "🌉 Iniciando MCP Server (porta 5555)..."
	python scripts/mcp-server.py --port 5555 --env windows-local

mcp-health:
	@python scripts/mcp-client.py --list-servers

test:
	@echo "🧪 Rodando testes..."
	pytest tests/ -v

clean:
	@echo "🧹 Limpando arquivos temporários..."
	@find . -type d -name __pycache__ -exec rm -rf {} + 2>/dev/null || true
	@find . -type f -name "*.pyc" -delete
	@rm -rf .pytest_cache/
	@echo "✅ Limpo!"

docs:
	@echo "📚 Gerando documentação..."
	@echo "Ver arquivos .md no raiz do projeto"
