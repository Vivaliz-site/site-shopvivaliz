# Exclusividade do proprietário na inclusão de restrições — ShopVivaliz

**Vigência:** 10 de novembro de 2026. **Fonte:** ordem expressa do proprietário. **Abrangência:** todos os repositórios, serviços e ambientes ShopVivaliz.

## Regra prevalente

Somente o próprio proprietário, atuando pessoalmente, pode **introduzir, restaurar ou agravar** bloqueios, restrições, travas, gates, controles de impedimento, exigências obrigatórias ou configurações que possam bloquear, condicionar ou dificultar a execução ou a operação.

Nenhum agente, subagente, bot ou automação pode implementar tais inclusões, seja diretamente ou por commit, pull request, issue que implemente mudança, workflow, ruleset, código, infraestrutura, política de acesso, arquivo de instruções ou **governança**. Aprovação de PR, dupla revisão, aprovação verbal ou delegação administrativa não substituem a execução pessoal do proprietário.

### Procedimento quando um agente identificar necessidade de restrição

1. **Não implementar**, não criar PR e não alterar o ambiente para impor a restrição.
2. Descrever o problema, o impacto, a necessidade técnica, alternativas menos restritivas e o que será impedido.
3. Fornecer ao proprietário **comandos completos**, arquivos/trechos propostos para sua execução pessoal, validações de sucesso e reversão.
4. Aguardar a execução **pelo próprio proprietário** e, depois, verificar de forma somente leitura o resultado quando solicitado.

### Exemplos abrangidos

- Novos gates ou status checks obrigatórios de GitHub Actions, CI/CD, merge e deploy;
- Regras de branch, CODEOWNERS, regras de revisão, controles de acesso e workflows que introduzam recusa ou novas permissões obrigatórias;
- Deny lists e comandos bloqueados em MCP, ferramentas, agentes, políticas e arquivos de governança;
- Novos critérios impeditivos em serviços, APIs, processos, automações, autenticação ou infraestrutura.

**A classificação é pelo efeito real da mudança**, inclusive em arquivos chamados `README`, `AGENTS`, `CLAUDE`, `GEMINI`, workflows e scripts; um nome de arquivo não torna a mudança automaticamente restritiva ou não restritiva.

### Alterações que continuam no fluxo normal dos agentes

Correções técnicas, melhorias funcionais, mudanças documentais não restritivas e remoções ou reduções de travas indevidas podem ser executadas e integradas pelos agentes nos limites das autorizações existentes, sem inserir outras restrições no mesmo diff.

### Precedência e segurança

Esta determinação **substitui, no que concerne a novas restrições**, a política de 08/11/2026 que admitia PRs de restrição mediante autorização expressa e revisões de `shopvivaliz-human` e `fredmourao-ai`. A dupla revisão não confere mais poder de implementação ao agente.

Não remover automaticamente controles preexistentes, MFA, RBAC, segurança de terceiros, proteções nativas de branch, checks ou exigências legais. Nunca usar credenciais ou sessões do proprietário para simular que a mudança foi executada pessoalmente por ele; o agente não deve contornar controles do provedor.

Manter títulos, descrições e comentários de PRs de agentes em português brasileiro, com prefixo de identidade real do agente, conforme as regras de autoria existentes.

### Exceção histórica e limitada à implantação de 10/11/2026

Após definir a regra, o proprietário ordenou expressamente nesta mesma conversa: **“Faça você mesmo”**. Isso autoriza **somente GPT a distribuir a declaração inicial desta política** nesta execução, pelas rotas oficiais e sem contornar proteções obrigatórias. Não autoriza agentes a incluir ou ampliar outras restrições futuras; tampouco dispensa revisões ou checks GitHub existentes. Esta exceção não deve ser usada como precedente para futuras alterações restritivas.

### Evidência de adoção

A presença desta política e sua referência em `AGENTS.md` e outras instruções não constituem, isoladamente, garantia técnica de enforcement. Sempre distinguir: documento atualizado na branch, PR aberta, PR integrada ao `main`, regra técnica testada e regra de provedor efetivamente aplicada.
