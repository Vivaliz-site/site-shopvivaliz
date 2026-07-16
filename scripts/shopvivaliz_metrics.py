#!/usr/bin/env python3
"""
ShopVivaliz Metrics - Prometheus Integration

Coleta métricas de performance e saúde do sistema.
"""

from prometheus_client import Counter, Gauge, Histogram, generate_latest
from datetime import datetime
import time

# ============================================================================
# MÉTRICAS
# ============================================================================

# Counters (incrementam)
sync_total = Counter(
    "sync_total",
    "Total de syncs executados",
    ["environment", "status"],
)

message_sent_total = Counter(
    "message_sent_total",
    "Total de mensagens enviadas",
    ["from_agent", "to_agent"],
)

task_completed_total = Counter(
    "task_completed_total",
    "Total de tarefas completadas",
    ["agent", "status"],
)

# Gauges (valor atual)
agents_connected = Gauge(
    "agents_connected",
    "Número de agentes conectados",
)

messages_pending = Gauge(
    "messages_pending",
    "Mensagens pendentes na fila",
)

database_rows = Gauge(
    "database_rows",
    "Número de rows no database",
    ["table"],
)

# Histograms (distribuição de latência)
sync_duration_seconds = Histogram(
    "sync_duration_seconds",
    "Tempo de execução de sync",
    ["environment"],
    buckets=(0.1, 0.5, 1.0, 2.5, 5.0, 10.0),
)

message_latency_seconds = Histogram(
    "message_latency_seconds",
    "Latência de entrega de mensagem",
    buckets=(0.01, 0.05, 0.1, 0.5, 1.0),
)

# ============================================================================
# HELPERS
# ============================================================================

class MetricsCollector:
    """Coletor de métricas."""

    @staticmethod
    def record_sync(environment: str, status: str, duration: float):
        """Registrar sync com duração."""
        sync_total.labels(environment=environment, status=status).inc()
        sync_duration_seconds.labels(environment=environment).observe(duration)

    @staticmethod
    def record_message(from_agent: str, to_agent: str, latency: float):
        """Registrar envio de mensagem."""
        message_sent_total.labels(
            from_agent=from_agent,
            to_agent=to_agent
        ).inc()
        message_latency_seconds.observe(latency)

    @staticmethod
    def record_task(agent: str, status: str):
        """Registrar tarefa completada."""
        task_completed_total.labels(agent=agent, status=status).inc()

    @staticmethod
    def update_agents_count(count: int):
        """Atualizar número de agentes."""
        agents_connected.set(count)

    @staticmethod
    def update_pending_messages(count: int):
        """Atualizar mensagens pendentes."""
        messages_pending.set(count)

    @staticmethod
    def update_db_rows(table: str, count: int):
        """Atualizar contagem de rows."""
        database_rows.labels(table=table).set(count)

    @staticmethod
    def get_metrics():
        """Obter métricas em formato Prometheus."""
        return generate_latest()


# ============================================================================
# PROMETHEUS EXPORTER (Para Grafana)
# ============================================================================

def create_metrics_app():
    """Criar Flask app com endpoint /metrics para Prometheus."""
    from flask import Flask

    app = Flask(__name__)

    @app.route("/metrics", methods=["GET"])
    def metrics():
        """Endpoint Prometheus."""
        return MetricsCollector.get_metrics(), 200, {
            "Content-Type": "text/plain; charset=utf-8"
        }

    @app.route("/health", methods=["GET"])
    def health():
        """Health check."""
        return {"status": "ok"}, 200

    return app


if __name__ == "__main__":
    # Teste
    print("🟢 Testando métricas...")

    MetricsCollector.record_sync("windows-local", "success", 2.5)
    MetricsCollector.record_sync("ubuntu-vm", "success", 1.8)
    MetricsCollector.update_agents_count(3)
    MetricsCollector.update_pending_messages(5)

    print("✅ Métricas registradas")
    print("   Inicie o servidor com:")
    print("   python -c \"from scripts.shopvivaliz_metrics import create_metrics_app; app = create_metrics_app(); app.run(port=9090)\"")
