<?php
// Painel da Cleide: pedidos e produtos.
require dirname(__DIR__) . '/api/lib.php';
session_start();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (isset($_POST['senha'])) {
  if (hash_equals((string)cfg('admin_senha'), (string)$_POST['senha'])) { session_regenerate_id(true); $_SESSION['adm'] = 1; }
  else { sleep(2); $erro_login = true; }
}
if (isset($_GET['sair'])) { session_destroy(); header('Location: ./'); exit; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$logado = !empty($_SESSION['adm']);
if ($logado && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) exit('Sessão expirada, recarregue.');
  $pdo = db(); $id = (string)($_POST['id'] ?? '');
  switch ($_POST['acao']) {
    case 'pago': confirmar_pagamento($id, 'confirmado no painel'); break;
    case 'cancelar': $pdo->prepare("UPDATE pedidos SET status='cancelado' WHERE id=?")->execute([$id]); break;
    case 'enviado': $pdo->prepare("UPDATE pedidos SET status='enviado', obs=? WHERE id=?")->execute([trim((string)$_POST['obs']), $id]); break;
    case 'resolvido': $pdo->prepare("UPDATE pedidos SET status='cancelado', obs='valor devolvido' WHERE id=?")->execute([$id]); break;
    case 'mais30': $pdo->prepare("UPDATE pedidos SET expira_em=MAX(expira_em,?)+1800 WHERE id=? AND status='aguardando'")->execute([time(), $id]); break;
    case 'ocultar': case 'mostrar':
      $pdo->prepare("INSERT INTO ajustes(produto_id,oculto) VALUES(?,?) ON CONFLICT(produto_id) DO UPDATE SET oculto=excluded.oculto")->execute([$id, $_POST['acao'] === 'ocultar' ? 1 : 0]); break;
    case 'vendido_fora':
      $pdo->prepare("INSERT INTO ajustes(produto_id,vendido_fora) VALUES(?,?) ON CONFLICT(produto_id) DO UPDATE SET vendido_fora=excluded.vendido_fora")->execute([$id, max(0, (int)$_POST['qtd'])]); break;
  }
  header('Location: ./' . (isset($_POST['aba']) ? '?aba=' . urlencode($_POST['aba']) : '')); exit;
}
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Painel · Bazar da Cleide</title>
<style>
:root{--cream:#f7f1ea;--paper:#fdf8f3;--ink:#3a2418;--soft:#6f5547;--rose:#c9897a;--line:#ecdccd;--choc:#3d271c;--ok:#25a85a;--warn:#c9822a;--bad:#b4473b}
*{box-sizing:border-box}body{margin:0;background:var(--cream);color:var(--ink);font:15px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.w{max-width:820px;margin:0 auto;padding:16px}h1{font:600 26px Georgia,serif;margin:6px 0 12px}
.tabs{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}.tabs a{padding:8px 14px;border-radius:999px;border:1px solid var(--line);background:var(--paper);color:var(--ink);text-decoration:none;font-weight:600;font-size:14px}.tabs a.on{background:var(--choc);color:#fff}
.c{background:var(--paper);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:12px}
.top{display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap}
.tag{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#fff;background:var(--soft)}
.tag.aguardando{background:var(--warn)}.tag.pago{background:var(--ok)}.tag.enviado{background:#3b6fb4}.tag.devolver{background:var(--bad)}.tag.expirado,.tag.cancelado{background:#9a8b80}
pre{white-space:pre-wrap;font:13px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;margin:8px 0;background:var(--cream);padding:10px;border-radius:10px}
form.i{display:inline}button,.b{font:600 13px -apple-system,"Segoe UI",Roboto,Arial,sans-serif;padding:8px 12px;border-radius:10px;border:1px solid var(--line);background:#fff;color:var(--ink);cursor:pointer;text-decoration:none;display:inline-block;margin:3px 4px 0 0}
button.ok{background:var(--ok);color:#fff;border:0}button.bad{color:var(--bad)}input{font:inherit;padding:8px;border-radius:10px;border:1px solid var(--line)}
.p{display:grid;grid-template-columns:56px 1fr;gap:10px;align-items:center}.p img{width:56px;height:70px;object-fit:cover;border-radius:8px}
small{color:var(--soft)}
</style></head><body><div class="w">
<?php if (!$logado): ?>
  <h1>Painel do Bazar</h1>
  <form method="post" class="c"><p>Senha do painel</p><input type="password" name="senha" autofocus> <button class="ok">Entrar</button>
  <?= !empty($erro_login) ? '<p style="color:var(--bad)">Senha errada.</p>' : '' ?></form>
<?php else:
  expirar_reservas();
  $aba = $_GET['aba'] ?? 'pedidos';
  $est = estoque();
?>
  <div class="top"><h1>Painel do Bazar</h1><a class="b" href="?sair=1">Sair</a></div>
  <nav class="tabs"><a href="?aba=pedidos" class="<?= $aba==='pedidos'?'on':'' ?>">Pedidos ativos</a><a href="?aba=todos" class="<?= $aba==='todos'?'on':'' ?>">Todos os pedidos</a><a href="?aba=produtos" class="<?= $aba==='produtos'?'on':'' ?>">Produtos</a></nav>
<?php if ($aba === 'produtos'):
  foreach (produtos() as $id => $p): $e = $est[$id]; ?>
  <div class="c p"><img src="../img/<?= $h($p['fotos'][0]) ?>" alt="">
    <div><b><?= $h($p['nome']) ?></b> · <?= reais((float)$p['preco']) ?> <span class="tag <?= $e['status']==='disponivel'?'pago':($e['status']==='reservado'?'aguardando':'cancelado') ?>"><?= $h($e['status']) ?></span><br>
    <small>Estoque cadastrado: <?= (int)$p['estoque'] ?> · disponível agora: <?= (int)$e['disponivel'] ?></small><br>
    <form class="i" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="aba" value="produtos"><input type="hidden" name="id" value="<?= $h($id) ?>">
      <?php if ($e['status']==='oculto'): ?><button name="acao" value="mostrar">Mostrar na loja</button><?php else: ?><button name="acao" value="ocultar">Esconder da loja</button><?php endif; ?>
    </form>
    <form class="i" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="aba" value="produtos"><input type="hidden" name="id" value="<?= $h($id) ?>">
      <small>Vendido fora do site:</small> <input name="qtd" type="number" min="0" max="<?= (int)$p['estoque'] ?>" value="<?= (int)(ajustes()[$id]['vendido_fora'] ?? 0) ?>" style="width:64px"> <button name="acao" value="vendido_fora">Salvar</button>
    </form></div></div>
<?php endforeach; else:
  $sql = $aba === 'todos' ? "SELECT * FROM pedidos ORDER BY criado_em DESC LIMIT 200"
       : "SELECT * FROM pedidos WHERE status IN ('aguardando','pago','devolver') ORDER BY CASE status WHEN 'devolver' THEN 0 WHEN 'pago' THEN 1 ELSE 2 END, criado_em DESC";
  $lista = db()->query($sql)->fetchAll();
  if (!$lista) echo '<p class="c">Nenhum pedido por aqui ainda.</p>';
  foreach ($lista as $p): $c = json_decode($p['cliente'], true); ?>
  <div class="c">
    <div class="top"><b><?= $h($p['id']) ?> · <?= reais((float)$p['total']) ?></b><span class="tag <?= $h($p['status']) ?>"><?= $h($p['status'] === 'devolver' ? 'pago fora do prazo: devolver' : $p['status']) ?></span></div>
    <small><?= date('d/m H:i', (int)$p['criado_em']) ?> · <?= $h($p['pagamento']) ?><?= $p['status']==='aguardando' ? ' · reserva até ' . date('H:i', (int)$p['expira_em']) : '' ?><?= $p['pagamento_ref'] ? ' · ' . $h($p['pagamento_ref']) : '' ?></small>
    <pre><?= $h(texto_pedido($p)) ?><?= $p['obs'] ? "\nObs.: " . $h($p['obs']) : '' ?></pre>
    <a class="b" target="_blank" href="https://wa.me/55<?= $h($c['telefone']) ?>">WhatsApp do cliente</a>
    <form class="i" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= $h($p['id']) ?>"><input type="hidden" name="aba" value="<?= $h($aba) ?>">
    <?php if ($p['status'] === 'aguardando'): ?>
      <button class="ok" name="acao" value="pago" onclick="return confirm('Confirmar que o Pix de <?= reais((float)$p['total']) ?> caiu?')">Pix recebido</button>
      <button name="acao" value="mais30">+30 min de reserva</button>
      <button class="bad" name="acao" value="cancelar" onclick="return confirm('Cancelar e liberar os itens?')">Cancelar e liberar</button>
    <?php elseif ($p['status'] === 'pago'): ?>
      <input name="obs" placeholder="Código de rastreio (opcional)"> <button class="ok" name="acao" value="enviado">Marcar como enviado</button>
    <?php elseif ($p['status'] === 'devolver'): ?>
      <button name="acao" value="resolvido">Já devolvi o valor</button>
    <?php endif; ?>
    </form>
  </div>
<?php endforeach; endif; endif; ?>
</div></body></html>
