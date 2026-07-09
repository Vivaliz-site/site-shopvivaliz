/**
 * Editor Visual Drag-and-Drop
 * Gerencia paleta de blocos, canvas, reordenação e properties panel
 */

class EditorDragDrop {
    constructor(config = {}) {
        this.layoutJson = config.initialLayout || { page_id: '', sections: [] };
        this.blocks = [];
        this.selectedBlockId = null;
        this.sortables = [];

        this.init();
    }

    async init() {
        await this.loadBlocks();
        this.renderPalette();
        this.renderCanvas();
        this.attachEventListeners();
    }

    async loadBlocks() {
        try {
            const response = await fetch('/api/admin/blocks-list.php');
            if (!response.ok) throw new Error('Failed to load blocks');
            const data = await response.json();
            this.blocks = data.blocks || [];
        } catch (error) {
            console.error('Error loading blocks:', error);
            this.blocks = [];
        }
    }

    renderPalette() {
        const paletteContainer = document.getElementById('editor-palette');
        if (!paletteContainer) return;

        // Agrupar blocos por categoria
        const categories = {};
        this.blocks.forEach(block => {
            const cat = block.category || 'other';
            if (!categories[cat]) categories[cat] = [];
            categories[cat].push(block);
        });

        let html = '<div class="palette-groups">';
        Object.entries(categories).forEach(([cat, blocks]) => {
            html += `<div class="palette-group">
                <h3 class="palette-group-title">${cat.replace(/_/g, ' ')}</h3>
                <div class="palette-blocks">`;

            blocks.forEach(block => {
                html += `
                    <div class="palette-block" draggable="true" data-block-name="${block.name}" title="${block.description}">
                        <div class="palette-block-icon">${block.icon}</div>
                        <div class="palette-block-label">${block.name}</div>
                    </div>
                `;
            });

            html += '</div></div>';
        });
        html += '</div>';

        paletteContainer.innerHTML = html;

        // Attach drag listeners
        document.querySelectorAll('.palette-block').forEach(el => {
            el.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('blockName', el.dataset.blockName);
            });
        });
    }

    renderCanvas() {
        const canvas = document.getElementById('editor-canvas');
        if (!canvas) return;

        let html = '<div class="canvas-sections" id="canvas-sections">';

        if (this.layoutJson.sections && this.layoutJson.sections.length > 0) {
            this.layoutJson.sections.forEach((section, idx) => {
                html += this.renderBlockElement(section, idx);
            });
        } else {
            html += '<div class="canvas-empty">Arraste blocos aqui para começar</div>';
        }

        html += '</div>';
        canvas.innerHTML = html;

        // Tornar canvas uma dropzone
        const sections = document.getElementById('canvas-sections');
        if (sections) {
            sections.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                sections.classList.add('drag-over');
            });

            sections.addEventListener('dragleave', () => {
                sections.classList.remove('drag-over');
            });

            sections.addEventListener('drop', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                sections.classList.remove('drag-over');

                const blockName = e.dataTransfer.getData('blockName');
                if (blockName) {
                    this.addBlockToCanvas(blockName);
                }
            });
        }

        // Inicializar Sortable para reordenação
        this.initSortable();
    }

    renderBlockElement(section, index) {
        const blockType = section.type || 'Unknown';
        const blockId = section.id || `block-${index}`;
        const props = section.props || {};
        const propsStr = Object.entries(props).slice(0, 2).map(([k, v]) => `${k}: ${v}`).join(', ');

        return `
            <div class="canvas-block" data-block-index="${index}" data-block-id="${blockId}">
                <div class="canvas-block-header">
                    <div class="canvas-block-title">${blockType}</div>
                    <div class="canvas-block-actions">
                        <button class="btn-edit" title="Editar propriedades">✏️</button>
                        <button class="btn-delete" title="Remover">🗑️</button>
                    </div>
                </div>
                <div class="canvas-block-preview">${propsStr}</div>
                <div class="canvas-block-handle">⋮⋮</div>
            </div>
        `;
    }

    initSortable() {
        const sections = document.getElementById('canvas-sections');
        if (!sections) return;

        // Remover instâncias antigas
        this.sortables.forEach(s => s.destroy?.());
        this.sortables = [];

        if (typeof Sortable !== 'undefined') {
            const sortable = Sortable.create(sections, {
                handle: '.canvas-block-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: (evt) => {
                    // Reordenar no array
                    const item = this.layoutJson.sections[evt.oldIndex];
                    this.layoutJson.sections.splice(evt.oldIndex, 1);
                    this.layoutJson.sections.splice(evt.newIndex, 0, item);
                }
            });
            this.sortables.push(sortable);
        }
    }

    addBlockToCanvas(blockName) {
        const block = this.blocks.find(b => b.name === blockName);
        if (!block) return;

        const newBlock = {
            type: blockName,
            id: `${blockName}-${Date.now()}`,
            props: block.metadata?.props ? Object.fromEntries(
                Object.entries(block.metadata.props).map(([key]) => [key, ''])
            ) : {},
            styles: {}
        };

        this.layoutJson.sections.push(newBlock);
        this.renderCanvas();
    }

    attachEventListeners() {
        // Edit block
        document.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const blockEl = e.target.closest('.canvas-block');
                const index = blockEl.dataset.blockIndex;
                this.showPropertiesPanel(index);
            });
        });

        // Delete block
        document.querySelectorAll('.btn-delete').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const blockEl = e.target.closest('.canvas-block');
                const index = parseInt(blockEl.dataset.blockIndex);
                this.layoutJson.sections.splice(index, 1);
                this.renderCanvas();
                this.attachEventListeners();
            });
        });

        // Save button
        const saveBtn = document.getElementById('btn-save-final');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveLayout());
        }

        // Toggle code view
        const codeToggle = document.getElementById('toggle-code-view');
        if (codeToggle) {
            codeToggle.addEventListener('click', () => {
                const codePanel = document.getElementById('editor-code');
                if (codePanel) {
                    codePanel.style.display = codePanel.style.display === 'none' ? 'block' : 'none';
                }
            });
        }
    }

    showPropertiesPanel(blockIndex) {
        const block = this.layoutJson.sections[blockIndex];
        if (!block) return;

        const blockType = this.blocks.find(b => b.name === block.type);
        const props = blockType?.metadata?.props || {};

        const panel = document.getElementById('editor-properties');
        if (!panel) return;

        let html = `<div class="properties-header">
            <h3>${block.type}</h3>
            <button class="btn-close">✕</button>
        </div>`;

        html += '<div class="properties-fields">';
        Object.entries(props).forEach(([key, propConfig]) => {
            const value = block.props[key] || '';
            const label = propConfig.label || key;
            const type = propConfig.type || 'text';

            html += `
                <div class="property-field">
                    <label>${label}</label>
                    <input type="${type}" value="${value}" data-prop-key="${key}" class="prop-input" />
                </div>
            `;
        });
        html += '</div>';

        panel.innerHTML = html;

        // Attach input listeners
        panel.querySelectorAll('.prop-input').forEach(input => {
            input.addEventListener('change', (e) => {
                const key = e.target.dataset.propKey;
                block.props[key] = e.target.value;
                this.renderCanvas();
                this.attachEventListeners();
            });
        });

        // Close button
        panel.querySelector('.btn-close').addEventListener('click', () => {
            panel.innerHTML = '<div class="properties-empty">Selecione um bloco para editar</div>';
        });
    }

    async saveLayout() {
        const layoutJson = JSON.stringify(this.layoutJson);
        const pageId = this.layoutJson.page_id || 'homepage';

        try {
            const response = await fetch('/api/admin/layouts-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    page_id: pageId,
                    config: this.layoutJson,
                    page_type: 'homepage',
                    viewport: 'both',
                    publish: false
                })
            });

            const result = await response.json();
            if (result.ok) {
                alert(`✓ Layout salvo com sucesso! (${result.saved_to})`);
            } else {
                alert(`✗ Erro: ${result.error}`);
            }
        } catch (error) {
            alert(`✗ Erro ao salvar: ${error.message}`);
        }
    }

    getLayoutJson() {
        return this.layoutJson;
    }

    setLayoutJson(json) {
        this.layoutJson = json;
        this.renderCanvas();
        this.attachEventListeners();
    }
}

// Auto-inicializar se o elemento existir
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('editor-container');
    if (container && window.initialLayoutJson) {
        window.editorInstance = new EditorDragDrop({
            initialLayout: window.initialLayoutJson
        });
    }
});
