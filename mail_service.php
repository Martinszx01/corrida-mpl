<?php

function mplMailRead($socket, $expectedCodes) {
    $response = '';
    while (($line = fgets($socket, 2048)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') break;
    }
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) throw new RuntimeException('Servidor SMTP recusou a operação (código ' . $code . ').');
    return $response;
}

function mplMailCommand($socket, $command, $expectedCodes) {
    if (fwrite($socket, $command . "\r\n") === false) throw new RuntimeException('Falha ao enviar comando SMTP.');
    return mplMailRead($socket, $expectedCodes);
}

function mplMailSmtp($config, $to, $subject, $body, $contentType) {
    $host = trim((string)(isset($config['mail_smtp_host']) ? $config['mail_smtp_host'] : ''));
    $port = (int)(isset($config['mail_smtp_port']) ? $config['mail_smtp_port'] : 587);
    $encryption = strtolower(trim((string)(isset($config['mail_smtp_encryption']) ? $config['mail_smtp_encryption'] : 'tls')));
    $timeout = max(3, min(30, (int)(isset($config['mail_smtp_timeout']) ? $config['mail_smtp_timeout'] : 10)));
    if ($host === '' || strpos($host, '@') !== false || !preg_match('/^[a-z0-9.-]+$/i', $host) || $port < 1 || $port > 65535) {
        throw new RuntimeException('Servidor SMTP não configurado corretamente.');
    }
    $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0; $error = '';
    $context = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'CN_match' => $host, 'SNI_enabled' => true, 'SNI_server_name' => $host)));
    $socket = @stream_socket_client($target, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException('Não foi possível conectar ao servidor SMTP.');
    stream_set_timeout($socket, $timeout);
    try {
        mplMailRead($socket, array(220));
        $clientName = preg_replace('/[^a-z0-9.\-]/i', '', (string)gethostname());
        if ($clientName === '') $clientName = 'localhost';
        mplMailCommand($socket, 'EHLO ' . $clientName, array(250));
        if ($encryption === 'tls') {
            mplMailCommand($socket, 'STARTTLS', array(220));
            $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if (@stream_socket_enable_crypto($socket, true, $cryptoMethod) !== true) throw new RuntimeException('Não foi possível ativar TLS no SMTP.');
            mplMailCommand($socket, 'EHLO ' . $clientName, array(250));
        }
        $username = trim((string)(isset($config['mail_smtp_username']) ? $config['mail_smtp_username'] : ''));
        $password = (string)(isset($config['mail_smtp_password']) ? $config['mail_smtp_password'] : '');
        if ($username !== '') {
            mplMailCommand($socket, 'AUTH LOGIN', array(334));
            mplMailCommand($socket, base64_encode($username), array(334));
            mplMailCommand($socket, base64_encode($password), array(235));
        }
        $from = trim((string)$config['mail_from']);
        mplMailCommand($socket, 'MAIL FROM:<' . $from . '>', array(250));
        mplMailCommand($socket, 'RCPT TO:<' . $to . '>', array(250, 251));
        mplMailCommand($socket, 'DATA', array(354));
        $domain = substr(strrchr($from, '@'), 1); if ($domain === false || $domain === '') $domain = 'localhost';
        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'From: 4ª Corrida MPL <' . $from . '>',
            'Reply-To: ' . $from,
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: ' . $contentType
        );
        $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(array("\r\n", "\r"), "\n", $body);
        $message = str_replace("\n", "\r\n", $message);
        $message = preg_replace('/(?m)^\./', '..', $message);
        if (fwrite($socket, $message . "\r\n.\r\n") === false) throw new RuntimeException('Falha ao transmitir o e-mail.');
        mplMailRead($socket, array(250));
        mplMailCommand($socket, 'QUIT', array(221));
        fclose($socket);
        return true;
    } catch (Exception $e) {
        fclose($socket);
        throw $e;
    }
}

function mplMailSend($config, $to, $subject, $body, $contentType) {
    $GLOBALS['mpl_mail_last_error'] = '';
    if (filter_var((string)(isset($config['mail_enabled']) ? $config['mail_enabled'] : '0'), FILTER_VALIDATE_BOOLEAN) !== true) return false;
    $from = trim((string)(isset($config['mail_from']) ? $config['mail_from'] : ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    if (preg_match('/[\r\n]/', $to . $from . $subject)) return false;
    $transport = strtolower(trim((string)(isset($config['mail_transport']) ? $config['mail_transport'] : 'mail')));
    try {
        if ($transport === 'smtp') return mplMailSmtp($config, $to, $subject, $body, $contentType);
        $headers = array('From: 4ª Corrida MPL <' . $from . '>', 'Reply-To: ' . $from, 'MIME-Version: 1.0', 'Content-Type: ' . $contentType);
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    } catch (Exception $e) {
        $GLOBALS['mpl_mail_last_error'] = $e->getMessage();
        error_log('[MPL Mail] ' . $e->getMessage());
        return false;
    }
}

function mplMailLastError() {
    return isset($GLOBALS['mpl_mail_last_error']) ? (string)$GLOBALS['mpl_mail_last_error'] : '';
}
