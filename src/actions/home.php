<?php
declare(strict_types=1);

$user = require_login();
render('home', ['title' => 'Inicio', 'user' => $user]);
