<?php
// Removido em 2026-08-19 (Rodada 3 de melhoria contínua): código morto --
// confirmado via grep que este arquivo não é incluído em lugar nenhum (a
// única ocorrência do nome era um comentário em
// includes/facebook-shop-config.php:14). Continha um XSS refletido latente:
// echo $_SERVER['REQUEST_URI'] sem escape dentro de uma string JavaScript
// entre aspas simples. Não executava (nunca era incluído), mas mantê-lo
// como está arrisca um agente futuro "religar" o include achando que é
// útil. Se a integração de Analytics/GTM/Meta Pixel precisar ser
// reativada, reescrever usando <?= json_encode($valor,
// JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG) ?> em vez de echo direto. Ver
// R3-6 no relatório da Rodada 3 de melhoria contínua.
