<?php
// Cálculo de frete (SuperFrete ou estimativa)
function cotar_frete(string $cep, array $itens): array {
  $prods = produtos();
  $lista = [];
  $valor = 0;
  foreach ($itens as $i) {
    $p = $prods[$i['id'] ?? ''] ?? null;
    $q = max(1, (int)($i['qtd'] ?? 1));
    if (!$p) continue;
    $valor += $p['preco'] * $q;
    $lista[] = ['quantity' => $q, 'weight' => (float)$p['peso_kg'], 'height' => (float)$p['altura_cm'],
                'width' => (float)$p['largura_cm'], 'length' => (float)$p['comprimento_cm']];
  }
  if (!$lista) return [];
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
      'products' => $lista,
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
  if (!$opcoes) {
    // Estimativa por região (sem token ou se a SuperFrete falhar). Ajuste à vontade.
    $peso = array_sum(array_map(fn($x) => $x['weight'] * $x['quantity'], $lista));
    $p2 = (int)substr($cep, 0, 2);
    if ($p2 >= 20 && $p2 <= 28) $base = 22;        // RJ
    elseif ($p2 <= 39) $base = 32;                  // SP, MG, ES
    elseif ($p2 >= 80) $base = 40;                  // Sul
    else $base = 55;                                // demais
    $preco = round($base + max(0, $peso - 1) * 6 + $extra, 2);
    $opcoes[] = ['id' => 'estimado', 'nome' => 'Correios (PAC)', 'preco' => $preco, 'prazo' => 0, 'estimado' => true];
  }
  usort($opcoes, fn($a, $b) => $a['preco'] <=> $b['preco']);
  if (cfg('retirada_ativa')) $opcoes[] = ['id' => 'retirada', 'nome' => cfg('retirada_texto'), 'preco' => 0, 'prazo' => 0];
  return $opcoes;
}
