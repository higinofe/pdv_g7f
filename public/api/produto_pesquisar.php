<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Models\Produto;

Auth::exigirLogin();

$termo = trim((string)($_GET['termo'] ?? ''));
$produtos = Produto::pesquisar($termo, 100);
Response::ok(['produtos' => $produtos, 'total' => count($produtos)]);
