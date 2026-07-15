#!/usr/bin/env python3
"""
Teste as APIs dos agentes para ver qual está funcionando
"""
import os
import json

print("=" * 60)
print("TESTANDO APIS DOS AGENTES")
print("=" * 60)

# Verificar chaves
gemini_key = os.getenv('GEMINI_API_KEY')
claude_key = os.getenv('ANTHROPIC_API_KEY')
openai_key = os.getenv('OPENAI_API_KEY')

print(f"\n[CHECK] Chaves de API:")
print(f"  GEMINI_API_KEY: {'OK' if gemini_key else 'FALTANDO'}")
print(f"  ANTHROPIC_API_KEY: {'OK' if claude_key else 'FALTANDO'}")
print(f"  OPENAI_API_KEY: {'OK' if openai_key else 'FALTANDO'}")

# Testar Gemini
print(f"\n[TEST 1] Gemini API (gemini-2.5-flash)")
try:
    import google.genai
    client = google.genai.Client(api_key=gemini_key)
    response = client.models.generate_content(
        model='gemini-2.5-flash',
        contents='Responda com "Gemini OK" apenas'
    )
    print(f"  [OK] Resposta: {response.text[:50]}")
except Exception as e:
    print(f"  [ERRO] {str(e)[:100]}")

# Testar Claude
print(f"\n[TEST 2] Claude API (claude-sonnet-4-6)")
try:
    import anthropic
    client = anthropic.Anthropic(api_key=claude_key)
    response = client.messages.create(
        model='claude-sonnet-4-6',
        max_tokens=50,
        messages=[{'role': 'user', 'content': 'Responda com "Claude OK" apenas'}]
    )
    print(f"  [OK] Resposta: {response.content[0].text[:50]}")
except Exception as e:
    print(f"  [ERRO] {str(e)[:100]}")

# Testar OpenAI
print(f"\n[TEST 3] OpenAI API (gpt-4o-mini)")
try:
    import openai
    client = openai.OpenAI(api_key=openai_key)
    response = client.chat.completions.create(
        model='gpt-4o-mini',
        messages=[{'role': 'user', 'content': 'Responda com "OpenAI OK" apenas'}],
        max_tokens=50
    )
    print(f"  [OK] Resposta: {response.choices[0].message.content[:50]}")
except Exception as e:
    print(f"  [ERRO] {str(e)[:100]}")

print("\n" + "=" * 60)
print("RESULTADO: Se todas as 3 mostrarem [OK], as APIs estao OK")
print("=" * 60)
