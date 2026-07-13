<?php
require_once __DIR__ . '/../app/bootstrap.php';
if (current_user()) audit((int)current_user()['id'], 'logout');
$_SESSION = [];
session_destroy();
redirect('/login.php');
