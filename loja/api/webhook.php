<?php
// Avisos de pagamento. Nunca confia no corpo: sempre confere direto com o provedor.
require __DIR__ . '/lib.php';
require __DIR__ . '/pagamentos.php';
$p = $_GET['p'] ?? '';
$in = corpo_json();
$conf = null;
if ($p === 'infinitepay') {
  $conf = conferir_infinitepay((string)($in['order_nsu'] ?? ''), (string)($in['invoice_slug'] ?? ''), (string)($in['transaction_nsu'] ?? ''));
} elseif ($p === 'mercadopago') {
  $tipo = $_GET['type'] ?? $_GET['topic'] ?? ($in['type'] ?? '');
  $pid = (string)($_GET['data_id'] ?? $_GET['data.id'] ?? $_GET['id'] ?? ($in['data']['id'] ?? ''));
  if ($tipo === 'payment' || $tipo === '') $conf = conferir_mercadopago($pid);
}
if ($conf) {
  $r = confirmar_pagamento($conf[0], $conf[1]);
  json_out($r);
}
json_out(['ok' => true, 'ignorado' => true]);
