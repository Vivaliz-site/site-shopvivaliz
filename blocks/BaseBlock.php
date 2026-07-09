<?php
declare(strict_types=1);

namespace Blocks;

use Core\BlockInterface;

/**
 * Classe base para todos os blocos
 */
abstract class BaseBlock implements BlockInterface {
    protected array $props = [];
    protected array $styles = [];

    public function __construct(array $props = [], array $styles = []) {
        $this->props = $props;
        $this->styles = array_merge($this->getDefaultStyles(), $styles);
    }

    /**
     * Retorna estilos padrão do bloco
     */
    protected function getDefaultStyles(): array {
        return [];
    }

    /**
     * Gerar string CSS de estilos
     */
    protected function styleToString(): string {
        $css = '';
        foreach ($this->styles as $property => $value) {
            $css .= "$property: $value; ";
        }
        return $css;
    }

    /**
     * Escapar HTML
     */
    protected function esc(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Obter valor de propriedade com fallback
     */
    protected function prop(string $key, mixed $default = null): mixed {
        return $this->props[$key] ?? $default;
    }

    /**
     * Metadados padrão (pode ser sobrescrito)
     */
    public static function getMetadata(): array {
        return [
            'name' => static::class,
            'description' => '',
            'icon' => '□',
            'category' => 'Other'
        ];
    }
}
