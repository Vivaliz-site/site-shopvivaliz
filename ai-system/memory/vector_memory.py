"""
Vector Memory System for ShopVivaliz
Stores and retrieves context, decisions, and learnings
Using SQLite with simple embeddings (can upgrade to Qdrant)
"""

import sqlite3
import json
import hashlib
from datetime import datetime
from typing import List, Dict, Any, Optional
import os

class VectorMemory:
    def __init__(self, db_path: str = "C:/site-shopvivaliz/ai-system/memory/vector.db"):
        self.db_path = db_path
        os.makedirs(os.path.dirname(db_path), exist_ok=True)
        self._init_database()

    def _init_database(self):
        """Initialize memory database"""
        conn = sqlite3.connect(self.db_path)
        c = conn.cursor()

        # Memory entries
        c.execute('''CREATE TABLE IF NOT EXISTS memory (
            id TEXT PRIMARY KEY,
            type TEXT,  -- decision, solution, incident, pattern, architecture
            content TEXT,
            agent TEXT,
            confidence REAL,
            created_at TIMESTAMP,
            updated_at TIMESTAMP,
            source TEXT,  -- file, branch, incident_id
            expires_at TIMESTAMP,
            tags TEXT  -- JSON array
        )''')

        # Retrieval history
        c.execute('''CREATE TABLE IF NOT EXISTS retrieval_log (
            id INTEGER PRIMARY KEY,
            memory_id TEXT,
            agent TEXT,
            timestamp TIMESTAMP,
            useful BOOLEAN,
            FOREIGN KEY(memory_id) REFERENCES memory(id)
        )''')

        conn.commit()
        conn.close()

    def store(self, content: str, type_: str, agent: str = "system",
              confidence: float = 0.8, source: str = "", tags: List[str] = None):
        """Store a memory entry"""
        conn = sqlite3.connect(self.db_path)
        c = conn.cursor()

        memory_id = hashlib.md5(f"{content}{datetime.now()}".encode()).hexdigest()

        c.execute('''
            INSERT INTO memory
            (id, type, content, agent, confidence, created_at, source, tags)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ''', (
            memory_id,
            type_,
            content,
            agent,
            confidence,
            datetime.now(),
            source,
            json.dumps(tags or [])
        ))

        conn.commit()
        conn.close()

        return memory_id

    def retrieve(self, query: str, agent: str = "system", limit: int = 5) -> List[Dict]:
        """Retrieve relevant memories"""
        conn = sqlite3.connect(self.db_path)
        c = conn.cursor()

        # Simple keyword search (can upgrade to vector similarity)
        keywords = query.lower().split()

        c.execute('''
            SELECT id, type, content, confidence, created_at, source
            FROM memory
            WHERE content LIKE ? OR content LIKE ?
            ORDER BY confidence DESC, created_at DESC
            LIMIT ?
        ''', (f'%{keywords[0]}%' if keywords else '%%',
              f'%{keywords[1]}%' if len(keywords) > 1 else '%%',
              limit))

        results = []
        for row in c.fetchall():
            results.append({
                "id": row[0],
                "type": row[1],
                "content": row[2],
                "confidence": row[3],
                "created_at": row[4],
                "source": row[5],
            })

            # Log retrieval
            c.execute('''
                INSERT INTO retrieval_log (memory_id, agent, timestamp)
                VALUES (?, ?, ?)
            ''', (row[0], agent, datetime.now()))

        conn.commit()
        conn.close()

        return results

    def get_agent_memory(self, agent: str, limit: int = 10) -> List[Dict]:
        """Get memories specific to an agent"""
        conn = sqlite3.connect(self.db_path)
        c = conn.cursor()

        c.execute('''
            SELECT id, type, content, confidence, created_at
            FROM memory
            WHERE agent = ? OR tags LIKE ?
            ORDER BY created_at DESC
            LIMIT ?
        ''', (agent, f'%"{agent}"%', limit))

        results = [{"id": r[0], "type": r[1], "content": r[2], "confidence": r[3], "created_at": r[4]}
                   for r in c.fetchall()]

        conn.close()
        return results

    def cleanup_expired(self):
        """Remove expired memories"""
        conn = sqlite3.connect(self.db_path)
        c = conn.cursor()

        c.execute('''
            DELETE FROM memory
            WHERE expires_at IS NOT NULL AND expires_at < ?
        ''', (datetime.now(),))

        conn.commit()
        conn.close()

if __name__ == "__main__":
    memory = VectorMemory()

    # Test
    print("Testing Vector Memory System...\n")

    # Store some memories
    memory.store("Always validate user input before database queries", "pattern", agent="backend")
    memory.store("MX110 GPU has 2GB VRAM, use quantized models", "architecture", agent="orchestrator")
    memory.store("Olist tokens expire every 30 days, setup auto-refresh", "solution", agent="integrations")

    # Retrieve
    results = memory.retrieve("olist token", agent="orchestrator")
    print(f"Found {len(results)} relevant memories:\n")
    for r in results:
        print(f"  [{r['type']}] {r['content'][:60]}...")
        print(f"    Confidence: {r['confidence']}, Created: {r['created_at']}\n")
