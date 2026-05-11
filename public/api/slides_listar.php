<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Env;
use App\Helpers\Response;

// Lista os arquivos de imagem disponíveis em /assets/img/slides/.
// Aceita formatos comuns; o frontend usa essa lista para montar o slideshow.
$dir = __DIR__ . '/../assets/img/slides';
$exts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif'];

$slides = [];
if (is_dir($dir)) {
    $arquivos = scandir($dir) ?: [];
    foreach ($arquivos as $a) {
        if ($a === '.' || $a === '..') continue;
        $ext = strtolower(pathinfo($a, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        $slides[] = [
            'url'  => '/assets/img/slides/' . rawurlencode($a),
            'nome' => $a,
        ];
    }
    sort($slides);
}

Response::ok([
    'slides'        => $slides,
    'intervalo_ms'  => (int) Env::get('SLIDES_INTERVALO_MS', 6000),
]);
