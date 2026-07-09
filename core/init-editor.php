<?php
/**
 * Inicialização do sistema de editor visual
 * Inclua este arquivo no início de admin/template-editor.php
 */
declare(strict_types=1);

// Autoloader
require_once __DIR__ . '/BlockAutoloader.php';

// Registrar blocos
\Core\BlockRegistry::initialize();

// Aliases para fácil acesso
use Core\BlockRegistry;
use Core\DynamicRenderer;
