# Backend e Supabase

O frontend convertido mantém o contrato da API original e invoca as Edge Functions já existentes no Supabase. Isso evita mudar regras de pagamento, autorização, auditoria e emissão de ingresso durante a transcrição visual.

Se for necessário migrar também as Edge Functions para PHP, faça isso separadamente, preservando os mesmos endpoints, payloads, respostas e políticas do banco. Não há alteração automática de banco neste pacote.
