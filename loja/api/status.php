<?php
// Situação de um pedido (página de retorno). Também confere o pagamento quando a InfinitePay devolve o cliente.
require __DIR__ . '/lib.php';
require __DIR__ . '/pagamentos.php';
expirar_reservas();
$id = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_GET['id'] ?? '')));
$st = db()->prepare("SELECT * FROM pedidos WHERE id=?");
$st->execute([$id]);
$ped = $st->fetch();
if (!$ped) json_out(['erro' => 'Pedido não encontrado'], 404);
if ($ped['status'] !== 'pago' && $ped['pagamento'] === 'infinitepay' && !empty($_GET['slug'])) {
  $conf = conferir_infinitepay($id, (string)$_GET['slug'], (string)($_GET['transaction_nsu'] ?? ''));
  if ($conf && $conf[0] === $id) { confirmar_pagamento($id, $conf[1]); $st->execute([$id]); $ped = $st->fetch(); }
}
if ($ped['status'] !== 'pago' && $ped['pagamento'] === 'mercadopago' && !empty($_GET['payment_id'])) {
  $conf = conferir_mercadopago((string)$_GET['payment_id']);
  if ($conf && $conf[0] === $id) { confirmar_pagamento($id, $conf[1]); $st->execute([$id]); $ped = $st->fetch(); }
}
$wa = 'https://wa.me/' . cfg('whatsapp_cleide') . '?text=' . rawurlencode(
  ($ped['status'] === 'pago' ? "Oi Cleide! Paguei o pedido {$id} no site 🩷\n\n" : "Oi Cleide! Sobre o pedido {$id}:\n\n") . texto_pedido($ped));
json_out([
  'id' => $id, 'status' => $ped['status'], 'pagamento' => $ped['pagamento'], 'pagamento_url' => $ped['status'] === 'aguardando' ? $ped['pagamento_url'] : null,
  'expira_em' => (int)$ped['expira_em'], 'itens' => json_decode($ped['itens'], true), 'subtotal' => (float)$ped['subtotal'],
  'frete' => (float)$ped['frete'], 'frete_servico' => $ped['frete_servico'], 'total' => (float)$ped['total'], 'whatsapp' => $wa, 'agora' => time(),
]);
