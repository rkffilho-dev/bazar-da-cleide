<?php
/*
 * CONFIGURAÇÃO DA LOJA — Bazar da Cleide
 * 1) Copie este arquivo para "config.php" (na mesma pasta api/).
 * 2) Preencha os campos abaixo. Nunca publique o config.php no GitHub.
 */
return [
  // Endereço público da loja, sem barra no final (ex.: https://bazardacleide.com.br ou https://ofertasquente.online/bazar)
  'site_url' => 'https://SEU-DOMINIO/loja',

  // ---------------- PAGAMENTO ----------------
  // 'infinitepay'  -> link de pagamento InfinitePay (Pix e cartão). Só precisa da InfiniteTag.
  // 'mercadopago'  -> Checkout Pro do Mercado Pago (Pix e cartão). Precisa do Access Token.
  // 'whatsapp'     -> sem pagamento no site: o pedido vai pro WhatsApp e a Cleide confirma no painel.
  'pagamento' => 'whatsapp',

  // InfinitePay: sua InfiniteTag SEM o "$" (aparece no app InfinitePay)
  'infinitepay_handle' => '',

  // Mercado Pago: Access Token de PRODUÇÃO (Seu negócio > Configurações > Credenciais), começa com APP_USR-
  'mercadopago_token' => '',

  // Quanto tempo o item fica reservado esperando o pagamento
  'reserva_minutos_online'   => 30,   // pagamento no site (Pix/cartão)
  'reserva_minutos_whatsapp' => 120,  // pedido pelo WhatsApp (regra do bazar: 2 horas para pagar)

  // ---------------- FRETE (SuperFrete) ----------------
  // Token de produção: web.superfrete.com > Integrações > Nova integração > Gerar token
  'superfrete_token' => '',
  'superfrete_sandbox' => false,
  'superfrete_servicos' => '1,2,17',   // 1 = PAC, 2 = SEDEX, 17 = Mini Envios
  'cep_origem' => '22785000',          // CEP de onde a Cleide posta (Vargem Grande/RJ) — CONFIRMAR
  'frete_acrescimo' => 0,              // R$ a mais por pedido (embalagem), se quiser
  'retirada_ativa' => true,            // oferece "retirar / ponto de encontro" grátis
  'retirada_texto' => 'Retirar em Vargem Grande (RJ)',

  // ---------------- AVISOS ----------------
  'whatsapp_cleide' => '5521967478819',        // número que recebe os pedidos (com 55 + DDD)
  'email_aviso' => '',                         // e-mail que recebe aviso de pedido (opcional)
  'callmebot_apikey' => '',                    // opcional: aviso automático no WhatsApp via CallMeBot

  // ---------------- PAINEL DA CLEIDE ----------------
  // Senha do painel /admin (troque!)
  'admin_senha' => 'troque-esta-senha',
];
