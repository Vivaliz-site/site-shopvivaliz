#!/usr/bin/env python3
"""
ShopVivaliz Retry Policy - Webhook Delivery with Exponential Backoff

Implementa retry automático com backoff exponencial para webhooks.
"""

import json
import time
import requests
from datetime import datetime, timedelta
from typing import Dict, Any, Optional, List
from enum import Enum
from dataclasses import dataclass, asdict
from pathlib import Path

class RetryStatus(Enum):
    PENDING = "pending"
    IN_PROGRESS = "in_progress"
    SUCCESS = "success"
    FAILED = "failed"
    DEAD_LETTER = "dead_letter"

@dataclass
class WebhookDelivery:
    delivery_id: str
    webhook_url: str
    payload: Dict[str, Any]
    status: str = RetryStatus.PENDING.value
    attempts: int = 0
    max_retries: int = 5
    next_retry_at: Optional[str] = None
    last_error: Optional[str] = None
    created_at: str = None
    updated_at: str = None
    response_code: Optional[int] = None
    response_body: Optional[str] = None

    def __post_init__(self):
        if not self.created_at:
            self.created_at = datetime.utcnow().isoformat()
        self.updated_at = datetime.utcnow().isoformat()

class RetryPolicy:
    """Política de retry com backoff exponencial."""

    MIN_BACKOFF = 1  # segundos
    MAX_BACKOFF = 3600  # 1 hora
    BASE_MULTIPLIER = 2
    JITTER_FACTOR = 0.1

    @staticmethod
    def calculate_backoff(attempt: int) -> int:
        """Calcular tempo de espera com backoff exponencial + jitter."""
        backoff = min(
            RetryPolicy.MIN_BACKOFF * (RetryPolicy.BASE_MULTIPLIER ** attempt),
            RetryPolicy.MAX_BACKOFF
        )
        jitter = backoff * RetryPolicy.JITTER_FACTOR
        return int(backoff + (jitter * (2 * time.time() % 1 - 1)))

    @staticmethod
    def should_retry(status_code: int) -> bool:
        """Determinar se deve fazer retry baseado no status code."""
        return status_code >= 500 or status_code in [408, 429]

class DeadLetterQueue:
    """Fila de letras mortas para falhas permanentes."""

    def __init__(self, storage_path: str = "dead-letter-queue.json"):
        self.storage_path = Path(storage_path)
        self.load()

    def load(self):
        """Carregar DLQ do arquivo."""
        if self.storage_path.exists():
            with open(self.storage_path) as f:
                self.queue = json.load(f)
        else:
            self.queue = []

    def save(self):
        """Salvar DLQ no arquivo."""
        with open(self.storage_path, 'w') as f:
            json.dump(self.queue, f, indent=2)

    def add(self, delivery: WebhookDelivery):
        """Adicionar mensagem à DLQ."""
        delivery.status = RetryStatus.DEAD_LETTER.value
        self.queue.append(asdict(delivery))
        self.save()

    def list(self) -> List[Dict]:
        """Listar mensagens na DLQ."""
        return self.queue

    def retry(self, delivery_id: str) -> bool:
        """Tentar reprocessar mensagem da DLQ."""
        for i, item in enumerate(self.queue):
            if item['delivery_id'] == delivery_id:
                self.queue.pop(i)
                self.save()
                return True
        return False

    def clear(self, days: int = 30):
        """Limpar DLQ mais antiga que N dias."""
        cutoff = datetime.utcnow() - timedelta(days=days)
        initial_len = len(self.queue)
        self.queue = [
            item for item in self.queue
            if datetime.fromisoformat(item['created_at']) > cutoff
        ]
        removed = initial_len - len(self.queue)
        self.save()
        return removed

class RetryQueue:
    """Fila de retry com persistência."""

    def __init__(self, storage_path: str = "retry-queue.json"):
        self.storage_path = Path(storage_path)
        self.dlq = DeadLetterQueue()
        self.load()

    def load(self):
        """Carregar fila do arquivo."""
        if self.storage_path.exists():
            with open(self.storage_path) as f:
                data = json.load(f)
                self.queue = [
                    WebhookDelivery(**item) for item in data
                ]
        else:
            self.queue = []

    def save(self):
        """Salvar fila no arquivo."""
        with open(self.storage_path, 'w') as f:
            json.dump([asdict(item) for item in self.queue], f, indent=2)

    def add(self, webhook_url: str, payload: Dict[str, Any]) -> str:
        """Adicionar mensagem à fila."""
        import uuid
        delivery_id = f"dlv-{uuid.uuid4().hex[:12]}"
        delivery = WebhookDelivery(
            delivery_id=delivery_id,
            webhook_url=webhook_url,
            payload=payload
        )
        self.queue.append(delivery)
        self.save()
        return delivery_id

    def get_pending(self) -> List[WebhookDelivery]:
        """Obter mensagens prontas para retry."""
        now = datetime.utcnow()
        pending = []

        for delivery in self.queue:
            if delivery.status == RetryStatus.PENDING.value:
                pending.append(delivery)
            elif delivery.status == RetryStatus.IN_PROGRESS.value:
                if delivery.next_retry_at and datetime.fromisoformat(delivery.next_retry_at) <= now:
                    pending.append(delivery)

        return pending

    def process_one(self, delivery: WebhookDelivery, timeout: int = 10) -> bool:
        """Processar um webhook com retry."""
        delivery.status = RetryStatus.IN_PROGRESS.value
        delivery.attempts += 1
        self.save()

        try:
            response = requests.post(
                delivery.webhook_url,
                json=delivery.payload,
                timeout=timeout,
                headers={"X-Retry-Attempt": str(delivery.attempts)}
            )
            delivery.response_code = response.status_code
            delivery.response_body = response.text[:500]

            if response.status_code < 300:
                delivery.status = RetryStatus.SUCCESS.value
                self.save()
                return True

            elif RetryPolicy.should_retry(response.status_code) and delivery.attempts < delivery.max_retries:
                backoff = RetryPolicy.calculate_backoff(delivery.attempts)
                delivery.next_retry_at = (
                    datetime.utcnow() + timedelta(seconds=backoff)
                ).isoformat()
                delivery.status = RetryStatus.PENDING.value
                delivery.last_error = f"HTTP {response.status_code}"
                self.save()
                return False

            else:
                delivery.status = RetryStatus.FAILED.value
                delivery.last_error = f"HTTP {response.status_code} - Won't retry"
                self.dlq.add(delivery)
                # Remove de queue normal
                self.queue = [d for d in self.queue if d.delivery_id != delivery.delivery_id]
                self.save()
                return False

        except requests.Timeout:
            if delivery.attempts < delivery.max_retries:
                backoff = RetryPolicy.calculate_backoff(delivery.attempts)
                delivery.next_retry_at = (
                    datetime.utcnow() + timedelta(seconds=backoff)
                ).isoformat()
                delivery.status = RetryStatus.PENDING.value
                delivery.last_error = "Timeout"
                self.save()
                return False
            else:
                delivery.status = RetryStatus.FAILED.value
                delivery.last_error = "Timeout after max retries"
                self.dlq.add(delivery)
                self.queue = [d for d in self.queue if d.delivery_id != delivery.delivery_id]
                self.save()
                return False

        except Exception as e:
            if delivery.attempts < delivery.max_retries:
                backoff = RetryPolicy.calculate_backoff(delivery.attempts)
                delivery.next_retry_at = (
                    datetime.utcnow() + timedelta(seconds=backoff)
                ).isoformat()
                delivery.status = RetryStatus.PENDING.value
                delivery.last_error = str(e)[:100]
                self.save()
                return False
            else:
                delivery.status = RetryStatus.FAILED.value
                delivery.last_error = str(e)[:100]
                self.dlq.add(delivery)
                self.queue = [d for d in self.queue if d.delivery_id != delivery.delivery_id]
                self.save()
                return False

    def process_all(self) -> Dict[str, int]:
        """Processar todas as mensagens prontas."""
        stats = {"success": 0, "failed": 0, "retry": 0}
        pending = self.get_pending()

        for delivery in pending:
            result = self.process_one(delivery)
            if delivery.status == RetryStatus.SUCCESS.value:
                stats["success"] += 1
            elif delivery.status == RetryStatus.PENDING.value:
                stats["retry"] += 1
            else:
                stats["failed"] += 1

        return stats

    def get_stats(self) -> Dict[str, Any]:
        """Obter estatísticas."""
        stats = {
            "total": len(self.queue),
            "pending": len([d for d in self.queue if d.status == RetryStatus.PENDING.value]),
            "in_progress": len([d for d in self.queue if d.status == RetryStatus.IN_PROGRESS.value]),
            "success": len([d for d in self.queue if d.status == RetryStatus.SUCCESS.value]),
            "failed": len([d for d in self.queue if d.status == RetryStatus.FAILED.value]),
            "dead_letter": len(self.dlq.queue),
        }
        return stats

    def get_delivery(self, delivery_id: str) -> Optional[WebhookDelivery]:
        """Obter detalhes de uma entrega."""
        for delivery in self.queue:
            if delivery.delivery_id == delivery_id:
                return delivery
        # Verificar DLQ
        for item in self.dlq.queue:
            if item['delivery_id'] == delivery_id:
                return WebhookDelivery(**item)
        return None

    def list_all(self) -> List[Dict]:
        """Listar todas as entregas."""
        return [asdict(d) for d in self.queue]

if __name__ == "__main__":
    import sys

    queue = RetryQueue()

    if len(sys.argv) > 1:
        cmd = sys.argv[1]

        if cmd == "add":
            url = sys.argv[2] if len(sys.argv) > 2 else "http://localhost:8000/webhook"
            delivery_id = queue.add(url, {"test": "payload", "timestamp": datetime.utcnow().isoformat()})
            print(f"✅ Adicionado à fila: {delivery_id}")

        elif cmd == "process":
            stats = queue.process_all()
            print(f"📨 Processamento concluído:")
            print(f"  ✅ Sucesso: {stats['success']}")
            print(f"  🔄 Retry: {stats['retry']}")
            print(f"  ❌ Falha: {stats['failed']}")

        elif cmd == "stats":
            stats = queue.get_stats()
            print(f"📊 Estatísticas da Fila:")
            for key, val in stats.items():
                print(f"  {key}: {val}")

        elif cmd == "list":
            deliveries = queue.list_all()
            print(f"📋 Mensagens na Fila ({len(deliveries)}):")
            for d in deliveries:
                print(f"  {d['delivery_id']}: {d['status']} (tentativa {d['attempts']}/{d['max_retries']})")

        elif cmd == "dlq":
            dlq_items = queue.dlq.list()
            print(f"💀 Dead Letter Queue ({len(dlq_items)}):")
            for item in dlq_items:
                print(f"  {item['delivery_id']}: {item['last_error']}")

        else:
            print("Uso: python shopvivaliz_retry.py <add|process|stats|list|dlq>")
    else:
        stats = queue.get_stats()
        print("🔄 Webhook Retry Queue Status:")
        print(f"  Total: {stats['total']}")
        print(f"  Pendente: {stats['pending']}")
        print(f"  Sucesso: {stats['success']}")
        print(f"  Dead Letter: {stats['dead_letter']}")
