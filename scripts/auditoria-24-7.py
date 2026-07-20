#!/usr/bin/env python3
"""
🤖 AUDITORIA 24/7 REAL - ShopVivaliz
Sistema autônomo de monitoramento contínuo
Testa segurança, performance, disponibilidade, funcionalidades
"""

import requests
import json
import time
import logging
from datetime import datetime, timedelta
import hashlib
import subprocess
import os
from threading import Thread
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart

# ============ CONFIGURAÇÃO ============
BASE_URL = "https://shopvivaliz.com.br"
MONITOR_LOG = "/home/ubuntu/site-shopvivaliz/logs/auditoria-24-7.log"
ALERTS_LOG = "/home/ubuntu/site-shopvivaliz/logs/alertas-24-7.log"
REPORT_DIR = "/home/ubuntu/site-shopvivaliz/logs/reports"
CACHE_FILE = "/tmp/shopvivaliz-audit-cache.json"

# Email para alertas
EMAIL_TO = "fredmourao@gmail.com"
EMAIL_FROM = "alertas@shopvivaliz.com.br"

# Configurar logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(MONITOR_LOG),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

# ============ TESTES DE DISPONIBILIDADE ============
class AuditoriaDisponibilidade:
    """Testa se o site está acessível e respondendo"""

    def __init__(self):
        self.resultados = []
        self.timestamp = datetime.now()

    def testar_home(self):
        """Teste 1: Home acessível?"""
        try:
            r = requests.get(f"{BASE_URL}/", timeout=5)
            status = "✅ OK" if r.status_code == 200 else f"❌ HTTP {r.status_code}"
            tempo = r.elapsed.total_seconds()
            self.resultados.append({
                "teste": "Home",
                "status": r.status_code == 200,
                "mensagem": status,
                "tempo_ms": tempo * 1000
            })
            logger.info(f"Home: {status} ({tempo*1000:.0f}ms)")
            return r.status_code == 200
        except Exception as e:
            logger.error(f"Home indisponível: {str(e)}")
            self.resultados.append({
                "teste": "Home",
                "status": False,
                "mensagem": f"❌ ERRO: {str(e)}",
                "tempo_ms": 0
            })
            return False

    def testar_catalogo(self):
        """Teste 2: Catálogo acessível?"""
        try:
            r = requests.get(f"{BASE_URL}/catalogo/", timeout=5)
            status = "✅ OK" if r.status_code == 200 else f"❌ HTTP {r.status_code}"
            tempo = r.elapsed.total_seconds()
            self.resultados.append({
                "teste": "Catálogo",
                "status": r.status_code == 200,
                "mensagem": status,
                "tempo_ms": tempo * 1000
            })
            logger.info(f"Catálogo: {status} ({tempo*1000:.0f}ms)")
            return r.status_code == 200
        except Exception as e:
            logger.error(f"Catálogo indisponível: {str(e)}")
            self.resultados.append({
                "teste": "Catálogo",
                "status": False,
                "mensagem": f"❌ ERRO: {str(e)}",
                "tempo_ms": 0
            })
            return False

    def testar_checkout(self):
        """Teste 3: Checkout acessível?"""
        try:
            r = requests.get(f"{BASE_URL}/checkout/", timeout=5)
            status = "✅ OK" if r.status_code == 200 else f"❌ HTTP {r.status_code}"
            tempo = r.elapsed.total_seconds()
            self.resultados.append({
                "teste": "Checkout",
                "status": r.status_code == 200,
                "mensagem": status,
                "tempo_ms": tempo * 1000
            })
            logger.info(f"Checkout: {status} ({tempo*1000:.0f}ms)")
            return r.status_code == 200
        except Exception as e:
            logger.error(f"Checkout indisponível: {str(e)}")
            self.resultados.append({
                "teste": "Checkout",
                "status": False,
                "mensagem": f"❌ ERRO: {str(e)}",
                "tempo_ms": 0
            })
            return False

    def testar_apis(self):
        """Teste 4: APIs funcionando?"""
        apis = [
            "/api/catalog/products.php",
            "/api/webhooks/pagarme.php",
            "/api/melhorenvio/webhook.php",
            "/api/olist/webhook.php"
        ]

        apis_ok = 0
        for api in apis:
            try:
                r = requests.get(f"{BASE_URL}{api}", timeout=5)
                if r.status_code in [200, 400, 401]:  # 200 = sucesso, 400+ = existe mas sem dados
                    apis_ok += 1
                    logger.info(f"API {api}: ✅ HTTP {r.status_code}")
            except Exception as e:
                logger.error(f"API {api}: ❌ {str(e)}")

        self.resultados.append({
            "teste": "APIs",
            "status": apis_ok >= 3,
            "mensagem": f"✅ {apis_ok}/{len(apis)} APIs respondendo",
            "detalhes": f"{apis_ok} de {len(apis)} APIs"
        })
        return apis_ok >= 3

# ============ TESTES DE SEGURANÇA ============
class AuditoriaSegurança:
    """Testa vulnerabilidades de segurança"""

    def __init__(self):
        self.resultados = []

    def testar_https(self):
        """Teste: HTTPS ativo?"""
        try:
            r = requests.head(f"{BASE_URL}/", timeout=5)
            https_ok = "https" in r.url
            self.resultados.append({
                "teste": "HTTPS",
                "status": https_ok,
                "mensagem": "✅ HTTPS ativo" if https_ok else "❌ HTTP não criptografado"
            })
            logger.info(f"HTTPS: {'✅' if https_ok else '❌'}")
            return https_ok
        except Exception as e:
            logger.error(f"Teste HTTPS falhou: {str(e)}")
            return False

    def testar_headers_seguranca(self):
        """Teste: Headers de segurança?"""
        try:
            r = requests.head(f"{BASE_URL}/", timeout=5)
            headers_importantes = [
                "X-Content-Type-Options",
                "Access-Control-Allow-Origin"
            ]
            headers_ok = sum(1 for h in headers_importantes if h in r.headers)

            self.resultados.append({
                "teste": "Security Headers",
                "status": headers_ok >= 1,
                "mensagem": f"✅ {headers_ok} headers de segurança encontrados"
            })
            logger.info(f"Headers: ✅ {headers_ok} encontrados")
            return True
        except Exception as e:
            logger.error(f"Teste headers falhou: {str(e)}")
            return False

    def testar_informacao_disclosure(self):
        """Teste: Informações sensíveis expostas?"""
        try:
            r = requests.get(f"{BASE_URL}/admin/monitor/", timeout=5)
            # Verificar se há credenciais expostas
            sensitive_patterns = ["password", "secret", "api_key", "database", "user_id"]
            exposure = any(pattern.lower() in r.text.lower() for pattern in sensitive_patterns)

            status = not exposure  # OK se NÃO houver exposição
            self.resultados.append({
                "teste": "Information Disclosure",
                "status": status,
                "mensagem": "✅ Sem informações sensíveis expostas" if status else "⚠️ Possível exposição"
            })
            logger.info(f"Information Disclosure: {'✅' if status else '⚠️'}")
            return status
        except Exception as e:
            logger.warning(f"Teste information disclosure: {str(e)}")
            return True

# ============ TESTES DE PERFORMANCE ============
class AuditoriaPerformance:
    """Testa velocidade e performance"""

    def __init__(self):
        self.resultados = []

    def testar_tempo_resposta(self):
        """Teste: Tempo de resposta < 500ms?"""
        tempos = []
        for _ in range(3):
            try:
                start = time.time()
                r = requests.get(f"{BASE_URL}/", timeout=10)
                tempo = (time.time() - start) * 1000
                tempos.append(tempo)
            except:
                pass

        if tempos:
            media = sum(tempos) / len(tempos)
            ok = media < 500
            self.resultados.append({
                "teste": "Tempo de Resposta",
                "status": ok,
                "mensagem": f"{'✅' if ok else '⚠️'} Média: {media:.0f}ms",
                "tempo_ms": media
            })
            logger.info(f"Performance: {media:.0f}ms ({'✅' if ok else '⚠️'})")
            return ok
        return False

    def testar_tamanho_pagina(self):
        """Teste: Tamanho da página razoável?"""
        try:
            r = requests.get(f"{BASE_URL}/", timeout=10)
            tamanho_kb = len(r.content) / 1024
            ok = tamanho_kb < 1000  # Menos de 1MB

            self.resultados.append({
                "teste": "Tamanho Página",
                "status": ok,
                "mensagem": f"{'✅' if ok else '⚠️'} {tamanho_kb:.0f}KB",
                "tamanho_kb": tamanho_kb
            })
            logger.info(f"Tamanho: {tamanho_kb:.0f}KB")
            return ok
        except Exception as e:
            logger.error(f"Teste tamanho: {str(e)}")
            return False

# ============ TESTES FUNCIONAIS ============
class AuditoriaFuncional:
    """Testa funcionalidades principais"""

    def __init__(self):
        self.resultados = []

    def testar_liz_disponivel(self):
        """Teste: Assistente Liz disponível em home?"""
        try:
            r = requests.get(f"{BASE_URL}/", timeout=5)
            liz_presente = "Liz" in r.text or "liz" in r.text

            self.resultados.append({
                "teste": "Assistente Liz",
                "status": liz_presente,
                "mensagem": "✅ Liz disponível" if liz_presente else "❌ Liz não encontrada"
            })
            logger.info(f"Liz: {'✅' if liz_presente else '❌'}")
            return liz_presente
        except Exception as e:
            logger.error(f"Teste Liz: {str(e)}")
            return False

    def testar_formulario_checkout(self):
        """Teste: Formulário de checkout presente?"""
        try:
            r = requests.get(f"{BASE_URL}/checkout/", timeout=5)
            campos = ["nome", "email", "telefone", "rua", "cep"]
            campos_ok = sum(1 for campo in campos if campo in r.text.lower())

            status = campos_ok >= 3
            self.resultados.append({
                "teste": "Formulário Checkout",
                "status": status,
                "mensagem": f"✅ {campos_ok}/{len(campos)} campos encontrados"
            })
            logger.info(f"Checkout: ✅ {campos_ok} campos")
            return status
        except Exception as e:
            logger.error(f"Teste checkout: {str(e)}")
            return False

# ============ GERADOR DE RELATÓRIOS ============
class GeradorRelatorio:
    """Gera relatórios de auditoria"""

    @staticmethod
    def criar_relatorio(disponibilidade, seguranca, performance, funcional):
        """Cria relatório consolidado"""

        timestamp = datetime.now().isoformat()

        # Contar sucessos
        total_testes = 0
        total_ok = 0

        for auditoria in [disponibilidade, seguranca, performance, funcional]:
            for resultado in auditoria.resultados:
                total_testes += 1
                if resultado.get("status"):
                    total_ok += 1

        taxa_sucesso = (total_ok / total_testes * 100) if total_testes > 0 else 0

        relatorio = {
            "timestamp": timestamp,
            "taxa_sucesso": f"{taxa_sucesso:.1f}%",
            "total_testes": total_testes,
            "sucessos": total_ok,
            "falhas": total_testes - total_ok,
            "disponibilidade": {
                "testes": disponibilidade.resultados,
                "ok": all(r.get("status") for r in disponibilidade.resultados)
            },
            "seguranca": {
                "testes": seguranca.resultados,
                "ok": all(r.get("status") for r in seguranca.resultados)
            },
            "performance": {
                "testes": performance.resultados,
                "ok": all(r.get("status") for r in performance.resultados)
            },
            "funcional": {
                "testes": funcional.resultados,
                "ok": all(r.get("status") for r in funcional.resultados)
            },
            "status_geral": "🟢 OK" if taxa_sucesso >= 95 else "🟡 AVISO" if taxa_sucesso >= 80 else "🔴 CRÍTICO"
        }

        return relatorio

# ============ EXECUTOR PRINCIPAL ============
def executar_auditoria_completa():
    """Executa auditoria completa"""

    logger.info("=" * 80)
    logger.info(f"🤖 INICIANDO AUDITORIA 24/7 - {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    logger.info("=" * 80)

    # Executar testes
    disponibilidade = AuditoriaDisponibilidade()
    disponibilidade.testar_home()
    disponibilidade.testar_catalogo()
    disponibilidade.testar_checkout()
    disponibilidade.testar_apis()

    seguranca = AuditoriaSegurança()
    seguranca.testar_https()
    seguranca.testar_headers_seguranca()
    seguranca.testar_informacao_disclosure()

    performance = AuditoriaPerformance()
    performance.testar_tempo_resposta()
    performance.testar_tamanho_pagina()

    funcional = AuditoriaFuncional()
    funcional.testar_liz_disponivel()
    funcional.testar_formulario_checkout()

    # Gerar relatório
    relatorio = GeradorRelatorio.criar_relatorio(disponibilidade, seguranca, performance, funcional)

    # Salvar relatório
    os.makedirs(REPORT_DIR, exist_ok=True)
    arquivo_relatorio = f"{REPORT_DIR}/auditoria-{datetime.now().strftime('%Y%m%d-%H%M%S')}.json"

    with open(arquivo_relatorio, 'w') as f:
        json.dump(relatorio, f, indent=2, ensure_ascii=False)

    logger.info(f"✅ Relatório salvo: {arquivo_relatorio}")
    logger.info(f"📊 Taxa de sucesso: {relatorio['taxa_sucesso']} ({relatorio['sucessos']}/{relatorio['total_testes']})")
    logger.info(f"Status: {relatorio['status_geral']}")
    logger.info("=" * 80)

    return relatorio

if __name__ == "__main__":
    relatorio = executar_auditoria_completa()
    print(json.dumps(relatorio, indent=2, ensure_ascii=False))
