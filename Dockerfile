FROM python:3.10-slim

WORKDIR /app

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Copiar requirements
COPY requirements.txt .

# Instalar Python dependencies
RUN pip install --no-cache-dir -r requirements.txt

# Copiar código
COPY . .

# Criar diretórios necessários
RUN mkdir -p logs reports .claude

# Healthcheck
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:5000/health || exit 1

# Variáveis de ambiente padrão
ENV PYTHONUNBUFFERED=1
ENV PYTHONIOENCODING=utf-8

# Comando padrão: Agent API
CMD ["python", "scripts/shopvivaliz_agent_api.py", "server", "5000"]
