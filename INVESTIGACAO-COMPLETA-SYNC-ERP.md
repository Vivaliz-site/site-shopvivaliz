# Investigação histórica da sincronização ERP

Este documento continha um refresh token Olist/Tiny completo e seu payload decodificado. O valor foi removido da árvore atual e deve ser considerado comprometido.

## Conclusões preservadas

- um token existia em arquivo `.env` local;
- a presença do token não comprovava que a sincronização estava funcionando;
- nenhum relatório deve reproduzir token, payload JWT, authorization code ou resposta completa de autenticação;
- a integração deve usar apenas secrets protegidos;
- uma execução só pode ser declarada válida com código de saída, request ID redigido, contagens, read-back e artifact ligado ao run.

## Ação externa obrigatória

1. Revogar e rotacionar o refresh token no provedor.
2. Salvar o substituto somente como secret protegido.
3. Reautorizar a integração pela interface oficial.
4. Validar leitura e sincronização real sem imprimir credenciais.
5. Limpar o histórico Git apenas depois da revogação e com coordenação dos colaboradores.

Este snapshot é histórico e não comprova prontidão atual.
