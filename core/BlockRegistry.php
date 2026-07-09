<?php
declare(strict_types=1);

namespace Core;

/**
 * Registro central de todos os blocos disponíveis no editor visual
 */
class BlockRegistry {
    private static array $blocks = [];
    private static bool $initialized = false;

    /**
     * Registrar um novo bloco
     */
    public static function register(
        string $name,
        string $class,
        string $icon,
        string $category,
        string $description = ''
    ): void {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Classe $class não existe");
        }

        if (!in_array(BlockInterface::class, class_implements($class) ?: [])) {
            throw new \InvalidArgumentException("$class não implementa BlockInterface");
        }

        self::$blocks[$name] = [
            'class' => $class,
            'icon' => $icon,
            'category' => $category,
            'description' => $description,
            'metadata' => $class::getMetadata()
        ];
    }

    /**
     * Registrar múltiplos blocos de uma vez
     */
    public static function registerBatch(array $blocks): void {
        foreach ($blocks as $name => $config) {
            self::register(
                $name,
                $config['class'],
                $config['icon'],
                $config['category'],
                $config['description'] ?? ''
            );
        }
    }

    /**
     * Obter todas as informações de um bloco
     */
    public static function get(string $name): ?array {
        return self::$blocks[$name] ?? null;
    }

    /**
     * Obter todos os blocos
     */
    public static function getAll(): array {
        return self::$blocks;
    }

    /**
     * Obter blocos por categoria
     */
    public static function getByCategory(string $category): array {
        return array_filter(self::$blocks, fn($block) => $block['category'] === $category);
    }

    /**
     * Obter lista de categorias
     */
    public static function getCategories(): array {
        $categories = array_unique(array_column(self::$blocks, 'category'));
        return array_values($categories);
    }

    /**
     * Instanciar um bloco
     */
    public static function instantiate(string $name, array $props = [], array $styles = []): ?BlockInterface {
        $config = self::get($name);
        if (!$config) {
            return null;
        }

        $class = $config['class'];
        return new $class($props, $styles);
    }

    /**
     * Verificar se um bloco existe
     */
    public static function exists(string $name): bool {
        return isset(self::$blocks[$name]);
    }

    /**
     * Inicializar registro padrão (chamado uma vez)
     */
    public static function initialize(): void {
        if (self::$initialized) {
            return;
        }

        // Registrar blocos core
        self::registerBatch([
            'HeroBanner' => [
                'class' => 'Blocks\\HeroBanner',
                'icon' => '🖼️',
                'category' => 'Marketing',
                'description' => 'Banner herói com imagem e CTA'
            ],
            'AnnouncementBar' => [
                'class' => 'Blocks\\AnnouncementBar',
                'icon' => '📢',
                'category' => 'Marketing',
                'description' => 'Barra de anúncio no topo'
            ],
            'ProductGrid' => [
                'class' => 'Blocks\\ProductGrid',
                'icon' => '📦',
                'category' => 'Catálogo',
                'description' => 'Grade de produtos estática'
            ],
            'CategoryCarousel' => [
                'class' => 'Blocks\\CategoryCarousel',
                'icon' => '🎠',
                'category' => 'Catálogo',
                'description' => 'Carrossel de categorias'
            ],
            'ProductCarousel' => [
                'class' => 'Blocks\\ProductCarousel',
                'icon' => '🛒',
                'category' => 'Catálogo',
                'description' => 'Carrossel de produtos'
            ],
            'CountdownTimer' => [
                'class' => 'Blocks\\CountdownTimer',
                'icon' => '⏱️',
                'category' => 'Marketing',
                'description' => 'Temporizador de contagem regressiva'
            ],
            'PromoRibbon' => [
                'class' => 'Blocks\\PromoRibbon',
                'icon' => '🎀',
                'category' => 'Marketing',
                'description' => 'Faixa promocional entre seções'
            ],
            'DynamicSpacer' => [
                'class' => 'Blocks\\DynamicSpacer',
                'icon' => '📏',
                'category' => 'Design',
                'description' => 'Espaçador com altura customizável'
            ],
            'Divider' => [
                'class' => 'Blocks\\Divider',
                'icon' => '─',
                'category' => 'Design',
                'description' => 'Linha divisória'
            ],
            'CardContainer' => [
                'class' => 'Blocks\\CardContainer',
                'icon' => '📋',
                'category' => 'Design',
                'description' => 'Caixa destacada com sombra'
            ],
            'GlobalFooter' => [
                'class' => 'Blocks\\GlobalFooter',
                'icon' => '🏛️',
                'category' => 'Estrutura',
                'description' => 'Rodapé global da loja'
            ]
        ]);

        self::$initialized = true;
    }
}

// Auto-inicializar ao carregar
BlockRegistry::initialize();
