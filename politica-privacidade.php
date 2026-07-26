<?php
declare(strict_types=1);

/**
 * Redirecionamento para a versão canônica da Política de Privacidade.
 *
 * Este arquivo era uma segunda cópia (desatualizada, sem link em nenhuma
 * página do site) do mesmo conteúdo publicado em /politica-privacidade/.
 * Duas URLs com conteúdo de política de privacidade divergente é ruim para
 * SEO (conteúdo duplicado) e para o usuário (pode ler a versão errada/antiga).
 * Em vez de apagar o arquivo, ele passa a redirecionar (301) para a página
 * oficial, mantendo a URL antiga funcional caso esteja indexada ou salva
 * em algum favorito/link externo.
 */

header('Location: /politica-privacidade/', true, 301);
exit;
