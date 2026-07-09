<?php
declare(strict_types=1);

namespace Core;

/**
 * Interface para todos os blocos do editor visual
 */
interface BlockInterface {
    /**
     * Renderiza o bloco em HTML
     *
     * @return string HTML do bloco
     */
    public function render(): string;

    /**
     * Retorna metadados do bloco
     *
     * @return array Metadados (nome, descrição, ícone, etc)
     */
    public static function getMetadata(): array;
}
