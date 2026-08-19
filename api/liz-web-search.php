<?php
/**
 * Módulo: Busca Web para Liz
 * Objetivo: Permitir que Liz busque informações na internet
 *
 * Integração com Google Search API ou similar
 * Ainda em desenvolvimento
 */

declare(strict_types=1);

class LizWebSearch
{
    private string $googleApiKey;
    private string $searchEngineId;

    public function __construct()
    {
        $this->googleApiKey = getenv('GOOGLE_SEARCH_API_KEY') ?: '';
        $this->searchEngineId = getenv('GOOGLE_SEARCH_ENGINE_ID') ?: '';
    }

    /**
     * Buscar informação na internet
     * Retorna snippets relevantes
     */
    public function search(string $query): array
    {
        if (empty($this->googleApiKey) || empty($this->searchEngineId)) {
            return ['error' => 'Google Search API não configurado'];
        }

        $url = 'https://www.googleapis.com/customsearch/v1';
        $params = [
            'q' => $query,
            'key' => $this->googleApiKey,
            'cx' => $this->searchEngineId,
            'num' => 3, // Apenas 3 resultados
        ];

        $fullUrl = $url . '?' . http_build_query($params);

        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            return ['error' => 'Falha ao buscar na internet'];
        }

        $data = json_decode($response, true);

        if (!isset($data['items'])) {
            return ['results' => []];
        }

        $results = [];
        foreach (array_slice($data['items'], 0, 3) as $item) {
            $results[] = [
                'title' => $item['title'] ?? '',
                'snippet' => $item['snippet'] ?? '',
                'link' => $item['link'] ?? '',
            ];
        }

        return ['results' => $results];
    }

    /**
     * Determinar se deveria buscar na internet
     * (Baseado na categoria da pergunta)
     */
    public static function shouldSearch(string $message): bool
    {
        $shouldSearch = preg_match(
            '/(noticias|ultimas|horoscopo|tempo|clima|preco|valor|taxa|historico|quanto custa|qual e o preco|quanto é|cotacao|dolar|euro|bitcoin)/i',
            $message
        );

        return (bool)$shouldSearch;
    }
}

// Rodada 10 (2026-08-19) - R10-5: bloco de teste manual removido. Este arquivo so
// declara a classe LizWebSearch e e' consumido via require por outro codigo; o bloco
// "?test=1" abaixo era roteavel por HTTP e disparava, sem autenticacao nem rate
// limit, uma chamada real e paga a Google Custom Search API a cada request anonimo.
