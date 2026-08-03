# Migrações Seguras do Repositório

Este runbook descreve como mover arquivos existentes sem criar mega-PRs e sem quebrar caminhos legados.

## Princípios

- cada plano deve conter poucos arquivos relacionados;
- a execução ocorre somente em branch não protegida;
- o preflight termina antes de qualquer movimento;
- colisões e traversal são bloqueados;
- documentos com padrão de credencial exigem sanitização manual;
- workflows ativos não são movidos automaticamente;
- o caminho antigo recebe wrapper ou stub quando necessário;
- o manifesto estrutural é atualizado no mesmo commit;
- o PR continua responsável pela revisão e pelos gates finais.

## Formato do plano

Crie `.github/migrations/<nome>.json` na branch da fase:

```json
{
  "migrations": [
    {
      "source": "scripts/exemplo.py",
      "target": "scripts/maintenance/exemplo.py",
      "kind": "python",
      "keep_compatibility": true
    },
    {
      "source": "RELATORIO-ANTIGO.md",
      "target": "docs/audits/legacy-reports/relatorio-antigo.md",
      "kind": "document",
      "keep_compatibility": true
    }
  ]
}
```

Tipos permitidos:

- `python`
- `shell`
- `powershell`
- `javascript`
- `document`
- `raw`, somente com `keep_compatibility=false`

## Execução

1. Crie uma branch a partir da `main` atual.
2. Adicione um plano pequeno.
3. Rode localmente o preflight:

```bash
python scripts/maintenance/apply_migration_plan.py \
  --plan .github/migrations/<nome>.json
```

4. Execute o workflow `Repository Safe Migration`, informando branch e plano.
5. Revise o commit criado pelo bot.
6. Abra PR pequeno para `main`.
7. Aguarde governança, QA e Quality Gate.
8. Faça merge somente com gates verdes.

## Restrições

O migrador não pode:

- executar na `main`;
- mover arquivos de `.github/workflows/`;
- sobrescrever destino existente;
- copiar documento com provável credencial;
- aceitar caminho absoluto ou `..`;
- arquivar arquivo `raw` mantendo origem duplicada.

## Remoção futura de wrappers e stubs

A compatibilidade só deve ser removida quando:

- busca não encontrar consumidores do caminho antigo;
- workflows e documentação usam o caminho canônico;
- manifesto for atualizado;
- testes e gates estiverem verdes.
