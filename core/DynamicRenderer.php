<?php
declare(strict_types=1);

namespace Core;

/**
 * Renderizador recursivo de layouts JSON
 * Percorre estrutura de blocos e monta HTML final
 */
class DynamicRenderer {
    private array $layoutConfig = [];
    private array $debugInfo = [];

    public function __construct(array $layoutConfig) {
        $this->layoutConfig = $layoutConfig;
    }

    /**
     * Carregar layout de arquivo JSON
     */
    public static function fromFile(string $filePath): self {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Layout file not found: $filePath");
        }

        $content = file_get_contents($filePath);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON: " . json_last_error_msg());
        }

        return new self($config);
    }

    /**
     * Carregar layout do banco de dados
     */
    public static function fromDatabase(string $pageId): self {
        try {
            $layout = Database::queryOne("SELECT config FROM page_layouts WHERE page_id = ?", [$pageId]);

            if (!$layout) {
                throw new \RuntimeException("Layout '$pageId' not found in database");
            }

            $config = json_decode($layout['config'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON in database: " . json_last_error_msg());
            }

            return new self($config);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to load layout from database: " . $e->getMessage());
        }
    }

    /**
     * Renderizar layout completo
     */
    public function render(): string {
        $html = '';

        if (isset($this->layoutConfig['meta'])) {
            $html .= $this->renderMeta($this->layoutConfig['meta']);
        }

        if (isset($this->layoutConfig['sections'])) {
            foreach ($this->layoutConfig['sections'] as $section) {
                $html .= $this->renderBlock($section);
            }
        }

        return $html;
    }

    /**
     * Renderizar um bloco individual (recursivo)
     */
    private function renderBlock(array $block): string {
        try {
            $blockName = $block['type'] ?? null;
            if (!$blockName) {
                return "<!-- Block type not specified -->\n";
            }

            if (!BlockRegistry::exists($blockName)) {
                $this->debugInfo[] = "Block '$blockName' not found in registry";
                return "<!-- Block type '$blockName' not found -->\n";
            }

            $props = $block['props'] ?? [];
            $styles = $block['styles'] ?? [];
            $blockInstance = BlockRegistry::instantiate($blockName, $props, $styles);

            if (!$blockInstance) {
                return "<!-- Failed to instantiate block '$blockName' -->\n";
            }

            // Renderizar bloco
            $html = $blockInstance->render();

            // Renderizar children recursivamente se houver
            if (!empty($block['children']) && is_array($block['children'])) {
                $childrenHtml = '';
                foreach ($block['children'] as $child) {
                    $childrenHtml .= $this->renderBlock($child);
                }

                // Injetar children no HTML (procura por placeholder)
                $html = str_replace('{{children}}', $childrenHtml, $html);
            }

            return $html;
        } catch (\Throwable $e) {
            $this->debugInfo[] = "Error rendering block: " . $e->getMessage();
            return "<!-- Error: " . htmlspecialchars($e->getMessage()) . " -->\n";
        }
    }

    /**
     * Renderizar metadados (SEO, título, etc)
     */
    private function renderMeta(array $meta): string {
        $html = '';

        if (isset($meta['title'])) {
            $html .= '<title>' . htmlspecialchars($meta['title']) . "</title>\n";
        }

        if (isset($meta['description'])) {
            $html .= '<meta name="description" content="' . htmlspecialchars($meta['description']) . "\">\n";
        }

        if (isset($meta['og_image'])) {
            $html .= '<meta property="og:image" content="' . htmlspecialchars($meta['og_image']) . "\">\n";
        }

        return $html;
    }

    /**
     * Obter informações de debug
     */
    public function getDebugInfo(): array {
        return $this->debugInfo;
    }

    /**
     * Validar estrutura de layout
     */
    public function validate(): array {
        $errors = [];

        if (!isset($this->layoutConfig['sections'])) {
            $errors[] = "No 'sections' key found in layout";
        }

        if (!is_array($this->layoutConfig['sections'] ?? null)) {
            $errors[] = "'sections' must be an array";
        }

        foreach ($this->layoutConfig['sections'] ?? [] as $index => $section) {
            if (!isset($section['type'])) {
                $errors[] = "Section $index missing 'type'";
            } elseif (!BlockRegistry::exists($section['type'])) {
                $errors[] = "Section $index has unknown block type: {$section['type']}";
            }
        }

        return $errors;
    }
}
