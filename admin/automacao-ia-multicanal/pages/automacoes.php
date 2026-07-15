<?php
require_once __DIR__ . '/../../../includes/admin-guard.php';
/**
 * Gerenciamento de Automações
 * Criar, editar, ativar/desativar automações
 */
?>
<div class="automacoes-container">
    <div class="page-header">
        <h2>Automações Configuradas</h2>
        <button class="btn btn-primary" onclick="openModal('newAutomacao')">
            ➕ Nova Automação
        </button>
    </div>

    <!-- Filtros -->
    <div class="filter-bar">
        <input type="text" placeholder="Buscar automação..." class="filter-input">
        <select class="filter-select">
            <option>Todos os status</option>
            <option>Ativas</option>
            <option>Pausadas</option>
            <option>Erro</option>
        </select>
        <select class="filter-select">
            <option>Todos os canais</option>
            <option>TikTok</option>
            <option>Amazon</option>
            <option>Mercado Livre</option>
        </select>
    </div>

    <!-- Lista de Automações -->
    <div class="automacoes-list">
        <!-- Automação 1 -->
        <div class="automacao-item">
            <div class="automacao-header">
                <div class="automacao-title">
                    <h3>TikTok - Descrições com Emojis</h3>
                    <span class="status-badge active">🟢 Ativa</span>
                </div>
                <div class="automacao-actions">
                    <button class="btn-icon" title="Editar">✏️</button>
                    <button class="btn-icon" title="Pausar">⏸️</button>
                    <button class="btn-icon" title="Executar agora">▶️</button>
                    <button class="btn-icon" title="Deletar">🗑️</button>
                </div>
            </div>

            <div class="automacao-body">
                <div class="automation-config">
                    <div class="config-group">
                        <label>ERP Conectado:</label>
                        <span>Tiny ERP</span>
                    </div>
                    <div class="config-group">
                        <label>IA Utilizada:</label>
                        <span>OpenAI (GPT-4)</span>
                    </div>
                    <div class="config-group">
                        <label>Processamento de Imagem:</label>
                        <span>Cloudinary</span>
                    </div>
                    <div class="config-group">
                        <label>Frequência:</label>
                        <span>A cada 2 horas</span>
                    </div>
                </div>

                <div class="automation-prompt">
                    <h4>Prompt de IA:</h4>
                    <p>"Atue como especialista em TikTok Shop. Com base nesses dados de produto, crie uma descrição envolvente com emojis, focada em engajamento e tons casual. Máximo 150 caracteres."</p>
                </div>

                <div class="automation-channels">
                    <h4>Canais de Destino:</h4>
                    <div class="channel-tags">
                        <span class="channel-tag tiktok">TikTok Shop</span>
                    </div>
                </div>

                <div class="automation-stats">
                    <div class="stat">
                        <span class="stat-label">Produtos Processados:</span>
                        <span class="stat-value">145</span>
                    </div>
                    <div class="stat">
                        <span class="stat-label">Taxa de Sucesso:</span>
                        <span class="stat-value">96.5%</span>
                    </div>
                    <div class="stat">
                        <span class="stat-label">Última Execução:</span>
                        <span class="stat-value">há 2 horas</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Automação 2 -->
        <div class="automacao-item">
            <div class="automacao-header">
                <div class="automacao-title">
                    <h3>Amazon - Otimização SEO</h3>
                    <span class="status-badge active">🟢 Ativa</span>
                </div>
                <div class="automacao-actions">
                    <button class="btn-icon" title="Editar">✏️</button>
                    <button class="btn-icon" title="Pausar">⏸️</button>
                    <button class="btn-icon" title="Executar agora">▶️</button>
                    <button class="btn-icon" title="Deletar">🗑️</button>
                </div>
            </div>

            <div class="automacao-body">
                <div class="automation-config">
                    <div class="config-group">
                        <label>ERP Conectado:</label>
                        <span>Tiny ERP</span>
                    </div>
                    <div class="config-group">
                        <label>IA Utilizada:</label>
                        <span>OpenAI (GPT-4)</span>
                    </div>
                    <div class="config-group">
                        <label>Processamento de Imagem:</label>
                        <span>Bannerbear</span>
                    </div>
                    <div class="config-group">
                        <label>Frequência:</label>
                        <span>A cada 4 horas</span>
                    </div>
                </div>

                <div class="automation-prompt">
                    <h4>Prompt de IA:</h4>
                    <p>"Criar título otimizado para Amazon com palavras-chave. Incluir especificações técnicas. Máximo 200 caracteres. Sem emojis. Foco em SEO."</p>
                </div>

                <div class="automation-channels">
                    <h4>Canais de Destino:</h4>
                    <div class="channel-tags">
                        <span class="channel-tag amazon">Amazon</span>
                    </div>
                </div>

                <div class="automation-stats">
                    <div class="stat">
                        <span class="stat-label">Produtos Processados:</span>
                        <span class="stat-value">128</span>
                    </div>
                    <div class="stat">
                        <span class="stat-label">Taxa de Sucesso:</span>
                        <span class="stat-value">92.2%</span>
                    </div>
                    <div class="stat">
                        <span class="stat-label">Última Execução:</span>
                        <span class="stat-value">há 4 horas</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Automação 3 -->
        <div class="automacao-item">
            <div class="automacao-header">
                <div class="automacao-title">
                    <h3>Mercado Livre - Descrição Padrão</h3>
                    <span class="status-badge paused">🟡 Pausada</span>
                </div>
                <div class="automacao-actions">
                    <button class="btn-icon" title="Editar">✏️</button>
                    <button class="btn-icon" title="Ativar">▶️</button>
                    <button class="btn-icon" title="Deletar">🗑️</button>
                </div>
            </div>

            <div class="automacao-body">
                <div class="automation-config">
                    <div class="config-group">
                        <label>ERP Conectado:</label>
                        <span>Bling ERP</span>
                    </div>
                    <div class="config-group">
                        <label>IA Utilizada:</label>
                        <span>OpenAI (GPT-3.5)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.automacao-item {
    background: #fff;
    border: 1px solid #e5e9f0;
    border-radius: 12px;
    margin-bottom: 16px;
    overflow: hidden;
}

.automacao-header {
    padding: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e5e9f0;
}

.automacao-title {
    display: flex;
    gap: 12px;
    align-items: center;
}

.automacao-body {
    padding: 16px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

.automation-config {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.config-group {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
}

.automation-prompt,
.automation-channels {
    padding: 12px;
    background: #f4f6fb;
    border-radius: 8px;
}
</style>
