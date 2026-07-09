<?php
declare(strict_types=1);

namespace Blocks;

class ProductReviews extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'backgroundColor' => '#f9fafb',
            'borderRadius' => '8px',
            'padding' => '24px',
            'margin' => '30px 0'
        ];
    }

    public function render(): string {
        $limit = (int)$this->prop('limit', 5);
        $productId = $this->esc($this->prop('product_id', ''));
        $allowNew = $this->prop('allow_new_reviews', true) ? '1' : '0';

        if (!$productId) {
            return "<!-- ProductReviews: product_id não configurado -->\n";
        }

        return <<<HTML
<section class="product-reviews-block" style="{$this->styleToString()}" data-product-id="$productId">
    <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 20px 0; color: #111827;">
        ⭐ Avaliações dos Clientes
    </h2>

    <div class="reviews-summary" style="margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid #e5e9f0;">
        <div style="display: flex; gap: 20px; align-items: center;">
            <div style="font-size: 48px; font-weight: 700; color: #059669;">4.5</div>
            <div>
                <div style="display: flex; gap: 4px; margin-bottom: 8px;">
                    <span style="font-size: 18px;">⭐⭐⭐⭐⭐</span>
                </div>
                <p style="color: #6b7280; font-size: 14px; margin: 0;">Baseado em 124 avaliações verificadas</p>
            </div>
        </div>
    </div>

    <div class="reviews-list" style="max-height: 600px; overflow-y: auto;">
        <!-- Reviews carregadas dinamicamente via AJAX -->
        <div class="review-item" style="padding: 16px 0; border-bottom: 1px solid #e5e9f0;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <strong style="color: #111827;">João Silva</strong>
                <span style="color: #6b7280; font-size: 12px;">Há 2 dias</span>
            </div>
            <div style="color: #d97706; margin-bottom: 8px; font-size: 14px;">⭐⭐⭐⭐⭐</div>
            <p style="color: #374151; font-size: 14px; margin: 0;">Produto de excelente qualidade! Chegou rápido e bem embalado. Recomendo muito!</p>
        </div>
    </div>

    <div class="review-form" data-allow="$allowNew" style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e5e9f0;">
        <h3 style="font-size: 16px; font-weight: 700; margin: 0 0 16px 0;">Deixe sua avaliação</h3>
        <form onsubmit="submitReview(event, '$productId')" style="display: flex; flex-direction: column; gap: 12px;">
            <div>
                <label style="display: block; font-weight: 600; color: #111827; margin-bottom: 6px;">Nome</label>
                <input type="text" name="name" required style="width: 100%; padding: 8px 12px; border: 1px solid #e5e9f0; border-radius: 6px; font-family: inherit;">
            </div>

            <div>
                <label style="display: block; font-weight: 600; color: #111827; margin-bottom: 6px;">Avaliação</label>
                <div style="display: flex; gap: 8px;">
                    <input type="radio" name="rating" value="5" required style="cursor: pointer;"> ⭐⭐⭐⭐⭐ Excelente
                    <input type="radio" name="rating" value="4" style="cursor: pointer; margin-left: 16px;"> ⭐⭐⭐⭐ Bom
                    <input type="radio" name="rating" value="3" style="cursor: pointer; margin-left: 16px;"> ⭐⭐⭐ OK
                </div>
            </div>

            <div>
                <label style="display: block; font-weight: 600; color: #111827; margin-bottom: 6px;">Comentário</label>
                <textarea name="comment" required maxlength="500" style="width: 100%; padding: 8px 12px; border: 1px solid #e5e9f0; border-radius: 6px; font-family: inherit; height: 100px; resize: vertical;"></textarea>
            </div>

            <button type="submit" style="background: #059669; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer;">
                Enviar Avaliação
            </button>
        </form>
    </div>
</section>

<script>
function submitReview(e, productId) {
    e.preventDefault();
    const form = e.target;
    const data = {
        product_id: productId,
        name: form.name.value,
        rating: parseInt(form.rating.value),
        comment: form.comment.value
    };

    fetch('/api/reviews/submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            alert('✓ Avaliação enviada! Obrigado.');
            form.reset();
            location.reload();
        } else {
            alert('✗ Erro ao enviar: ' + (d.error || 'Tente novamente'));
        }
    })
    .catch(e => alert('Erro de conexão: ' + e.message));
}
</script>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Product Reviews',
            'description' => 'Seção de avaliações e comentários do produto',
            'icon' => '⭐',
            'category' => 'Produto',
            'props' => [
                'product_id' => ['type' => 'text', 'default' => ''],
                'limit' => ['type' => 'number', 'default' => 5, 'min' => 1, 'max' => 50],
                'allow_new_reviews' => ['type' => 'boolean', 'default' => true]
            ]
        ];
    }
}
