<?php
function b64($v) {
    return rtrim(
        strtr(base64_encode($v), '+/', '-_'),
        '='
    );
}

function tokenCriar(
    $id,
    $perfil,
    $email,
    $segredo
) {
    $h = b64(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT'
    ]));

    $p = b64(json_encode([
        'sub' => $id,
        'role' => $perfil,
        'email' => $email,
        'iat' => time(),
        'exp' => time() + 86400
    ]));

    $assinatura = b64(
        hash_hmac(
            'sha256',
            "$h.$p",
            $segredo,
            true
        )
    );

    return "$h.$p.$assinatura";
}

function usuarioAtual($c) {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION']
        : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '');

    if (
        $h === ''
        && !empty($_SERVER['HTTP_X_MPL_TOKEN'])
    ) {
        $h = 'Bearer ' . $_SERVER['HTTP_X_MPL_TOKEN'];
    }

    if (!preg_match('/Bearer\s+(.+)/i', $h, $m)) {
        return null;
    }

    $part = explode('.', $m[1]);

    if (count($part) !== 3) {
        return null;
    }

    $assinaturaEsperada = b64(
        hash_hmac(
            'sha256',
            "{$part[0]}.{$part[1]}",
            $c['jwt_secret'],
            true
        )
    );

    if (!hash_equals($assinaturaEsperada, $part[2])) {
        return null;
    }

    $raw = strtr($part[1], '-_', '+/');
    $raw .= str_repeat(
        '=',
        (4 - strlen($raw) % 4) % 4
    );

    $p = json_decode(
        base64_decode($raw),
        true
    );

    if (
        !$p
        || ((isset($p['exp']) ? $p['exp'] : 0)) <= time()
    ) {
        return null;
    }

    return $p;
}

function exigir(
    $c,
    $perfis = []
) {
    $u = usuarioAtual($c);

    if (!$u) {
        resposta([
            'error' => 'Faça login para continuar.'
        ], 401);
    }

    if (!empty($u['sub'])) {
        $db = banco($c);
        $q = $db->prepare('SELECT email, perfil FROM usuarios_admin WHERE id = ? AND ativo = 1 LIMIT 1');
        $q->execute(array((int) $u['sub']));
        $current = $q->fetch();
        if (!$current || strtolower(trim((string)$current['email'])) !== strtolower(trim((string)(isset($u['email']) ? $u['email'] : '')))) {
            resposta(array('error' => 'Sessão administrativa inválida ou revogada.'), 401);
        }
        $u['role'] = strtoupper((string)$current['perfil']);
    }

    if (
        $perfis
        && !in_array(
            strtolower((isset($u['role']) ? $u['role'] : '')),
            array_map('strtolower', $perfis),
            true
        )
    ) {
        resposta([
            'error' => 'Você não tem permissão.'
        ], 403);
    }

    return $u;
}

