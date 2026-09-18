#!/usr/bin/env python3
"""
CHAT RESPONDER - Agentes REAIS respondendo com APIs de verdade
"""
import json
import os
import sys
from pathlib import Path
from datetime import datetime

def get_agent_response(user_message):
    """Obter resposta real usando apenas os provedores aprovados para automa??o."""

    prompt = f'Voce eh um agente de ecommerce da ShopVivaliz. Usuario: {user_message}\n\nResponda brevemente:'

    try:
        import google.genai
        api_key = os.getenv('GEMINI_API_KEY')
        if api_key:
            client = google.genai.Client(api_key=api_key)
            response = client.models.generate_content(
                model=os.getenv('GEMINI_MODEL') or 'gemini-3.1-flash-lite',
                contents=prompt,
            )
            if getattr(response, 'text', None):
                return response.text, 'Gemini'
    except Exception as e:
        print(f'[Gemini error] {str(e)[:80]}', file=sys.stderr)

    try:
        import urllib.request
        api_key = os.getenv('OPENROUTER_API_KEY')
        if api_key:
            base = (os.getenv('OPENROUTER_API_BASE_URL') or 'https://openrouter.ai/api/v1').rstrip('/')
            payload = json.dumps({
                'model': os.getenv('OPENROUTER_TEXT_MODEL') or 'google/gemini-2.5-flash-lite',
                'messages': [{'role': 'user', 'content': prompt}],
                'max_tokens': 150,
            }).encode('utf-8')
            headers = {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + api_key,
                'HTTP-Referer': os.getenv('OPENROUTER_HTTP_REFERER') or 'https://shopvivaliz.com.br',
                'X-OpenRouter-Title': os.getenv('OPENROUTER_APP_TITLE') or 'ShopVivaliz',
            }
            req = urllib.request.Request(base + '/chat/completions', data=payload, headers=headers, method='POST')
            with urllib.request.urlopen(req, timeout=20) as resp:
                data = json.loads(resp.read().decode('utf-8'))
            text = data.get('choices', [{}])[0].get('message', {}).get('content')
            if text:
                return str(text), 'OpenRouter'
    except Exception as e:
        print(f'[OpenRouter error] {str(e)[:80]}', file=sys.stderr)

    return 'Agentes offline. Tente novamente em alguns minutos.', 'System'

def respond_to_messages():
    """Processar mensagens e responder"""

    chat_log = Path('logs/monitor-messages.log')
    response_log = Path('logs/monitor-responses.jsonl')

    # Criar logs dir
    Path('logs').mkdir(exist_ok=True)

    if not chat_log.exists():
        print('[INFO] Nenhuma mensagem para responder')
        return

    # Ler mensagens
    messages = []
    try:
        raw = chat_log.read_text(encoding='utf-8')
        if raw.strip():
            for line in raw.strip().split('\n'):
                if line.strip():
                    messages.append(json.loads(line))
    except Exception as e:
        print(f'[ERROR] Lendo chat_log: {e}')
        return

    # Respostas já feitas
    responded_timestamps = set()
    if response_log.exists():
        try:
            with open(response_log) as f:
                for line in f:
                    if line.strip():
                        r = json.loads(line)
                        responded_timestamps.add(r.get('message_timestamp'))
        except Exception as e:
            print(f'[ERROR] Lendo response_log: {e}')

    # Responder mensagens novas
    new_responses = []
    for msg in messages:
        timestamp = msg.get('timestamp')
        if timestamp and timestamp not in responded_timestamps:
            user_msg = msg.get('message', '')

            print(f'[RESPONDING] {user_msg[:50]}...')

            # Obter resposta real
            response_text, agent_name = get_agent_response(user_msg)

            # Salvar resposta
            response_record = {
                'timestamp': datetime.now().isoformat(),
                'message_timestamp': timestamp,
                'user_message': user_msg,
                'agent_response': response_text,
                'agent': agent_name
            }

            new_responses.append(response_record)
            responded_timestamps.add(timestamp)

            print(f'[OK] Respondido por {agent_name}')

    # Escrever novas respostas
    if new_responses:
        try:
            with open(response_log, 'a') as f:
                for r in new_responses:
                    f.write(json.dumps(r) + '\n')
            print(f'[OK] {len(new_responses)} respostas salvas')
        except Exception as e:
            print(f'[ERROR] Salvando respostas: {e}')
    else:
        print('[INFO] Nenhuma resposta necessaria')

if __name__ == '__main__':
    try:
        respond_to_messages()
    except Exception as e:
        print(f'[FATAL] {e}')
        sys.exit(1)
