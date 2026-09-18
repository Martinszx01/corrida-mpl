<?php
function garantirTicketMpl($db, $registrationId) {
    $q = $db->prepare("SELECT token FROM tickets WHERE inscricao_id = ? AND status = 'ativo' LIMIT 1");
    $q->execute(array($registrationId));
    $row = $q->fetch();
    if ($row) return $row['token'];
    $token = 'MPL-RACE-TICKET-' . strtoupper(bin2hex(random_bytes(8)));
    $db->prepare('INSERT INTO tickets (inscricao_id, token, qr_code_data) VALUES (?, ?, ?)')->execute(array($registrationId, $token, $token));
    $ticketId = $db->lastInsertId();
    $db->prepare('INSERT IGNORE INTO retiradas_kit (inscricao_id, ticket_id) VALUES (?, ?)')->execute(array($registrationId, $ticketId));
    return $token;
}

