<?php
// Cálculo de frete pela SuperFrete, com o pedido inteiro numa caixa só.

// Caixas padrão (cm) por faixa de peso do conteúdo. Ajuste se a Cleide usar outras.
function caixa_do_pedido(float $peso_produtos): array {
  $peso = round($peso_produtos * (float)cfg('embalagem_margem', 1.10) + (float)cfg('embalagem_peso_kg', 0.3), 2);
  $caixas = cfg('caixas', [
    ['ate_kg' => 0.5, 'altura' => 6,  'largura' => 16, 'comprimento' => 22],
    ['ate_kg' => 1,   'altura' => 10, 'largura' => 20, 'comprimento' => 25],
    ['ate_kg' => 3,   'altura' => 15, 'largura' => 25, 'comprimento' => 35],
    ['ate_kg' => 6,   'altura' => 20, 'largura' => 30, 'comprimento' => 40],
    ['ate_kg' => 12,  'altura' => 30, 'largura' => 40, 'comprimento' => 50],
    ['ate_kg' => 30,  'altura' => 40, 'largura' => 50, 'comprimento' => 60],
  ]);
  $c = end($caixas);
  foreach ($caixas as $x) if ($peso <= $x['ate_kg']) { $c = $x; break; }
  return ['height' => $c['altura'], 'width' => $c['largura'], 'length' => $c['comprimento'], 'weight' => max(0.1, $peso)];
}

function cotar_frete(string $cep, array $itens): array {
  $prods = produtos();
  // Tudo do pedido vai numa embalagem só: soma o peso dos produtos (sem embalagem),
  // acrescenta a embalagem e escolhe a caixa padrão que comporta esse peso.
  $peso = 0.0; $n = 0;
  foreach ($itens as $i) {
    $p = $prods[$i['id'] ?? ''] ?? null;
    if (!$p) continue;
    $q = max(1, (int)($i['qtd'] ?? 1));
    $peso += (float)$p['peso_kg'] * $q; $n += $q;
  }
  if (!$n) return [];
  $pacote = caixa_do_pedido($peso);
  $extra = (float)cfg('frete_acrescimo', 0);
  $opcoes = [];
  $token = cfg('superfrete_token');
  if ($token) {
    $base = cfg('superfrete_sandbox') ? 'https://sandbox.superfrete.com' : 'https://api.superfrete.com';
    $r = http_json('POST', $base . '/api/v0/calculator', [
      'from' => ['postal_code' => so_digitos((string)cfg('cep_origem'))],
      'to' => ['postal_code' => $cep],
      'services' => (string)cfg('superfrete_servicos', '1,2,17'),
      'options' => ['own_hand' => false, 'receipt' => false, 'insurance_value' => 0, 'use_insurance_value' => false],
      'package' => $pacote,
    ], ['Authorization: Bearer ' . $token, 'User-Agent: BazarDaCleide (loja)']);
    if ($r['code'] === 200 && is_array($r['json'])) {
      foreach ($r['json'] as $s) {
        if (!empty($s['error']) || !isset($s['price'])) continue;
        $opcoes[] = ['id' => 'sf-' . $s['id'], 'nome' => $s['name'], 'preco' => round((float)$s['price'] + $extra, 2),
                     'prazo' => (int)($s['delivery_time'] ?? 0)];
      }
    } else {
      log_loja('superfrete erro ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 300));
    }
  }
  // Sem estimativa: só entra frete real calculado pela SuperFrete para o pacote do carrinho.
  usort($opcoes, fn($a, $b) => $a['preco'] <=> $b['preco']);
  if (cfg('retirada_ativa')) $opcoes[] = ['id' => 'retirada', 'nome' => cfg('retirada_texto'), 'preco' => 0, 'prazo' => 0];
  return $opcoes;
}
