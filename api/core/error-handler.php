<?php
function apiHandleException($config, $exception) {
    $incidentId = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    error_log('[MPL API][' . $incidentId . '] ' . $exception->getMessage());
    resposta(array(
        'error' => $config['debug']
            ? $exception->getMessage()
            : 'Não foi possível concluir a operação. Código: ' . $incidentId
    ), 500);
}
