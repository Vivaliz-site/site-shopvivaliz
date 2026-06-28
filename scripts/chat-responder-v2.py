#!/usr/bin/env python3
"""
CHAT RESPONDER v2 - Agentes REALMENTE respondem via APIs
Chamada REAL às APIs Gemini e Claude para gerar respostas inteligentes
"""
import json
import os
from pathlib import Path
from datetime import datetime

class ChatResponderV2:
    def __init__(self):
        self.chat_log = Path('logs/monitor-messages.log')
        self.response_log = Path('logs/monitor-responses.jsonl')
        self.queue_file = Path('tasks-queue.json')
        self.log_dir = Path('logs')
        self.log_dir.mkdir(parents=True, exist_ok=True)

        # API Keys
        self.gemini_key = os.getenv('GEMINI_API_KEY')
        self.claude_key = os.getenv('ANTHROPIC_API_KEY')

    def get_latest_message(self):
        """Obter ultima mensagem nao respondida"""
        if not self.chat_log.exists():
            return None

        with open(self.chat_log) as f:
            lines = f.readlines()

        if not lines:
            return None

        # Verificar se ja foi respondida
        for line in reversed(lines):
            try:
                msg = json.loads(line)
                if not self.already_responded(msg['timestamp']):
                    return msg
            except:
                pass

        return None

    def already_responded(self, timestamp):
        """Verificar se ja respondemos essa mensagem"""
        if not self.response_log.exists():
            return False

        with open(self.response_log) as f:
            for line in f:
                try:
                    response = json.loads(line)
                    if response.get('message_timestamp') == timestamp:
                        return True
                except:
                    pass

        return False

    def call_gemini(self, message):
        """Chamar Gemini API para gerar resposta"""
        try:
            import google.generativeai as genai

            if not self.gemini_key:
                return None

            genai.configure(api_key=self.gemini_key)
            model = genai.GenerativeModel('gemini-pro')

            prompt = f"""Você é um assistente de IA para o ShopVivaliz ecommerce.
Usuario perguntou: {message}

Responda de forma concisa (max 2 linhas) com informacoes util sobre o sistema."""

            response = model.generate_content(prompt)
            return response.text if response else None
        except Exception as e:
            print(f"Erro ao chamar Gemini: {e}")
            return None

    def call_claude(self, message):
        """Chamar Claude API para gerar resposta"""
        try:
            import anthropic

            if not self.claude_key:
                return None

            client = anthropic.Anthropic(api_key=self.claude_key)

            message_obj = client.messages.create(
                model="claude-3-5-sonnet-20241022",
                max_tokens=200,
                messages=[
                    {
                        "role": "user",
                        "content": f"""Voce eh um assistente de IA para ShopVivaliz ecommerce.
Usuario: {message}

Responda de forma concisa (max 2 linhas) sobre o sistema."""
                    }
                ]
            )

            return message_obj.content[0].text if message_obj.content else None
        except Exception as e:
            print(f"Erro ao chamar Claude: {e}")
            return None

    def respond(self):
        """Processar mensagem nao respondida"""
        message_obj = self.get_latest_message()

        if not message_obj:
            return False

        message = message_obj.get('message', '')
        print(f"[RESPONDER] Processando: {message}")

        # Tentar Gemini primeiro, depois Claude
        response_text = self.call_gemini(message)

        if not response_text:
            response_text = self.call_claude(message)

        if not response_text:
            response_text = "Agentes offline. Tente novamente em alguns minutos."

        # Salvar resposta
        response_obj = {
            'timestamp': datetime.now().isoformat(),
            'message_timestamp': message_obj.get('timestamp'),
            'user_message': message,
            'agent_response': response_text,
            'agent': 'Gemini' if 'Gemini' in response_text else 'Claude'
        }

        with open(self.response_log, 'a') as f:
            f.write(json.dumps(response_obj, ensure_ascii=False) + '\n')

        print(f"[RESPOSTA] {response_text}")
        return True

if __name__ == '__main__':
    responder = ChatResponderV2()
    responder.respond()
