<?php
declare(strict_types=1);

namespace Blocks;

class GlobalFooter extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'background' => '#0f2847',
            'color' => '#ffffff',
            'padding' => '40px 20px',
            'marginTop' => '60px'
        ];
    }

    public function render(): string {
        return <<<HTML
<footer class="global-footer-block" style="{$this->styleToString()}">
    <div class="container" style="max-width: 1200px; margin: 0 auto;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 40px; margin-bottom: 30px;">
            <div>
                <strong style="font-size: 16px; display: block; margin-bottom: 15px;">Vivaliz</strong>
                <p style="opacity: 0.8; line-height: 1.6; margin: 0;">
                    Qualidade e entrega rápida para todo o Brasil.
                </p>
            </div>
            <div>
                <strong style="font-size: 16px; display: block; margin-bottom: 15px;">Navegação</strong>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li><a href="/catalogo" style="color: #ffffff; text-decoration: none; display: block; margin-bottom: 8px; opacity: 0.8;">Catálogo</a></li>
                    <li><a href="/sobre" style="color: #ffffff; text-decoration: none; display: block; margin-bottom: 8px; opacity: 0.8;">Sobre</a></li>
                    <li><a href="/contato" style="color: #ffffff; text-decoration: none; display: block; margin-bottom: 8px; opacity: 0.8;">Contato</a></li>
                </ul>
            </div>
            <div>
                <strong style="font-size: 16px; display: block; margin-bottom: 15px;">Atendimento</strong>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li><a href="/contato" style="color: #ffffff; text-decoration: none; display: block; margin-bottom: 8px; opacity: 0.8;">Fale conosco</a></li>
                    <li><a href="/faq" style="color: #ffffff; text-decoration: none; display: block; margin-bottom: 8px; opacity: 0.8;">FAQ</a></li>
                    <li><a href="/politica-privacidade" style="color: #ffffff; text-decoration: none; display: block; margin-bottom: 8px; opacity: 0.8;">Privacidade</a></li>
                </ul>
            </div>
        </div>
        <div style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 20px; text-align: center; opacity: 0.7; font-size: 14px;">
            &copy; 2026 Vivaliz. Todos os direitos reservados.
        </div>
    </div>
</footer>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Global Footer',
            'description' => 'Rodapé global da loja',
            'icon' => '🏛️',
            'category' => 'Estrutura',
            'props' => []
        ];
    }
}
