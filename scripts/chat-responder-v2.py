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
        self.queue_file = Path('logs/tasks-queue.json')
        self.log_dir = Path('logs')
        self.log_dir.mkdir(parents=True, exist_ok=True)

        # API Keys
        self.gemini_key = os.getenv('GEMINI_API_KEY')
        self.claude_key = os.getenv('ANTHROPIC_API_KEY')
        self.openai_key = os.getenv('OPENAI_API_KEY')

    def load_messages(self):
        """Carregar mensagens do log, suportando JSON em uma linha ou várias linhas."""
        if not self.chat_log.exists():
            return []

        raw = self.chat_log.read_text(encoding='utf-8')
        decoder = json.JSONDecoder()
        messages = []
        idx = 0

        while idx < len(raw):
            while idx < len(raw) and raw[idx] in ' \t\r\n':
                idx += 1
            if idx >= len(raw):
                break

            try:
                msg, end = decoder.raw_decode(raw, idx)
                if isinstance(msg, dict) and 'timestamp' in msg:
                    messages.append(msg)
                idx = end
            except json.JSONDecodeError:
                idx += 1

        return messages

    def get_latest_message(self):
        """Obter ultima mensagem nao respondida"""
        messages = self.load_messages()
        if not messages:
            return None

        for msg in reversed(messages):
            if not self.already_responded(msg.get('timestamp')):
                return msg

        return None

    def already_responded(self, timestamp):
        """Verificar se ja respondemos essa mensagem"""
        if timestamp is None:
            return False
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

    def _extract_text_from_response(self, response):
        if not response:
            return None

        if hasattr(response, 'output_text'):
            return response.output_text.strip() if response.output_text else None

        if hasattr(response, 'output'):
            output = response.output
            if isinstance(output, list) and output:
                first = output[0]
                if isinstance(first, dict):
                    content = first.get('content')
                    if isinstance(content, list):
                        text_parts = [part.get('text', '') for part in content if isinstance(part, dict) and part.get('type') == 'text']
                        return ''.join(text_parts).strip() or None
                    return first.get('text')

        if hasattr(response, 'choices'):
            choices = response.choices
            if isinstance(choices, list) and choices:
                first = choices[0]
                if hasattr(first, 'message'):
                    return getattr(first.message, 'content', None)
                if isinstance(first, dict):
                    message = first.get('message')
                    if isinstance(message, dict):
                        return message.get('content')

        if hasattr(response, 'text'):
            return response.text.strip() if response.text else None

        if isinstance(response, str):
            return response.strip() or None

        return None

    def call_gemini(self, message):
        """Chamar Gemini API para gerar resposta"""
        if not self.gemini_key:
            return None

        prompt = f"""Você é um assistente de IA para o ShopVivaliz ecommerce.
Usuario perguntou: {message}

Responda de forma concisa (max 2 linhas) com informacoes util sobre o sistema."""

        try:
            import google.genai
            client = google.genai.Client(api_key=self.gemini_key)
            response = client.models.generate_content(
                model='gemini-2.5-flash',
                contents=prompt
            )
            if response.text:
                return response.text.strip()
        except Exception as e:
            print(f"Erro ao chamar Gemini (google.genai): {e}")

        try:
            import google.generativeai as genai
            genai.configure(api_key=self.gemini_key)
            model = genai.GenerativeModel(os.getenv('GEMINI_MODEL') or 'gemini-1.5-flash')
            response = model.generate_content(prompt)
            if response.text:
                return response.text.strip()
        except Exception as e:
            print(f"Erro ao chamar Gemini (google.generativeai): {e}")

        return None

    def call_claude(self, message):
        """Chamar Claude API para gerar resposta"""
        try:
            import anthropic

            if not self.claude_key:
                return None

            client = anthropic.Anthropic(api_key=self.claude_key)

            response = client.messages.create(
                model=os.getenv('ANTHROPIC_MODEL') or 'claude-haiku-4-5-20251001',
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

            if response.content and len(response.content) > 0:
                return response.content[0].text.strip()
        except Exception as e:
            print(f"Erro ao chamar Claude: {e}")
            return None

    def call_openai(self, message):
        """Chamar OpenAI API para gerar resposta"""
        if not self.openai_key:
            return None

        prompt = f"""Você é um assistente de IA para o ShopVivaliz ecommerce.
Usuario perguntou: {message}

Responda de forma concisa (max 2 linhas) com informacoes util sobre o sistema."""

        try:
            import openai
            client = openai.OpenAI(api_key=self.openai_key)

            response = client.chat.completions.create(
                model=os.getenv('OPENAI_MODEL') or 'gpt-4o-mini',
                messages=[{'role': 'user', 'content': prompt}],
                max_tokens=200
            )

            if response.choices and len(response.choices) > 0:
                return response.choices[0].message.content.strip()

        except Exception as e:
            print(f"Erro ao chamar OpenAI: {e}")

        return None

    def respond(self):
        """Processar mensagem nao respondida"""
        message_obj = self.get_latest_message()

        if not message_obj:
            return False

        message = message_obj.get('message', '')
        print(f"[RESPONDER] Processando: {message}")

        response_text = self.call_gemini(message)
        provider = 'Gemini' if response_text else None

        if not response_text:
            response_text = self.call_claude(message)
            provider = 'Claude' if response_text else None

        if not response_text:
            response_text = self.call_openai(message)
            provider = 'OpenAI' if response_text else None

        if not response_text:
            response_text = 'Agentes offline. Tente novamente em alguns minutos.'
            provider = 'fallback'

        response_obj = {
            'timestamp': datetime.now().isoformat(),
            'message_timestamp': message_obj.get('timestamp'),
            'user_message': message,
            'agent_response': response_text,
            'agent': provider
        }

        with open(self.response_log, 'a') as f:
            f.write(json.dumps(response_obj, ensure_ascii=False) + '\n')

        print(f"[RESPOSTA] {response_text}")
        return True

if __name__ == '__main__':
    responder = ChatResponderV2()
    responder.respond()
