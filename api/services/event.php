<?php
function corrida(
    $db,
    $c
) {
    $q = $db->prepare(
        'SELECT *
         FROM corridas
         WHERE slug = ?
         LIMIT 1'
    );

    $q->execute([
        $c['event_slug']
    ]);

    $e = $q->fetch();

    if (!$e) {
        throw new RuntimeException(
            'Corrida não cadastrada.'
        );
    }

    $q = $db->prepare(
        "SELECT
            c.id,
            c.nome,
            c.nome AS name,
            c.genero,
            c.idade_minima,
            c.idade_maxima,
            c.idade_minima AS min_age,
            c.idade_maxima AS max_age,
            d.distancia_km,
            d.distancia_km AS distance_km,
            COALESCE(
                (
                    SELECT CAST(l.preco * 100 AS UNSIGNED)
                    FROM lotes l
                    WHERE l.categoria_id = c.id
                      AND l.corrida_id = c.corrida_id
                      AND l.ativo = 1
                    ORDER BY l.id
                    LIMIT 1
                ),
                0
            ) AS price_cents
         FROM categorias c
         JOIN distancias d
           ON d.id = c.distancia_id
         WHERE c.corrida_id = ?
           AND c.ativa = 1
         ORDER BY c.ordem, c.id"
    );

    $q->execute([
        $e['id']
    ]);

    $e['categories'] = $q->fetchAll();

    $e['name'] = $e['nome'];
    $e['event_date'] = $e['data_corrida'];
    $e['start_time'] = $e['horario_largada'];
    $e['registration_open'] = (bool) $e['inscricoes_abertas'];

    return $e;
}

function confirmar(
    $db,
    $corridaId,
    $nome
) {
    $q = $db->prepare(
        "SELECT
            c.id,
            c.nome AS name,
            d.distancia_km,
            d.distancia_km AS distance_km,
            c.idade_minima AS min_age,
            c.idade_maxima AS max_age,
            c.ativa AS active,
            COALESCE(
                (
                    SELECT SUM(l.quantidade)
                    FROM lotes l
                    WHERE l.categoria_id = c.id
                      AND l.corrida_id = c.corrida_id
                ),
                0
            ) AS capacity
         FROM categorias c
         JOIN distancias d
           ON d.id = c.distancia_id
         WHERE c.corrida_id = ?
         ORDER BY c.ordem, c.id"
    );

    $q->execute([
        $corridaId
    ]);

    $out = [];

    foreach ($q->fetchAll() as $r) {
        $r['active'] = (bool) $r['active'];
        $r['capacity'] = (int) $r['capacity'];
        $out[] = $r;
    }

    return $out;
}

