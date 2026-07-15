#!/usr/bin/env python3
"""
ShopVivaliz Agent API - Interface Agnóstica para Qualquer IA

Permite comunicação com:
- Claude (Anthropic)
- Gemini (Google)
- GPT (OpenAI)
- Ou qualquer agente customizado

Via REST API + Webhooks + Message Queue
"""

import json
import uuid
from datetime import datetime
from pathlib import Path
from typing import Dict, Any, List, Optional
from flask import Flask, request, jsonify
import requests

# ============================================================================
# CONFIGURAÇÃO
# ============================================================================

REPO_ROOT = Path(__file__).parent.parent
AGENT_REGISTRY_FILE = REPO_ROOT / ".claude" / "agent_registry.json"
MESSAGE_QUEUE_FILE = REPO_ROOT / "message-queue.json"

# ============================================================================
# AGENT REGISTRY
# ============================================================================

class AgentRegistry:
    """Registro de agentes conectados no sistema."""

    @staticmethod
    def register_agent(
        agent_name: str,
        agent_type: str,  # "claude", "gemini", "gpt", "custom"
        webhook_url: str,
        capabilities: List[str],
    ) -> Dict[str, Any]:
        """Registrar novo agente no sistema."""
        agent_id = str(uuid.uuid4())[:8]

        agent = {
            "agent_id": agent_id,
            "name": agent_name,
            "type": agent_type,
            "webhook_url": webhook_url,
            "capabilities": capabilities,
            "status": "active",
            "registered_at": datetime.now().isoformat(),
            "last_heartbeat": datetime.now().isoformat(),
        }

        # Carregar registry
        registry = AgentRegistry._load_registry()
        registry["agents"].append(agent)

        # Salvar
        with open(AGENT_REGISTRY_FILE, "w") as f:
            json.dump(registry, f, indent=2)

        return agent

    @staticmethod
    def list_agents(agent_type: Optional[str] = None) -> List[Dict]:
        """Listar agentes registrados."""
        registry = AgentRegistry._load_registry()

        agents = registry.get("agents", [])

        if agent_type:
            agents = [a for a in agents if a["type"] == agent_type]

        return agents

    @staticmethod
    def notify_agent(agent_id: str, event: Dict[str, Any]) -> bool:
        """Enviar evento para um agente via webhook."""
        registry = AgentRegistry._load_registry()

        agent = next((a for a in registry["agents"] if a["agent_id"] == agent_id), None)
        if not agent:
            return False

        try:
            response = requests.post(
                agent["webhook_url"],
                json=event,
                timeout=10,
            )
            return response.status_code == 200
        except Exception as e:
            print(f"❌ Erro ao notificar agente {agent_id}: {e}")
            return False

    @staticmethod
    def broadcast_event(event: Dict[str, Any], agent_type: Optional[str] = None) -> Dict[str, bool]:
        """Notificar todos os agentes sobre um evento."""
        agents = AgentRegistry.list_agents(agent_type)
        results = {}

        for agent in agents:
            if agent["status"] == "active":
                success = AgentRegistry.notify_agent(agent["agent_id"], event)
                results[agent["agent_id"]] = success

        return results

    @staticmethod
    def _load_registry() -> Dict:
        """Carregar registry ou criar novo."""
        if AGENT_REGISTRY_FILE.exists():
            with open(AGENT_REGISTRY_FILE) as f:
                return json.load(f)

        return {
            "agents": [],
            "created_at": datetime.now().isoformat(),
        }


# ============================================================================
# MESSAGE QUEUE
# ============================================================================

class MessageQueue:
    """Fila de mensagens entre agentes."""

    @staticmethod
    def enqueue(
        from_agent: str,
        to_agent: str,
        message_type: str,
        data: Dict[str, Any],
        priority: str = "normal",
    ) -> str:
        """Enfileirar mensagem."""
        message_id = str(uuid.uuid4())[:8]

        message = {
            "message_id": message_id,
            "from_agent": from_agent,
            "to_agent": to_agent,
            "type": message_type,
            "data": data,
            "priority": priority,
            "status": "pending",
            "created_at": datetime.now().isoformat(),
        }

        # Carregar fila
        queue = MessageQueue._load_queue()
        queue["messages"].append(message)

        # Salvar
        with open(MESSAGE_QUEUE_FILE, "w") as f:
            json.dump(queue, f, indent=2)

        return message_id

    @staticmethod
    def dequeue(agent_id: str, limit: int = 10) -> List[Dict]:
        """Desfileirar mensagens para um agente."""
        queue = MessageQueue._load_queue()

        messages = [
            m for m in queue["messages"]
            if m["to_agent"] == agent_id and m["status"] == "pending"
        ]

        # Retornar até `limit` mensagens
        result = messages[:limit]

        # Marcar como processadas
        for msg in result:
            msg["status"] = "processing"

        # Salvar atualização
        with open(MESSAGE_QUEUE_FILE, "w") as f:
            json.dump(queue, f, indent=2)

        return result

    @staticmethod
    def mark_processed(message_id: str, result: Optional[Dict] = None):
        """Marcar mensagem como processada."""
        queue = MessageQueue._load_queue()

        message = next((m for m in queue["messages"] if m["message_id"] == message_id), None)
        if message:
            message["status"] = "processed"
            message["processed_at"] = datetime.now().isoformat()
            message["result"] = result or {}

        with open(MESSAGE_QUEUE_FILE, "w") as f:
            json.dump(queue, f, indent=2)

    @staticmethod
    def _load_queue() -> Dict:
        """Carregar fila ou criar nova."""
        if MESSAGE_QUEUE_FILE.exists():
            with open(MESSAGE_QUEUE_FILE) as f:
                return json.load(f)

        return {"messages": []}


# ============================================================================
# REST API
# ============================================================================

def create_agent_api():
    """Criar API Flask para agentes."""
    app = Flask(__name__)

    # ========================= REGISTRATION =========================

    @app.route("/agents/register", methods=["POST"])
    def register_agent():
        """Registrar novo agente no sistema."""
        data = request.json

        agent = AgentRegistry.register_agent(
            agent_name=data.get("name", "unknown"),
            agent_type=data.get("type", "custom"),  # claude, gemini, gpt, custom
            webhook_url=data.get("webhook_url", ""),
            capabilities=data.get("capabilities", []),
        )

        return jsonify({
            "success": True,
            "agent": agent,
        }), 201

    @app.route("/agents", methods=["GET"])
    def list_agents():
        """Listar agentes registrados."""
        agent_type = request.args.get("type")
        agents = AgentRegistry.list_agents(agent_type)

        return jsonify({
            "agents": agents,
            "total": len(agents),
        })

    # ========================= MESSAGING =========================

    @app.route("/messages/send", methods=["POST"])
    def send_message():
        """Enviar mensagem entre agentes."""
        data = request.json

        message_id = MessageQueue.enqueue(
            from_agent=data.get("from_agent", "unknown"),
            to_agent=data.get("to_agent", "unknown"),
            message_type=data.get("type", "generic"),
            data=data.get("data", {}),
            priority=data.get("priority", "normal"),
        )

        return jsonify({
            "success": True,
            "message_id": message_id,
        }), 201

    @app.route("/messages/inbox", methods=["GET"])
    def get_inbox():
        """Obter mensagens pendentes para um agente."""
        agent_id = request.args.get("agent_id")
        limit = int(request.args.get("limit", 10))

        if not agent_id:
            return jsonify({"error": "agent_id required"}), 400

        messages = MessageQueue.dequeue(agent_id, limit)

        return jsonify({
            "messages": messages,
            "count": len(messages),
        })

    @app.route("/messages/<message_id>/ack", methods=["POST"])
    def ack_message(message_id):
        """Confirmar processamento de mensagem."""
        data = request.json

        MessageQueue.mark_processed(message_id, data.get("result"))

        return jsonify({"success": True})

    # ========================= EVENTS =========================

    @app.route("/events/broadcast", methods=["POST"])
    def broadcast_event():
        """Transmitir evento para todos agentes."""
        data = request.json

        results = AgentRegistry.broadcast_event(
            event=data.get("event", {}),
            agent_type=data.get("agent_type"),
        )

        return jsonify({
            "success": True,
            "results": results,
        })

    # ========================= HEALTH =========================

    @app.route("/health", methods=["GET"])
    def health():
        """Health check."""
        agents = AgentRegistry.list_agents()
        active = sum(1 for a in agents if a["status"] == "active")

        return jsonify({
            "status": "ok",
            "agents_registered": len(agents),
            "agents_active": active,
            "timestamp": datetime.now().isoformat(),
        })

    return app


# ============================================================================
# CLI
# ============================================================================

if __name__ == "__main__":
    import sys

    if len(sys.argv) > 1 and sys.argv[1] == "server":
        app = create_agent_api()
        port = int(sys.argv[2]) if len(sys.argv) > 2 else 5000
        print(f"🤖 Agent API rodando em http://localhost:{port}/")
        app.run(host="0.0.0.0", port=port, debug=False)

    elif len(sys.argv) > 1 and sys.argv[1] == "register":
        # Exemplo: python shopvivaliz_agent_api.py register claude my-webhook-url
        agent_type = sys.argv[2] if len(sys.argv) > 2 else "custom"
        webhook_url = sys.argv[3] if len(sys.argv) > 3 else ""

        agent = AgentRegistry.register_agent(
            agent_name=f"Agent-{agent_type}",
            agent_type=agent_type,
            webhook_url=webhook_url,
            capabilities=["sync", "execute", "monitor"],
        )

        print(f"✅ Agente registrado: {agent['agent_id']}")
        print(f"   Tipo: {agent['type']}")
        print(f"   Nome: {agent['name']}")

    else:
        print("Uso:")
        print("  python shopvivaliz_agent_api.py server [port]")
        print("  python shopvivaliz_agent_api.py register [type] [webhook_url]")
