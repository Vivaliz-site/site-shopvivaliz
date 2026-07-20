# 🔍 ShopVivaliz 24/7 Audit Monitor

Sistema de auditoria contínua com alertas automáticos.

## ✅ Ativado Agora

### GitHub Actions (Automático)
- Roda a cada 30 minutos
- Monitora endpoints críticos
- Alerta por email se RED
- Logs em `/logs/audit-24-7-YYYY-MM-DD.log`

### VM Oracle (Manual)
```bash
ssh ubuntu@137.131.156.17
cd /home/ubuntu/site-shopvivaliz
bash deploy/install-audit-monitor-24-7.sh
```

## 📊 Monitorado

- ✅ Endpoints (Home, Catalog, Checkout)
- ✅ Olist Sync (Orders → ERP)
- ✅ Token Refresh automático
- ✅ Email Alerts

## 📋 Logs

`logs/audit-24-7-2026-07-14.log` → JSON estruturado
`logs/health-status-latest.json` → Status atual

## 🚨 Alertas

Email automático quando status muda para RED:
- Para: EMAIL_TO (.env)
- Subject: 🚨 ShopVivaliz Alert
- Link: https://shopvivaliz.com.br/admin/monitor/

Status: ✅ Production-Ready (2026-07-14)
