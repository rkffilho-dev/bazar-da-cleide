<?php
// Cria o pedido, reserva os itens e devolve o link de pagamento (ou do WhatsApp).
require __DIR__ . '/lib.php';
require __DIR__ . '/frete_calc.php';
require __DIR__ . '/pagamentos.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'use POST'], 405);

$in = corpo_json();
$c = (array)($in['cliente'] ?? []);
$limpa = fn($k, $max = 80) => mb_substr(trim(strip_tags((string)($c[$k] ?? ''))), 0, $max);
$cli = [
  'nome' => $limpa('nome'), 'telefone' => so_digitos((string)($c['telefone'] ?? '')), 'email' => $limpa('email'),
  'cpf' => so_digitos((string)($c['cpf'] ?? '')), 'cep' => so_digitos((string)($c['cep'] ?? '')),
  'rua' => $limpa('rua'), 'numero' => $limpa('numero', 15), 'complemento' => $limpa('complemento', 40),
  'bairro' => $limpa('bairro'), 'cidade' => $limpa('cidade'), 'uf' => strtoupper($limpa('uf', 2)),
];
$frete_id = (string)($in['frete_id'] ?? '');
$retirada = $frete_id === 'retirada';

$erros = [];
if (count(preg_split('/\s+/', $cli['nome'])) < 2) $erros[] = 'Escreva nome e sobrenome.';
if (strlen($cli['telefone']) < 10 || strlen($cli['telefone']) > 11) $erros[] = 'WhatsApp com DDD inválido.';
if ($cli['email'] && !filter_var($cli['email'], FILTER_VALIDATE_EMAIL)) $erros[] = 'E-mail inválido.';
if (!$retirada) {
  if (strlen($cli['cep']) !== 8) $erros[] = 'CEP inválido.';
  foreach (['rua' => 'rua', 'numero' => 'número', 'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'estado'] as $k => $n)
    if ($cli[$k] === '') $erros[] = "Preencha $n.";
}
$prods = produtos();
$pedidos_itens = [];
foreach ((array)($in['itens'] ?? []) as $i) {
  $id = (string)($i['id'] ?? '');
  if (!isset($prods[$id])) continue;
  $q = max(1, min(50, (int)($i['qtd'] ?? 1)));
  $pedidos_itens[$id] = ($pedidos_itens[$id] ?? 0) + $q;
}
if (!$pedidos_itens) $erros[] = 'Seu carrinho está vazio.';
if ($erros) json_out(['erro' => implode(' ', $erros)], 400);

// Frete recalculado no servidor (não confia no valor que veio do navegador)
$itens_frete = [];
foreach ($pedidos_itens as $id => $q) $itens_frete[] = ['id' => $id, 'qtd' => $q];
$frete = 0.0; $frete_nome = 'Retirada / ponto de encontro';
if ($retirada) {
  if (!cfg('retirada_ativa')) json_out(['erro' => 'Retirada indisponível.'], 400);
} else {
  $escolhido = null;
  foreach (cotar_frete($cli['cep'], $itens_frete) as $o) if ($o['id'] === $frete_id) $escolhido = $o;
  if (!$escolhido) json_out(['erro' => 'O frete mudou. Calcule de novo e escolha a entrega.', 'refazer_frete' => true], 409);
  $frete = (float)$escolhido['preco']; $frete_nome = $escolhido['nome'];
}

$modo = (string)cfg('pagamento', 'whatsapp');
if ($modo === 'infinitepay' && !cfg('infinitepay_handle')) $modo = 'whatsapp';
if ($modo === 'mercadopago' && !cfg('mercadopago_token')) $modo = 'whatsapp';
$minutos = (int)($modo === 'whatsapp' ? cfg('reserva_minutos_whatsapp', 120) : cfg('reserva_minutos_online', 30));
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

$pdo = db();
$pdo->exec('BEGIN IMMEDIATE');   // trava: dois pedidos nunca leem o estoque ao mesmo tempo
try {
  expirar_reservas();
  $ativos = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE status='aguardando' AND (ip=? OR json_extract(cliente,'$.telefone')=?)");
  $ativos->execute([$ip, $cli['telefone']]);
  if ((int)$ativos->fetchColumn() >= 3) {
    $pdo->exec('ROLLBACK');
    json_out(['erro' => 'Você já tem pedidos aguardando pagamento. Pague ou espere a reserva vencer para fazer outro.'], 429);
  }
  $est = estoque();
  $faltando = []; $linhas = []; $subtotal = 0.0;
  foreach ($pedidos_itens as $id => $q) {
    if (($est[$id]['disponivel'] ?? 0) < $q) { $faltando[] = $prods[$id]['nome']; continue; }
    $linhas[] = ['id' => $id, 'nome' => $prods[$id]['nome'], 'qtd' => $q, 'preco' => (float)$prods[$id]['preco']];
    $subtotal += $prods[$id]['preco'] * $q;
  }
  if ($faltando) {
    $pdo->exec('ROLLBACK');
    json_out(['erro' => 'Poxa, alguém acabou de pegar: ' . implode(', ', $faltando) . '. Tiramos do seu carrinho.', 'indisponiveis' => array_keys(array_filter($pedidos_itens, fn($q, $id) => ($est[$id]['disponivel'] ?? 0) < $q, ARRAY_FILTER_USE_BOTH)), 'estoque' => $est], 409);
  }
  do { $pid = 'BZ' . strtoupper(bin2hex(random_bytes(4))); }
  while ($pdo->query("SELECT 1 FROM pedidos WHERE id=" . $pdo->quote($pid))->fetchColumn());
  $agora = time();
  $ped = ['id' => $pid, 'criado_em' => $agora, 'expira_em' => $agora + $minutos * 60, 'status' => 'aguardando', 'pagamento' => $modo,
          'cliente' => json_encode($cli, JSON_UNESCAPED_UNICODE), 'itens' => json_encode($linhas, JSON_UNESCAPED_UNICODE),
          'subtotal' => $subtotal, 'frete' => $frete, 'frete_servico' => $frete_nome, 'total' => $subtotal + $frete, 'ip' => $ip];
  $pdo->prepare("INSERT INTO pedidos (id,criado_em,expira_em,status,pagamento,cliente,itens,subtotal,frete,frete_servico,total,ip)
                 VALUES (:id,:criado_em,:expira_em,:status,:pagamento,:cliente,:itens,:subtotal,:frete,:frete_servico,:total,:ip)")->execute($ped);
  $ins = $pdo->prepare("INSERT INTO pedido_itens VALUES (?,?,?)");
  foreach ($linhas as $l) $ins->execute([$pid, $l['id'], $l['qtd']]);
  $pdo->exec('COMMIT');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
  log_loja('erro pedido: ' . $e->getMessage());
  json_out(['erro' => 'Não deu para registrar o pedido agora. Tente de novo.'], 500);
}

$pg = criar_pagamento($ped);
if (!$pg['ok']) {
  // não conseguiu gerar o link: cai para o WhatsApp para não perder a venda
  $ped['pagamento'] = 'whatsapp';
  $pdo->prepare("UPDATE pedidos SET pagamento='whatsapp', expira_em=? WHERE id=?")->execute([time() + (int)cfg('reserva_minutos_whatsapp', 120) * 60, $pid]);
  $ped['expira_em'] = time() + (int)cfg('reserva_minutos_whatsapp', 120) * 60;
  $pg = criar_pagamento($ped);
}
$pdo->prepare("UPDATE pedidos SET pagamento_url=? WHERE id=?")->execute([$pg['url'], $pid]);
if ($ped['pagamento'] === 'whatsapp') avisar_cleide("🆕 Pedido novo pelo site (aguardando Pix)\n\n" . texto_pedido($ped));
log_loja("pedido $pid criado ({$ped['pagamento']}) " . reais((float)$ped['total']));
json_out(['id' => $pid, 'url' => $pg['url'], 'pagamento' => $ped['pagamento'], 'expira_em' => (int)$ped['expira_em'], 'total' => $ped['total']]);
