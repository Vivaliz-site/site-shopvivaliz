#!/usr/bin/env python3
"""
ShopVivaliz Integration Tests - Multi-Agent Collaboration

Testes de integração entre múltiplos agentes.
"""

import pytest
import json
import os
import time
import requests
from datetime import datetime
from typing import Dict, Any
from pathlib import Path

AGENT_REGISTRY_URL = os.getenv("AGENT_REGISTRY_URL", "").rstrip("/")
pytestmark = pytest.mark.skipif(
    not AGENT_REGISTRY_URL,
    reason="Set AGENT_REGISTRY_URL to run the external agent-registry integration suite",
)

class TestAgentRegistry:
    """Testes do Agent Registry."""

    API_URL = AGENT_REGISTRY_URL or "http://localhost:5000"
    TIMEOUT = 10

    def test_register_agent(self):
        """Registrar um novo agente."""
        response = requests.post(
            f"{self.API_URL}/agents/register",
            json={
                "name": "TestAgent-1",
                "type": "custom",
                "webhook_url": "http://test-webhook:8000/events",
                "capabilities": ["test", "validate"]
            },
            timeout=self.TIMEOUT
        )
        assert response.status_code == 201
        data = response.json()
        assert data["agent"]["name"] == "TestAgent-1"
        assert "agent_id" in data["agent"]

    def test_list_agents(self):
        """Listar agentes registrados."""
        response = requests.get(
            f"{self.API_URL}/agents",
            timeout=self.TIMEOUT
        )
        assert response.status_code == 200
        data = response.json()
        assert "agents" in data
        assert isinstance(data["agents"], list)

    def test_agent_health(self):
        """Verificar saúde do agente."""
        response = requests.get(
            f"{self.API_URL}/health",
            timeout=self.TIMEOUT
        )
        assert response.status_code == 200
        data = response.json()
        assert data["status"] == "ok"

class TestMessageQueue:
    """Testes da Message Queue."""

    API_URL = AGENT_REGISTRY_URL or "http://localhost:5000"
    TIMEOUT = 10

    def test_send_message(self):
        """Enviar mensagem entre agentes."""
        response = requests.post(
            f"{self.API_URL}/messages/send",
            json={
                "from_agent": "test-agent-1",
                "to_agent": "test-agent-2",
                "type": "task",
                "data": {
                    "action": "test_action",
                    "params": {"key": "value"}
                },
                "priority": "high"
            },
            timeout=self.TIMEOUT
        )
        assert response.status_code in [200, 201]
        data = response.json()
        assert "message_id" in data or "status" in data

    def test_get_inbox(self):
        """Obter inbox de um agente."""
        response = requests.get(
            f"{self.API_URL}/messages/inbox?agent_id=test-agent-1",
            timeout=self.TIMEOUT
        )
        assert response.status_code == 200
        data = response.json()
        assert "messages" in data or isinstance(data, list)

    def test_broadcast_message(self):
        """Broadcast para todos os agentes."""
        response = requests.post(
            f"{self.API_URL}/events/broadcast",
            json={
                "event": {
                    "type": "sync_required",
                    "data": {"timestamp": datetime.utcnow().isoformat()}
                },
                "agent_type": "custom"
            },
            timeout=self.TIMEOUT
        )
        assert response.status_code in [200, 201]

class TestMultiAgentWorkflow:
    """Testes de workflow multi-agente."""

    API_URL = AGENT_REGISTRY_URL or "http://localhost:5000"
    TIMEOUT = 30

    def test_agent_collaboration_chain(self):
        """
        Testar cadeia de colaboração:
        Agent1 → Agent2 → Agent3
        """
        # 1. Agent1 envia task para Agent2
        msg1 = requests.post(
            f"{self.API_URL}/messages/send",
            json={
                "from_agent": "agent-1",
                "to_agent": "agent-2",
                "type": "task",
                "data": {"action": "analyze", "subject": "test"},
                "priority": "high"
            },
            timeout=self.TIMEOUT
        )
        assert msg1.status_code in [200, 201]

        # 2. Agent2 recebe e envia para Agent3
        time.sleep(0.5)
        msg2 = requests.post(
            f"{self.API_URL}/messages/send",
            json={
                "from_agent": "agent-2",
                "to_agent": "agent-3",
                "type": "task",
                "data": {"action": "validate", "reference": "from-agent-1"},
                "priority": "high"
            },
            timeout=self.TIMEOUT
        )
        assert msg2.status_code in [200, 201]

        # 3. Verificar fila não explodiu
        inbox = requests.get(
            f"{self.API_URL}/messages/inbox?agent_id=agent-3",
            timeout=self.TIMEOUT
        )
        assert inbox.status_code == 200

    def test_parallel_message_processing(self):
        """Testar processamento paralelo de mensagens."""
        responses = []

        # Enviar 10 mensagens em paralelo
        for i in range(10):
            response = requests.post(
                f"{self.API_URL}/messages/send",
                json={
                    "from_agent": f"agent-parallel-{i}",
                    "to_agent": "agent-sink",
                    "type": "task",
                    "data": {"id": i},
                    "priority": "normal"
                },
                timeout=self.TIMEOUT
            )
            responses.append(response)

        # Todas devem ter sucesso
        assert all(r.status_code in [200, 201] for r in responses)

    def test_message_ordering(self):
        """Testar ordem de processamento de mensagens."""
        order = []

        for i in range(5):
            response = requests.post(
                f"{self.API_URL}/messages/send",
                json={
                    "from_agent": "agent-order-test",
                    "to_agent": "agent-order-sink",
                    "type": "ordered",
                    "data": {"sequence": i},
                    "priority": "high"
                },
                timeout=self.TIMEOUT
            )
            if response.status_code in [200, 201]:
                data = response.json()
                if "message_id" in data:
                    order.append(i)

        # Todas as mensagens devem ter sido enfileiradas
        assert len(order) == 5

class TestDatabasePersistence:
    """Testes de persistência de dados."""

    API_URL = AGENT_REGISTRY_URL or "http://localhost:5000"
    TIMEOUT = 10

    def test_message_persists(self):
        """Verificar que mensagens são persistidas."""
        # Enviar mensagem
        send_response = requests.post(
            f"{self.API_URL}/messages/send",
            json={
                "from_agent": "persistent-agent-1",
                "to_agent": "persistent-agent-2",
                "type": "task",
                "data": {"test": "persistence"},
                "priority": "high"
            },
            timeout=self.TIMEOUT
        )
        assert send_response.status_code in [200, 201]

        # Aguardar um pouco
        time.sleep(1)

        # Verificar que foi persistida
        inbox = requests.get(
            f"{self.API_URL}/messages/inbox?agent_id=persistent-agent-2",
            timeout=self.TIMEOUT
        )
        assert inbox.status_code == 200

    def test_database_consistency(self):
        """Testar consistência do database após múltiplas operações."""
        # Fazer múltiplas operações
        for i in range(5):
            requests.post(
                f"{self.API_URL}/messages/send",
                json={
                    "from_agent": f"consistency-test-{i}",
                    "to_agent": "consistency-sink",
                    "type": "task",
                    "data": {"iteration": i},
                    "priority": "normal"
                },
                timeout=self.TIMEOUT
            )

        # Verificar health após operações
        health = requests.get(
            f"{self.API_URL}/health",
            timeout=self.TIMEOUT
        )
        assert health.status_code == 200

class TestErrorHandling:
    """Testes de tratamento de erros."""

    API_URL = AGENT_REGISTRY_URL or "http://localhost:5000"
    TIMEOUT = 10

    def test_invalid_message_format(self):
        """Testar validação de formato de mensagem."""
        response = requests.post(
            f"{self.API_URL}/messages/send",
            json={"invalid": "format"},
            timeout=self.TIMEOUT
        )
        assert response.status_code >= 400

    def test_missing_required_fields(self):
        """Testar validação de campos obrigatórios."""
        response = requests.post(
            f"{self.API_URL}/messages/send",
            json={
                "from_agent": "test",
                # faltando to_agent
                "type": "task"
            },
            timeout=self.TIMEOUT
        )
        assert response.status_code >= 400

    def test_nonexistent_agent(self):
        """Testar envio para agente inexistente."""
        response = requests.post(
            f"{self.API_URL}/messages/send",
            json={
                "from_agent": "test-sender",
                "to_agent": "nonexistent-agent-xyz",
                "type": "task",
                "data": {"test": "data"},
                "priority": "normal"
            },
            timeout=self.TIMEOUT
        )
        # Pode suceder (fila async) ou falhar (validação strict)
        assert response.status_code in [200, 201, 400, 404]

class TestPerformance:
    """Testes de performance."""

    API_URL = AGENT_REGISTRY_URL or "http://localhost:5000"
    TIMEOUT = 30

    def test_message_latency(self):
        """Testar latência de entrega de mensagens."""
        start = time.time()

        response = requests.post(
            f"{self.API_URL}/messages/send",
            json={
                "from_agent": "latency-test-1",
                "to_agent": "latency-test-2",
                "type": "task",
                "data": {"timestamp": start},
                "priority": "high"
            },
            timeout=self.TIMEOUT
        )

        latency = (time.time() - start) * 1000  # ms
        assert response.status_code in [200, 201]
        assert latency < 5000  # Menos de 5 segundos

    def test_throughput(self):
        """Testar throughput (mensagens por segundo)."""
        count = 0
        start = time.time()

        while time.time() - start < 5:  # 5 segundos
            response = requests.post(
                f"{self.API_URL}/messages/send",
                json={
                    "from_agent": "throughput-test",
                    "to_agent": "throughput-sink",
                    "type": "task",
                    "data": {"index": count},
                    "priority": "normal"
                },
                timeout=self.TIMEOUT
            )
            if response.status_code in [200, 201]:
                count += 1

        throughput = count / 5  # mensagens por segundo
        assert throughput > 1  # Pelo menos 1 msg/s

if __name__ == "__main__":
    pytest.main([__file__, "-v", "--tb=short"])
