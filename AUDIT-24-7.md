# 🔍 ShopVivaliz 24/7 Audit Monitor

Sistema de auditoria contínua de produção com monitoramento, alertas automáticos e auto-recovery.

## 🚀 Quick Start

### 1. GitHub Actions (Automático - já ativo)
Roda a cada 30 minutos via cron workflow:
- **Arquivo:** `.github/workflows/audit-monitor-24-7-cron.yml`
- **Logs:** GitHub Actions UI → Workflow "24/7 Audit Monitor"
- **Status:** Dashboard em https://dev.shopvivaliz.com.br/admin/monitor/

### 2. VM Oracle (Local - Manual Setup)
Instalar monitor direto na VM para redundância:

```bash
ssh -i <chave> ubuntu@137.131.156.17
cd /home/ubuntu/site-shopvivaliz
bash deploy/install-audit-monitor-24-7.sh
```

Depois:
```bash
# Status
sudo systemctl status shopvivaliz-audit-monitor.timer

# Logs em tempo real
tail -f logs/audit-monitor-service.log
tail -f logs/audit-24-7-$(date +%Y-%m-%d).log

# Health JSON (atualizado a cada 30 min)
cat logs/health-status-latest.json | jq .
```

---

## 📊 O Que é Monitorado

### ✅ Endpoints Críticos (a cada 30 min)
- `GET /` → Homepage (200)
- `GET /catalogo.php` → Catálogo (200)
- `GET /checkout/index.php` → Checkout (200)

### ✅ Integrações (a cada 30 min)
- **Olist:** Sync de pedidos com ERP
- **Shopee:** Status de conexão
- **MelhorEnvio:** API de frete
- **Catálogo:** Stock + Prices
- **Pedidos:** Order creation health

### ✅ Token Management (a cada 30 min)
- Verifica expiração de tokens
- Renova automaticamente se necessário
- Logs de refresh com timestamps

### ✅ Email Alerts
- Disparado quando status muda para RED
- Enviado para `EMAIL_TO` (configurado em .env)
- Inclui link direto para admin monitor

---

## 📋 Estrutura de Logs

```
logs/
├── audit-24-7-2026-07-14.log        ← Eventos estruturados (JSON)
├── audit-24-7-2026-07-15.log        ← Um arquivo por dia
├── health-status-latest.json        ← Status atual (atualizado a cada 30 min)
└── audit-monitor-service.log        ← Logs do serviço systemd (VM)
```

### Exemplo audit-24-7-*.log
```json
{"timestamp":"2026-07-14T10:30:00+00:00","type":"endpoint_check","status":"ok","endpoint":"Home","path":"/","status_code":200,"expected":200}
{"timestamp":"2026-07-14T10:30:05+00:00","type":"olist_sync","status":"ok","data":{"orders_synced":42,"last_sync":"2026-07-14T10:25:00"}}
{"timestamp":"2026-07-14T10:30:30+00:00","type":"token_refresh","status":"ok","token":"olist","refresh_rotated":true}
```

### Exemplo health-status-latest.json
```json
{
  "timestamp": "2026-07-14T10:30:45+00:00",
  "overall_status": "green",
  "endpoints_ok": true,
  "olist_sync": "ok",
  "integrations": {
    "olist": true,
    "shopee": true,
    "melhorenvio": true,
    "catalog": true,
    "orders": true
  }
}
```

---

## 🚨 Alertas Automáticos

Quando `overall_status` fica RED:
1. ✉️ Email enviado para `EMAIL_TO`
2. 📋 Log registrado em `audit-24-7-*.log`
3. 🔄 Retry automático (max 3x)
4. 📊 Dashboard atualizado em tempo real

### Email de Alerta
```
Subject: 🚨 ShopVivaliz Alert: Production Issue Detected
Body:
  ⚠️ Problema detectado em produção
  Status Geral: RED
  Endpoints: ❌ ERRO
  Sync Olist: fail
  Hora: 2026-07-14T10:30:00+00:00
  Action: Verifique https://dev.shopvivaliz.com.br/admin/monitor/
```

---

## 🔄 Auto-Recovery

### Token Expirado
```
Detectado: 2026-07-14 10:30:00
Ação: GET /api/olist/refresh-token.php
Resultado: ✅ Novo token salvo em .env
Retry: Próxima check em 30 min
```

### Endpoint Fora
```
Detectado: 2026-07-14 10:30:05
Endpoint: /api/orders/health.php (timeout)
Ação: 1. Log em audit-24-7-*.log
      2. Status atualizado para RED
      3. Alerta enviado
      4. Retry automático em 5 min
```

---

## 🛠️ Configuração Manual

### Via .env
```bash
# SMTP para alertas
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=shopvivaliz@gmail.com
SMTP_PASS=ukts yplc vtij jjpx
EMAIL_FROM=shopvivaliz@gmail.com
EMAIL_TO=fredmourao@gmail.com,atendimento@shopvivaliz.com.br
```

### Via GitHub Secrets (optional)
```
SMTP_HOST
SMTP_PORT
SMTP_USER
SMTP_PASS
EMAIL_TO
EMAIL_FROM
```

---

## 📈 Dashboard Admin

Acesse: https://dev.shopvivaliz.com.br/admin/monitor/

Mostra em tempo real:
- ✅/❌ Status de cada endpoint
- 📊 Gráficos de uptime (24h)
- 📋 Últimos 50 eventos
- 🔔 Alertas recent
- 🔗 Links rápidos para logs

---

## 🔌 Integração com Outros Sistemas

### GitHub Actions
- CI/CD sabe status de produção
- Pode bloquear deploys se RED
- Enviar resultados para Slack (futuro)

### Logs em Storage
```bash
# Sincronizar logs para bucket
gsutil -m cp logs/audit-24-7-*.log gs://shopvivaliz-logs/
```

### Webhook para Observabilidade
```bash
# POST para serviço externo
curl -X POST https://monitoring.internal/webhook \
  -H "Content-Type: application/json" \
  -d @logs/health-status-latest.json
```

---

## 🐛 Troubleshooting

### Monitor não roda via systemd
```bash
# Status
sudo systemctl status shopvivaliz-audit-monitor.timer

# Logs
sudo journalctl -u shopvivaliz-audit-monitor.service -n 50

# Reiniciar
sudo systemctl restart shopvivaliz-audit-monitor.timer
```

### Email não é enviado
```bash
# Verificar config SMTP em .env
grep SMTP .env

# Testar manualmente
python3 scripts/audit-monitor-24-7.py
# Deve aparecer em logs/audit-24-7-*.log
```

### Logs não atualizam
```bash
# Verificar permissions
ls -la logs/

# Dar permissão se necessário
chmod 755 logs/
sudo chown ubuntu:ubuntu logs/ -R
```

---

## 📞 Suporte

**Para ativar agora:**
```bash
# Local (seu PC)
python3 scripts/audit-monitor-24-7.py

# GitHub Actions já está ativo
# Acompanhe em: https://github.com/Vivaliz-site/site-shopvivaliz/actions

# VM Oracle (quando quiser redundância)
bash deploy/install-audit-monitor-24-7.sh
```

**Logs em tempo real:**
```bash
# GitHub Actions
gh run list -w "24/7 Audit Monitor" -L 1

# VM
ssh ubuntu@137.131.156.17 'tail -f /home/ubuntu/site-shopvivaliz/logs/audit-24-7-$(date +%Y-%m-%d).log'
```

---

**Status:** ✅ Production-Ready (2026-07-14)
**Última atualização:** 2026-07-14 10:36:00 UTC
