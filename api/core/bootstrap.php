<?php
$config = require dirname(__DIR__) . '/config/config.php';

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/database.php';
require_once dirname(__DIR__) . '/database/schema.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/participant-auth.php';
require_once dirname(__DIR__) . '/services/invitation.php';
require_once dirname(__DIR__) . '/services/ticket.php';
require_once dirname(__DIR__) . '/services/qrcode.php';
require_once dirname(__DIR__) . '/services/mail.php';
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/services/event.php';
require_once dirname(__DIR__) . '/services/registration.php';
require_once __DIR__ . '/error-handler.php';

return $config;
