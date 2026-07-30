# Incidente de credencial em documento Olist

Data da detecção: 2026-07-30.

## Ocorrência

Durante a reorganização estrutural foi encontrado um token em texto puro em um documento de configuração de webhook Olist.

## Ações executadas no repositório

- o valor foi removido da versão corrente da branch;
- o documento foi substituído por procedimento sanitizado;
- o auditor passou a detectar tokens hexadecimais longos e JWTs associados a campos de credencial;
- o incidente foi registrado sem reproduzir o valor.

## Ação externa obrigatória

A credencial deve ser considerada comprometida e rotacionada no provedor. Depois da rotação, o novo valor deve existir somente em secret protegido.

## Limitação

A remoção da versão corrente não apaga automaticamente o valor do histórico Git anterior. Uma limpeza de histórico exige procedimento separado, coordenado e com rotação já concluída.
