<?php
// Núcleo da loja: configuração, banco SQLite, estoque e reservas.
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

function cfg(?string $k = null, $padrao = null) {
  static $c = null;
  if ($c === null) {
    $arq = __DIR__ . '/config.php';
    $c = is_file($arq) ? require $arq : require __DIR__ . '/config.exemplo.php';
  }
  if ($k === null) return $c;
  return $c[$k] ?? $padrao;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dir = dirname(__DIR__) . '/data';
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $pdo = new PDO('sqlite:' . $dir . '/loja.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA busy_timeout = 8000');
  $pdo->exec('PRAGMA journal_mode = WAL');
  $pdo->exec("CREATE TABLE IF NOT EXISTS pedidos (
    id TEXT PRIMARY KEY,
    criado_em INTEGER NOT NULL,
    expira_em INTEGER NOT NULL,
    status TEXT NOT NULL,             -- aguardando | pago | enviado | expirado | cancelado | devolver
    pagamento TEXT NOT NULL,          -- infinitepay | mercadopago | whatsapp
    pagamento_url TEXT,
    pagamento_ref TEXT,
    pago_em INTEGER,
    cliente TEXT NOT NULL,            -- JSON
    itens TEXT NOT NULL,              -- JSON
    subtotal REAL NOT NULL,
    frete REAL NOT NULL,
    frete_servico TEXT,
    total REAL NOT NULL,
    ip TEXT,
    obs TEXT
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS pedido_itens (
    pedido_id TEXT NOT NULL, produto_id TEXT NOT NULL, qtd INTEGER NOT NULL
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS ix_pi ON pedido_itens(produto_id)");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ajustes (
    produto_id TEXT PRIMARY KEY, oculto INTEGER DEFAULT 0, vendido_fora INTEGER DEFAULT 0
  )");
  return $pdo;
}

function produtos(): array {
  static $p = null;
  if ($p === null) {
    $j = json_decode((string)file_get_contents(dirname(__DIR__) . '/produtos.json'), true);
    $p = [];
    foreach ($j['produtos'] as $x) $p[$x['id']] = $x;
  }
  return $p;
}

// Reservas vencidas voltam para a vitrine.
function expirar_reservas(): void {
  db()->prepare("UPDATE pedidos SET status='expirado' WHERE status='aguardando' AND expira_em < ?")->execute([time()]);
}

// Quantidade presa por produto (pedidos pagos/enviados + reservas ativas), ignorando um pedido opcional.
function ocupados(?string $ignorar = null): array {
  $sql = "SELECT pi.produto_id, SUM(pi.qtd) q, MAX(CASE WHEN p.status IN ('pago','enviado') THEN 1 ELSE 0 END) vendido
          FROM pedido_itens pi JOIN pedidos p ON p.id = pi.pedido_id
          WHERE (p.status IN ('pago','enviado') OR (p.status='aguardando' AND p.expira_em >= ?))" .
         ($ignorar ? " AND p.id <> ?" : "") . " GROUP BY pi.produto_id";
  $st = db()->prepare($sql);
  $st->execute($ignorar ? [time(), $ignorar] : [time()]);
  $r = [];
  foreach ($st as $l) $r[$l['produto_id']] = ['q' => (int)$l['q']];
  // quantos estão vendidos de fato (para mostrar "Vendido" x "Reservado")
  $st = db()->prepare("SELECT pi.produto_id, SUM(pi.qtd) q FROM pedido_itens pi JOIN pedidos p ON p.id=pi.pedido_id
                       WHERE p.status IN ('pago','enviado')" . ($ignorar ? " AND p.id <> ?" : "") . " GROUP BY pi.produto_id");
  $st->execute($ignorar ? [$ignorar] : []);
  foreach ($st as $l) $r[$l['produto_id']]['vendidos'] = (int)$l['q'];
  return $r;
}

function ajustes(): array {
  $r = [];
  foreach (db()->query("SELECT * FROM ajustes") as $l) $r[$l['produto_id']] = $l;
  return $r;
}

// Estado de cada produto para a vitrine.
function estoque(?string $ignorar = null): array {
  $occ = ocupados($ignorar);
  $aj = ajustes();
  $out = [];
  foreach (produtos() as $id => $p) {
    $total = (int)($p['estoque'] ?? 1) - (int)($aj[$id]['vendido_fora'] ?? 0);
    $preso = $occ[$id]['q'] ?? 0;
    $vend = ($occ[$id]['vendidos'] ?? 0) + (int)($aj[$id]['vendido_fora'] ?? 0);
    $disp = max(0, $total - $preso);
    $status = $disp > 0 ? 'disponivel' : ($vend >= (int)($p['estoque'] ?? 1) ? 'vendido' : 'reservado');
    if (!empty($aj[$id]['oculto'])) $status = 'oculto';
    $out[$id] = ['disponivel' => $status === 'oculto' ? 0 : $disp, 'status' => $status];
  }
  return $out;
}

function json_out($dados, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function corpo_json(): array {
  $j = json_decode((string)file_get_contents('php://input'), true);
  return is_array($j) ? $j : [];
}

function http_json(string $metodo, string $url, ?array $corpo, array $headers = []): array {
  $ch = curl_init($url);
  $h = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $metodo,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $h,
  ]);
  if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return ['code' => $code, 'json' => json_decode((string)$res, true), 'raw' => $res, 'erro' => $err];
}

function so_digitos(string $s): string { return preg_replace('/\D+/', '', $s); }

function reais(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }

function log_loja(string $msg): void {
  @file_put_contents(dirname(__DIR__) . '/data/log.txt', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

// Texto do pedido (usado no WhatsApp e nos avisos)
function texto_pedido(array $ped): string {
  $c = json_decode($ped['cliente'], true);
  $itens = json_decode($ped['itens'], true);
  $l = ["🛍️ *Pedido {$ped['id']}* · Bazar da Cleide", ''];
  foreach ($itens as $i) $l[] = "• {$i['qtd']}x {$i['nome']} — " . reais($i['preco'] * $i['qtd']);
  $l[] = '';
  $l[] = 'Subtotal: ' . reais((float)$ped['subtotal']);
  $l[] = 'Entrega: ' . ($ped['frete_servico'] ?: '-') . ' — ' . reais((float)$ped['frete']);
  $l[] = '*Total: ' . reais((float)$ped['total']) . '*';
  $l[] = '';
  $l[] = "Nome: {$c['nome']}";
  $l[] = "WhatsApp: {$c['telefone']}";
  if (!empty($c['cep'])) {
    $l[] = "Endereço: {$c['rua']}, {$c['numero']}" . (!empty($c['complemento']) ? " ({$c['complemento']})" : '') .
           " · {$c['bairro']} · {$c['cidade']}/{$c['uf']} · CEP {$c['cep']}";
  }
  if (!empty($c['cpf'])) $l[] = "CPF: {$c['cpf']}";
  return implode("\n", $l);
}

function avisar_cleide(string $texto): void {
  $email = cfg('email_aviso');
  if ($email) @mail($email, 'Bazar da Cleide: aviso de pedido', $texto, "Content-Type: text/plain; charset=utf-8");
  $key = cfg('callmebot_apikey');
  $fone = cfg('whatsapp_cleide');
  if ($key && $fone) {
    $url = 'https://api.callmebot.com/whatsapp.php?phone=' . urlencode('+' . $fone) . '&text=' . urlencode($texto) . '&apikey=' . urlencode($key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    curl_exec($ch); curl_close($ch);
  }
}

// Marca um pedido como pago. Se a reserva já tinha vencido e alguém levou o item, vira "devolver".
function confirmar_pagamento(string $id, string $ref): array {
  $pdo = db();
  $pdo->exec('BEGIN IMMEDIATE');
  try {
    $st = $pdo->prepare("SELECT * FROM pedidos WHERE id=?");
    $st->execute([$id]);
    $ped = $st->fetch();
    if (!$ped) { $pdo->exec('ROLLBACK'); return ['ok' => false, 'erro' => 'pedido não encontrado']; }
    if (in_array($ped['status'], ['pago', 'enviado', 'devolver'], true)) { $pdo->exec('COMMIT'); return ['ok' => true, 'status' => $ped['status'], 'repetido' => true]; }
    $novo = 'pago';
    if ($ped['status'] !== 'aguardando' || (int)$ped['expira_em'] < time()) {
      // reserva já tinha caído: confere se ainda dá para atender
      $est = estoque($id);
      foreach (json_decode($ped['itens'], true) as $i) {
        if (($est[$i['id']]['disponivel'] ?? 0) < $i['qtd']) { $novo = 'devolver'; break; }
      }
    }
    $pdo->prepare("UPDATE pedidos SET status=?, pago_em=?, pagamento_ref=? WHERE id=?")->execute([$novo, time(), $ref, $id]);
    $pdo->exec('COMMIT');
  } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
  $ped['status'] = $novo;
  $msg = $novo === 'pago'
    ? "✅ PAGAMENTO CONFIRMADO\n\n" . texto_pedido($ped) . "\n\nÉ só separar e gerar a etiqueta na SuperFrete."
    : "⚠️ PAGOU DEPOIS DA RESERVA VENCER e o item já tinha sido vendido. Fale com o cliente e devolva o valor.\n\n" . texto_pedido($ped);
  avisar_cleide($msg);
  log_loja("pagamento $id -> $novo ($ref)");
  return ['ok' => true, 'status' => $novo];
}
