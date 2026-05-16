<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Models\Operador;

Auth::exigirAdmin();

Response::ok(['operadores' => Operador::listar()]);
