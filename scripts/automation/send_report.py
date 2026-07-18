#!/usr/bin/env python3
"""
Enviar relatório de automação por email
"""

import os
import json
import csv
import smtplib
import ssl
from email.mime.text import MIMEText
from datetime import datetime

class ReportSender:
    def __init__(self):
        self.smtp_host = os.getenv('SMTP_HOST') or os.getenv('EMAIL_SMTP_HOST') or os.getenv('MAIL_HOST') or ''
        self.smtp_user = os.getenv('SMTP_USER') or os.getenv('EMAIL_USER') or os.getenv('MAIL_USER') or ''
        self.smtp_pass = os.getenv('SMTP_PASS') or os.getenv('EMAIL_PASSWORD') or os.getenv('MAIL_PASS') or ''
        self.email_to = os.getenv('EMAIL_TO', '')

    def send_daily_report(self):
        """Envia relatório diário"""
        print("\n[EMAIL] Gerando e enviando relatório")
        print("="*70)

        report = self._generate_report()
        email_body = self._format_email(report)

        subject = f"Relatório Automação - {datetime.now().strftime('%Y-%m-%d')}"
        print(f"Destinatário: {self.email_to}")
        print(f"Assunto: {subject}")
        print("\nConteúdo do email:")
        print("-"*70)
        print(email_body)
        print("-"*70)
        self._persist_report(email_body)

        sent = self._send_smtp(subject, email_body)
        if sent:
            print("\n[OK] Email enviado com sucesso via SMTP")
        else:
            print("\n[ERRO] Email NAO foi enviado (ver mensagem de erro acima)")
        print("="*70)
        return sent

    def _send_smtp(self, subject: str, body: str) -> bool:
        """Envia o relatorio por SMTP real. Retorna False honestamente (nao
        finge sucesso) se faltar config ou a conexao falhar."""
        if not (self.smtp_host and self.smtp_user and self.smtp_pass and self.email_to):
            print("[ERRO] SMTP nao configurado (faltam SMTP_HOST/SMTP_USER/SMTP_PASS/EMAIL_TO)")
            return False

        smtp_port = int(os.getenv('SMTP_PORT') or os.getenv('MAIL_PORT') or 587)
        msg = MIMEText(body, 'plain', 'utf-8')
        msg['Subject'] = subject
        msg['From'] = self.smtp_user
        msg['To'] = self.email_to

        try:
            context = ssl.create_default_context()
            with smtplib.SMTP(self.smtp_host, smtp_port, timeout=20) as server:
                server.starttls(context=context)
                server.login(self.smtp_user, self.smtp_pass)
                server.sendmail(self.smtp_user, [self.email_to], msg.as_string())
            return True
        except Exception as e:
            print(f"[ERRO] Falha ao enviar email via SMTP: {e}")
            return False

    def _generate_report(self):
        """Gera dados do relatório"""
        report = {
            'timestamp': datetime.now().isoformat(),
            'statistics': self._get_statistics(),
            'performance': self._get_performance_data(),
            'recommendations': self._get_recommendations()
        }
        return report

    def _get_statistics(self):
        """Estatísticas da execução"""
        try:
            with open('logs/performance.csv', 'r') as f:
                rows = list(csv.DictReader(f))
                return {
                    'total_products': len(rows),
                    'avg_seo_score': sum(float(r.get('seo_score', 0)) for r in rows) / len(rows) if rows else 0,
                    'avg_ctr': sum(float(r.get('ctr', 0)) for r in rows) / len(rows) if rows else 0,
                    'total_sales': sum(float(r.get('sales', 0)) for r in rows) if rows else 0,
                }
        except Exception:
            return {}

    def _get_performance_data(self):
        """Dados de performance"""
        try:
            with open('logs/validation_report.json', 'r') as f:
                return json.load(f)
        except Exception:
            return {}

    def _get_recommendations(self):
        """Recomendações automáticas"""
        recommendations = [
            'Produtos com SEO < 70: melhorar keywords',
            'Imagens com CTR < 8%: regenerar com IA',
            'Teste A/B: continuar rodando por mais 7 dias',
            'TikTok: aumentar apelo emocional',
            'Shopee: incentivar avaliacoes reais pos-compra e destacar provas sociais verificadas',
        ]
        return recommendations

    def _format_email(self, report):
        """Formata email"""
        stats = report.get('statistics', {})

        email = f"""
RELATORIO DE AUTOMACAO SHOPVIVALIZ
{'='*70}

Data: {report['timestamp']}

ESTATISTICAS:
- Produtos Processados: {stats.get('total_products', 0)}
- SEO Score Médio: {stats.get('avg_seo_score', 0):.1f}/100
- CTR Médio: {stats.get('avg_ctr', 0)*100:.2f}%
- Total de Vendas: {stats.get('total_sales', 0):.0f}

RECOMENDACOES:
"""

        for i, rec in enumerate(report.get('recommendations', []), 1):
            email += f"\n{i}. {rec}"

        email += f"\n\n{'='*70}\nSistema Autônomo 24/7\nPróxima execução: em 6 horas"

        return email

    def _persist_report(self, email_body):
        os.makedirs('logs', exist_ok=True)
        with open('logs/automation-email-report.txt', 'w', encoding='utf-8') as f:
            f.write(email_body.strip() + "\n")

# CLI
if __name__ == '__main__':
    sender = ReportSender()
    sender.send_daily_report()
