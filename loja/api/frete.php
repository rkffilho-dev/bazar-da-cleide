<?php
require __DIR__ . '/lib.php';
require __DIR__ . '/frete_calc.php';
$in = corpo_json();
$cep = so_digitos((string)($in['cep'] ?? ''));
if (strlen($cep) !== 8) json_out(['erro' => 'CEP inválido'], 400);
$op = cotar_frete($cep, (array)($in['itens'] ?? []));
if (!$op) json_out(['erro' => 'Carrinho vazio'], 400);
json_out(['opcoes' => $op]);
