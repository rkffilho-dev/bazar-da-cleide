<?php
// Criação de link de pagamento e conferência, por provedor.

function criar_pagamento(array $ped): array {
  $modo = $ped['pagamento'];
  $site = rtrim((string)cfg('site_url'), '/');
  $c = json_decode($ped['cliente'], true);
  $itens = json_decode($ped['itens'], true);
  $volta = $site . '/pedido.html?id=' . urlencode($ped['id']);

  if ($modo === 'infinitepay') {
    $lin = [];
    foreach ($itens as $i) $lin[] = ['quantity' => (int)$i['qtd'], 'price' => (int)round($i['preco'] * 100), 'description' => $i['nome']];
    if ((float)$ped['frete'] > 0) $lin[] = ['quantity' => 1, 'price' => (int)round($ped['frete'] * 100), 'description' => 'Frete ' . $ped['frete_servico']];
    $corpo = [
      'handle' => ltrim((string)cfg('infinitepay_handle'), '$'),
      'order_nsu' => $ped['id'],
      'redirect_url' => $volta,
      'webhook_url' => $site . '/api/webhook.php?p=infinitepay',
      'items' => $lin,
      'customer' => array_filter(['name' => $c['nome'], 'email' => $c['email'] ?? null, 'phone_number' => '+55' . so_digitos($c['telefone'])]),
    ];
    if (!empty($c['cep'])) $corpo['address'] = ['cep' => $c['cep'], 'street' => $c['rua'], 'number' => $c['numero'],
      'neighborhood' => $c['bairro'], 'city' => $c['cidade'], 'state' => $c['uf'], 'complement' => $c['complemento'] ?? ''];
    $r = http_json('POST', 'https://api.checkout.infinitepay.io/links', $corpo);
    $url = $r['json']['url'] ?? ($r['json']['link'] ?? null);
    if (!$url) { log_loja('infinitepay erro ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 400)); return ['ok' => false]; }
    return ['ok' => true, 'url' => $url];
  }

  if ($modo === 'mercadopago') {
    $lin = [];
    foreach ($itens as $i) $lin[] = ['id' => $i['id'], 'title' => $i['nome'], 'quantity' => (int)$i['qtd'], 'unit_price' => (float)$i['preco'], 'currency_id' => 'BRL'];
    $exp = date('c', (int)$ped['expira_em']);
    $nomes = explode(' ', trim($c['nome']), 2);
    $corpo = [
      'items' => $lin,
      'external_reference' => $ped['id'],
      'notification_url' => $site . '/api/webhook.php?p=mercadopago',
      'back_urls' => ['success' => $volta, 'pending' => $volta, 'failure' => $volta],
      'auto_return' => 'approved',
      'statement_descriptor' => 'BAZARDACLEIDE',
      'expires' => true,
      'expiration_date_from' => date('c'),
      'expiration_date_to' => $exp,
      'date_of_expiration' => $exp,
      'payment_methods' => ['excluded_payment_types' => [['id' => 'ticket']], 'installments' => 12],
      'payer' => array_filter(['name' => $nomes[0], 'surname' => $nomes[1] ?? '', 'email' => $c['email'] ?? null]),
    ];
    if ((float)$ped['frete'] > 0) $corpo['shipments'] = ['mode' => 'not_specified', 'cost' => (float)$ped['frete']];
    $r = http_json('POST', 'https://api.mercadopago.com/checkout/preferences', $corpo,
      ['Authorization: Bearer ' . cfg('mercadopago_token'), 'X-Idempotency-Key: ' . $ped['id']]);
    $url = $r['json']['init_point'] ?? null;
    if (!$url) { log_loja('mercadopago erro ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 400)); return ['ok' => false]; }
    return ['ok' => true, 'url' => $url];
  }

  // WhatsApp: abre a conversa com a Cleide com o pedido pronto.
  $txt = texto_pedido($ped) . "\n\nOi Cleide! Fiz esse pedido no site. Pode me mandar o Pix? 🩷";
  return ['ok' => true, 'url' => 'https://wa.me/' . cfg('whatsapp_cleide') . '?text=' . rawurlencode($txt)];
}

// Confere com o provedor se o pagamento foi aprovado. Retorna [pedido_id, ref] ou null.
function conferir_infinitepay(string $order_nsu, string $slug, string $transacao): ?array {
  if (!$order_nsu || !$slug || !$transacao) return null;
  $r = http_json('POST', 'https://api.checkout.infinitepay.io/payment_check', [
    'handle' => ltrim((string)cfg('infinitepay_handle'), '$'),
    'order_nsu' => $order_nsu, 'transaction_nsu' => $transacao, 'slug' => $slug,
  ]);
  if (!empty($r['json']['success']) && !empty($r['json']['paid'])) {
    return [$order_nsu, 'infinitepay ' . ($r['json']['capture_method'] ?? '') . ' ' . $transacao];
  }
  return null;
}

function conferir_mercadopago(string $payment_id): ?array {
  if (!ctype_digit($payment_id)) return null;
  $r = http_json('GET', 'https://api.mercadopago.com/v1/payments/' . $payment_id, null, ['Authorization: Bearer ' . cfg('mercadopago_token')]);
  $j = $r['json'] ?? [];
  if (($j['status'] ?? '') === 'approved' && !empty($j['external_reference'])) {
    return [$j['external_reference'], 'mercadopago ' . ($j['payment_type_id'] ?? '') . ' ' . $payment_id];
  }
  return null;
}
