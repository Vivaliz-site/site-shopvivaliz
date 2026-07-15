#!/usr/bin/env python3
"""
ShopVivaliz Authentication - JWT-based Security

Protege API com tokens JWT.
"""

import os
import jwt
import uuid
from datetime import datetime, timedelta
from functools import wraps
from typing import Dict, Any, Optional
from flask import request, jsonify

SECRET_KEY = os.getenv("JWT_SECRET_KEY", "shopvivaliz-secret-key-change-in-production")
ALGORITHM = "HS256"
TOKEN_EXPIRY_HOURS = 24

class AuthManager:
    """Gerenciador de autenticação JWT."""

    @staticmethod
    def generate_token(agent_id: str, agent_type: str) -> str:
        """Gerar token JWT para um agente."""
        payload = {
            "agent_id": agent_id,
            "agent_type": agent_type,
            "iat": datetime.utcnow(),
            "exp": datetime.utcnow() + timedelta(hours=TOKEN_EXPIRY_HOURS),
            "jti": str(uuid.uuid4()),
        }
        return jwt.encode(payload, SECRET_KEY, algorithm=ALGORITHM)

    @staticmethod
    def verify_token(token: str) -> Optional[Dict[str, Any]]:
        """Verificar e decodificar token JWT."""
        try:
            payload = jwt.decode(token, SECRET_KEY, algorithms=[ALGORITHM])
            return payload
        except jwt.ExpiredSignatureError:
            return None
        except jwt.InvalidTokenError:
            return None

    @staticmethod
    def require_auth(f):
        """Decorator para requerer autenticação em endpoints."""
        @wraps(f)
        def decorated_function(*args, **kwargs):
            token = None

            # Procurar token em: Authorization header, query param, ou body
            if "Authorization" in request.headers:
                auth_header = request.headers["Authorization"]
                try:
                    token = auth_header.split(" ")[1]
                except IndexError:
                    return jsonify({"error": "Invalid token format"}), 401

            elif "token" in request.args:
                token = request.args.get("token")

            elif request.is_json and "token" in request.json:
                token = request.json.get("token")

            if not token:
                return jsonify({"error": "Token required"}), 401

            payload = AuthManager.verify_token(token)
            if not payload:
                return jsonify({"error": "Invalid or expired token"}), 401

            # Adicionar payload ao request context
            request.auth = payload

            return f(*args, **kwargs)

        return decorated_function

    @staticmethod
    def get_current_agent() -> Optional[str]:
        """Obter ID do agente autenticado."""
        if hasattr(request, "auth"):
            return request.auth.get("agent_id")
        return None


# CLI para gerar tokens
if __name__ == "__main__":
    import sys

    if len(sys.argv) > 1:
        agent_id = sys.argv[1]
        agent_type = sys.argv[2] if len(sys.argv) > 2 else "custom"

        token = AuthManager.generate_token(agent_id, agent_type)
        print(f"Token gerado para {agent_id}:")
        print(f"  {token}")
        print(f"\nUse em request:")
        print(f"  curl -H 'Authorization: Bearer {token}' http://localhost:5000/agents")
    else:
        print("Uso: python shopvivaliz_auth.py <agent_id> [agent_type]")
