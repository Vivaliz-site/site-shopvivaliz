# Smoke Tests

Esta pasta contém verificações rápidas pós-deploy ou contra ambiente controlado.

Regras:

- não alterar dados de produção sem confirmação explícita;
- não armazenar credenciais;
- validar endpoints, páginas e fluxos críticos;
- registrar ambiente, horário e resultado;
- usar nomes `test_*.py` quando executados por unittest.
