<?php
function paymentOwner($db, $registrationId) {
    $email = participantSessionEmail($db, true);

    $q = $db->prepare(
        'SELECT i.*, p.nome_completo, p.email, p.cpf, p.telefone, p.data_nascimento,
                p.tamanho_camiseta, c.nome AS categoria_nome, d.distancia_km
         FROM inscricoes i
         JOIN participantes p ON p.id = i.participante_id
         JOIN categorias c ON c.id = i.categoria_id
         JOIN distancias d ON d.id = i.distancia_id
         WHERE i.id = ? AND LOWER(TRIM(p.email)) = ?
         LIMIT 1'
    );
    $q->execute([$registrationId, $email]);
    $row = $q->fetch();
    if (!$row) {
        resposta(['error' => 'Inscrição não encontrada para este e-mail.'], 404);
    }
    return $row;
}

