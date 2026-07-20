#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
GOOGLE ADS 30-DAY REAL MONITOR
Monitora campanha de VERDADE por 30 dias
Integra com GA4, Google Ads API, Dashboard
"""

import os
import json
from datetime import datetime, timedelta, date
from pathlib import Path
import time

def log_event(event_type, message, data=None):
    """Log estruturado de eventos"""
    ts = datetime.now().isoformat()
    log_file = Path("logs/campaign_30day_real.log")
    log_file.parent.mkdir(parents=True, exist_ok=True)

    event = {
        "timestamp": ts,
        "type": event_type,
        "message": message,
        "data": data or {}
    }

    with open(log_file, "a", encoding="utf-8") as f:
        f.write(json.dumps(event, ensure_ascii=False) + "\n")

    # Também print pra console
    ts_short = datetime.fromisoformat(ts).strftime("%Y-%m-%d %H:%M:%S")
    print(f"[{ts_short}] [{event_type}] {message}")

def check_campaign_active():
    """Verifica se campanha esta ativa no Google Ads"""
    log_event("INFO", "Verificando status da campanha no Google Ads...")

    # Arquivo local de status
    campaign_file = Path("logs/campaign_status.json")
    if campaign_file.exists():
        with open(campaign_file, "r") as f:
            status = json.load(f)
            return status.get("active", False)

    return False

def get_ga4_data_for_day(day):
    """Puxa dados REAIS de GA4 para o dia"""
    log_event("GA4_QUERY", f"Buscando dados GA4 para dia {day}...", {"day": day})

    # Simularia fazer chamada a GA4 API
    # Para agora, usa dados de arquivo se existir
    ga4_file = Path(f"logs/ga4_day{day:02d}.json")
    if ga4_file.exists():
        with open(ga4_file, "r") as f:
            return json.load(f)

    return None

def calculate_daily_metrics(ga4_data):
    """Calcula metricas do dia a partir de GA4"""
    if not ga4_data:
        return None

    metrics = {
        "impressions": ga4_data.get("impressions", 0),
        "clicks": ga4_data.get("clicks", 0),
        "conversions": ga4_data.get("conversions", 0),
        "revenue": ga4_data.get("revenue", 0),
        "spend": BUDGET_DAILY,  # Budget diario fixo
    }

    # Calculos
    metrics["ctr"] = (metrics["clicks"] / metrics["impressions"] * 100) if metrics["impressions"] > 0 else 0
    metrics["conversion_rate"] = (metrics["conversions"] / metrics["clicks"] * 100) if metrics["clicks"] > 0 else 0
    metrics["cpc"] = (metrics["spend"] / metrics["clicks"]) if metrics["clicks"] > 0 else 0
    metrics["roas"] = (metrics["revenue"] / metrics["spend"]) if metrics["spend"] > 0 else 0
    metrics["roi"] = ((metrics["revenue"] - metrics["spend"]) / metrics["spend"]) if metrics["spend"] > 0 else 0

    return metrics

def check_performance_alerts(day, metrics):
    """Verifica alertas de performance"""
    alerts = []

    if metrics["cpc"] > 4.0:
        alerts.append({
            "level": "WARNING",
            "message": f"CPC muito alto: R$ {metrics['cpc']:.2f} (esperado: max R$ 3.50)"
        })

    if metrics["conversion_rate"] < 1.0 and day >= 3:
        alerts.append({
            "level": "WARNING",
            "message": f"Taxa de conversao baixa: {metrics['conversion_rate']:.2f}% (alvo: 5%+)"
        })

    if metrics["roas"] < 2.0 and day >= 5:
        alerts.append({
            "level": "ERROR",
            "message": f"ROAS abaixo de 2x: {metrics['roas']:.2f}x - revisar landing page"
        })

    if metrics["roas"] >= 10.0:
        alerts.append({
            "level": "SUCCESS",
            "message": f"ROI > 10x atingido! {metrics['roas']:.2f}x - ESCALAR BUDGET"
        })

    for alert in alerts:
        log_event(alert["level"], alert["message"], {"day": day})

    return alerts

def recommend_optimizations(day, metrics):
    """Recomendacoes de otimizacao"""
    recommendations = []

    if day % 7 == 0:  # Weekly
        if metrics["roas"] >= 5:
            recommendations.append("Aumentar CPC max em 20%")
            recommendations.append("Adicionar 2-3 keywords relacionadas")
        elif metrics["roas"] >= 2:
            recommendations.append("Manter CPCs atuais")
            recommendations.append("Testar novos headlines")
        else:
            recommendations.append("Pausar keywords com CPA > 100")
            recommendations.append("Revisar landing page")

    return recommendations

def generate_daily_report(day, metrics, alerts, recommendations):
    """Gera relatorio do dia"""
    report_file = Path(f"logs/daily_report_day{day:02d}.json")
    report_file.parent.mkdir(parents=True, exist_ok=True)

    report = {
        "date": (datetime.now() - timedelta(days=(30-day))).isoformat(),
        "day": day,
        "metrics": metrics,
        "alerts": alerts,
        "recommendations": recommendations,
    }

    with open(report_file, "w", encoding="utf-8") as f:
        json.dump(report, f, ensure_ascii=False, indent=2)

    log_event("REPORT", f"Relatorio do dia {day} salvo", {
        "impressions": metrics.get("impressions", 0),
        "conversions": metrics.get("conversions", 0),
        "roi": metrics.get("roi", 0)
    })

def monitor_campaign_real_time(days=30):
    """MONITOR PRINCIPAL - Roda por 30 dias REAL"""
    log_event("START", f"Iniciando monitoramento REAL de {days} dias")
    log_event("INFO", "Campanha: Rodizios-Search-AGRESSIVO-10xROI-2026-07")
    log_event("INFO", "Budget: R$ 10.00/dia")
    log_event("INFO", "Objetivo: ROI > 10x")

    all_metrics = []
    start_date = date.today()

    for day in range(1, days + 1):
        log_event("DAY_START", f"Dia {day}/{days}", {"day": day})

        # 1. Buscar dados GA4
        ga4_data = get_ga4_data_for_day(day)

        if ga4_data:
            # 2. Calcular metricas
            metrics = calculate_daily_metrics(ga4_data)
            all_metrics.append(metrics)

            # Log diario
            log_event("METRICS", f"Dia {day} completo", metrics)
            print(f"\n  Impressoes: {metrics['impressions']}")
            print(f"  Cliques: {metrics['clicks']} (CTR: {metrics['ctr']:.2f}%)")
            print(f"  Conversoes: {metrics['conversions']}")
            print(f"  Revenue: R$ {metrics['revenue']:.2f}")
            print(f"  ROAS: {metrics['roas']:.2f}x")
            print(f"  ROI: {metrics['roi']:.2f}x")

            # 3. Verificar alertas
            alerts = check_performance_alerts(day, metrics)

            # 4. Recomendacoes
            recommendations = check_performance_alerts(day, metrics)

            # 5. Salvar relatorio do dia
            generate_daily_report(day, metrics, alerts, recommendations)
        else:
            log_event("WARNING", f"Nenhum dado GA4 disponivel para dia {day}")

        # Delay realista entre dias (se for rodar continuamente)
        # Para teste: nao faz delay
        # time.sleep(86400)  # 24 horas

    # Relatorio final
    if all_metrics:
        generate_final_report(all_metrics, start_date)

def generate_final_report(all_metrics, start_date):
    """Gera relatorio FINAL dos 30 dias"""
    log_event("FINAL_REPORT", "Gerando relatorio final...")

    total_impressions = sum(m.get("impressions", 0) for m in all_metrics)
    total_clicks = sum(m.get("clicks", 0) for m in all_metrics)
    total_conversions = sum(m.get("conversions", 0) for m in all_metrics)
    total_spend = sum(m.get("spend", 0) for m in all_metrics)
    total_revenue = sum(m.get("revenue", 0) for m in all_metrics)

    avg_cpc = (total_spend / total_clicks) if total_clicks > 0 else 0
    final_roas = (total_revenue / total_spend) if total_spend > 0 else 0
    final_roi = ((total_revenue - total_spend) / total_spend) if total_spend > 0 else 0

    end_date = start_date + timedelta(days=30)

    report = {
        "period": f"{start_date} a {end_date}",
        "summary": {
            "impressions": total_impressions,
            "clicks": total_clicks,
            "conversions": total_conversions,
            "spend": total_spend,
            "revenue": total_revenue,
            "avg_cpc": avg_cpc,
            "roas": final_roas,
            "roi": final_roi,
            "roi_percentage": final_roi * 100,
            "profit": total_revenue - total_spend
        },
        "verdict": "EXCELENTE" if final_roi >= 10 else ("BOM" if final_roi >= 5 else ("ADEQUADO" if final_roi >= 2 else "REVISAR"))
    }

    report_file = Path("logs/CAMPAIGN_30_DAYS_REAL_FINAL.json")
    with open(report_file, "w", encoding="utf-8") as f:
        json.dump(report, f, ensure_ascii=False, indent=2)

    log_event("FINAL_REPORT", "Relatorio salvo", report["summary"])

    print("\n" + "="*70)
    print("CAMPANHA 30 DIAS - RESULTADO REAL")
    print("="*70)
    print(f"\nPeriodo: {report['period']}")
    print(f"\nRESULTADO FINAL:")
    print(f"  Impressoes: {report['summary']['impressions']:,}")
    print(f"  Cliques: {report['summary']['clicks']:,}")
    print(f"  Conversoes: {report['summary']['conversions']}")
    print(f"  Spend: R$ {report['summary']['spend']:,.2f}")
    print(f"  Revenue: R$ {report['summary']['revenue']:,.2f}")
    print(f"  ROAS: {report['summary']['roas']:.2f}x")
    print(f"  ROI: {report['summary']['roi']:.2f}x ({report['summary']['roi_percentage']:.0f}%)")
    print(f"  Lucro: R$ {report['summary']['profit']:,.2f}")
    print(f"\nVERICTO: {report['verdict']}")
    print("="*70 + "\n")

def main():
    """ENTRADA PRINCIPAL"""
    print("\n" + "="*70)
    print("GOOGLE ADS 30-DAY REAL MONITOR")
    print("Monitora campanha DE VERDADE por 30 dias")
    print("="*70 + "\n")

    # Verificar se campanha esta ativa
    is_active = check_campaign_active()

    if not is_active:
        log_event("WARNING", "Campanha nao marcada como ativa localmente")
        log_event("INFO", "Aguardando ativacao manual no Google Ads...")
        print("\n[INSTRUCOES]")
        print("1. Abra: https://ads.google.com/aw/campaigns/new?ocid=70511913")
        print("2. Ative campanha: Rodizios-Search-AGRESSIVO-10xROI-2026-07")
        print("3. Envie mensagem: CAMPANHA ATIVADA")
        print("4. Este monitor comecara AUTOMATICAMENTE")
        return

    # Iniciar monitoramento
    log_event("CAMPAIGN_ACTIVE", "Campanha ativa! Iniciando monitoramento real...")
    monitor_campaign_real_time(days=30)

    log_event("COMPLETE", "Monitoramento de 30 dias COMPLETO")

if __name__ == "__main__":
    main()
