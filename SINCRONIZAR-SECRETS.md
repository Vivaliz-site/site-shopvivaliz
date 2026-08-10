# Materialização segura de secrets

## Regra principal

GitHub Actions Secrets são **write-only**. O comando `gh secret list` mostra apenas os nomes e nunca permite recuperar os valores. Portanto, nenhum script deste repositório deve afirmar que baixa ou sincroniza valores secretos do GitHub para `.env.local`.

Os valores devem ser fornecidos por um ambiente autorizado, como:

- secrets já injetados por um workflow;
- gerenciador de secrets da infraestrutura;
- variáveis de ambiente configuradas pelo operador;
- `.env.local` criado manualmente e mantido fora do Git.

## Materializar sem apagar valores existentes

```bash
python3 scripts/sincronizar_secrets_github.py
python3 scripts/validar_secrets.py --quick
```

O materializador:

- aplica somente variáveis não vazias presentes no ambiente atual;
- preserva todos os valores existentes que não receberam substituição explícita;
- recusa placeholders como `(do GitHub)`;
- usa gravação atômica e permissão `0600`;
- falha quando `.env.local` não existe e nenhum valor foi injetado.

O wrapper Linux executa o mesmo contrato:

```bash
bash scripts/sincronizar_secrets_github.sh
```

## Bootstrap

Por padrão, o bootstrap apenas materializa e valida. Ele **não** inicia auto-sync automaticamente:

```bash
bash scripts/bootstrap.sh
```

Somente em ambiente autorizado e após revisão da configuração:

```bash
bash scripts/bootstrap.sh --start-auto-sync
```

Uma falha na materialização ou na validação interrompe o bootstrap antes de qualquer processo em background.

## Checklist

```bash
# O arquivo nunca deve ser versionado
git check-ignore .env.local

# Materializar somente valores presentes no ambiente
python3 scripts/sincronizar_secrets_github.py

# Bloquear a operação quando obrigatórios estiverem ausentes
python3 scripts/validar_secrets.py --quick

# Testes de regressão
python3 -m unittest tests/test_secret_bootstrap_safety.py -v
```

Nunca envie `.env.local` por email/chat, nunca registre valores em logs e nunca substitua o arquivo por campos vazios.
