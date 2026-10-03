<?php
declare(strict_types=1);

$user = require_login();

render('about', [
    'title' => 'Acerca de',
    'user' => $user,
    'app_version' => APP_VERSION,
    'api_version' => API_VERSION,
]);
