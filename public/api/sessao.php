<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;

Response::ok([
    'logado'   => Auth::logado(),
    'operador' => Auth::operador(),
]);
