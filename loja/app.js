/* Bazar da Cleide · loja */
(function () {
  'use strict';
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var brl = function (v) { return 'R$ ' + Number(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.'); };
  var brlCurto = function (v) { return Number(v) % 1 === 0 ? 'R$ ' + Number(v) : brl(v); };
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  };
  var track = function () { try { if (window.fbq) fbq.apply(null, arguments); } catch (e) {} };

  var produtos = [], porId = {}, estoque = {}, apiOk = false, modoPag = 'whatsapp', retiradaTxt = null;
  var cart = store.get('bz_cart', []);
  var filtro = 'Todos';
  var frete = store.get('bz_frete', null);   // {cep, opcoes, escolhido}
  var cliente = store.get('bz_cliente', {});
  var view = 'cart';

  /* ---------- dados ---------- */
  function carregar() {
    return fetch('produtos.json?v=' + Date.now()).then(function (r) { return r.json(); }).then(function (j) {
      produtos = j.produtos; produtos.forEach(function (p) { porId[p.id] = p; });
      return atualizarEstoque();
    });
  }
  function atualizarEstoque() {
    return fetch('api/estoque.php', { cache: 'no-store' }).then(function (r) {
      if (!r.ok) throw new Error('api');
      return r.json();
    }).then(function (j) {
      apiOk = true; estoque = j.estoque || {}; modoPag = j.pagamento || 'whatsapp'; retiradaTxt = j.retirada;
    }).catch(function () {
      apiOk = false; estoque = {};
      produtos.forEach(function (p) { estoque[p.id] = { disponivel: p.estoque || 1, status: 'disponivel' }; });
    }).then(function () { limparCarrinho(); render(); });
  }
  function disp(id) { var e = estoque[id]; return e ? e.disponivel : 0; }
  function status(id) { var e = estoque[id]; return e ? e.status : 'vendido'; }

  // tira do carrinho o que não está mais disponível
  function limparCarrinho(silencioso) {
    var tirados = [];
    cart = cart.filter(function (c) {
      if (!porId[c.id]) return false;
      var d = disp(c.id);
      if (d <= 0) { tirados.push(porId[c.id].nome); return false; }
      if (c.qtd > d) c.qtd = d;
      return true;
    });
    salvar();
    if (tirados.length && !silencioso) toast('Saiu do carrinho (já foi): ' + tirados.join(', '));
  }
  function salvar() { store.set('bz_cart', cart); }
  function qtdNoCarrinho(id) { var c = cart.find(function (x) { return x.id === id; }); return c ? c.qtd : 0; }

  /* ---------- vitrine ---------- */
  function render() {
    var cats = ['Todos'];
    produtos.forEach(function (p) { if (status(p.id) !== 'oculto' && cats.indexOf(p.categoria) < 0) cats.push(p.categoria); });
    $('#filters').innerHTML = cats.map(function (c) { return '<button class="' + (c === filtro ? 'on' : '') + '" data-cat="' + esc(c) + '">' + esc(c) + '</button>'; }).join('');
    var lista = produtos.filter(function (p) { return status(p.id) !== 'oculto' && (filtro === 'Todos' || p.categoria === filtro); });
    // disponíveis primeiro
    lista.sort(function (a, b) { return (disp(b.id) > 0) - (disp(a.id) > 0); });
    var n = lista.filter(function (p) { return disp(p.id) > 0; }).length;
    $('#count').textContent = n + (n === 1 ? ' peça disponível' : ' peças disponíveis');
    $('#grid').innerHTML = lista.map(cardHTML).join('');
    renderBadge();
  }
  function cardHTML(p) {
    var st = status(p.id), off = disp(p.id) <= 0, inCart = qtdNoCarrinho(p.id) > 0;
    var badge = off ? '' : ((p.estoque || 1) > 1 ? disp(p.id) + ' disponíveis' : 'Peça única');
    return '<article class="card' + (off ? ' off' : '') + '" data-open="' + esc(p.id) + '">' +
      '<div class="ph"><img src="img/' + esc(p.fotos[0]) + '" alt="' + esc(p.nome) + '" loading="lazy" width="450" height="562">' +
      (badge ? '<span class="badge">' + esc(badge) + '</span>' : '') +
      (p.fotos.length > 1 ? '<span class="photos">' + p.fotos.length + ' fotos</span>' : '') +
      (off ? '<div class="stamp ' + st + '"><span>' + (st === 'reservado' ? 'Reservado' : 'Vendido') + '</span></div>' : '') +
      '</div><div class="tx"><h3>' + esc(p.nome) + '</h3><p class="rs">' + esc(p.resumo) + '</p>' +
      (off && st === 'reservado' ? '<p class="off-note">Alguém está pagando. Se não pagar, volta.</p>' : '') +
      '<div class="pr"><b>' + brlCurto(p.preco) + '</b>' +
      (off ? '' : '<button class="add' + (inCart ? ' in' : '') + '" data-add="' + esc(p.id) + '" aria-label="' + (inCart ? 'No carrinho' : 'Adicionar ao carrinho') + '">' +
        (inCart ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5 9-10"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>') + '</button>') +
      '</div></div></article>';
  }
  function renderBadge() {
    var n = cart.reduce(function (s, c) { return s + c.qtd; }, 0);
    $('#cartCount').textContent = n;
    var tot = cart.reduce(function (s, c) { return s + porId[c.id].preco * c.qtd; }, 0);
    $('#dockBtn').textContent = n ? 'Ver carrinho · ' + n + (n === 1 ? ' item' : ' itens') + ' · ' + brl(tot) : 'Ver carrinho';
    $('#dock').classList.toggle('on', n > 0 && !document.body.classList.contains('lock'));
  }

  function adicionar(id, abrir) {
    var p = porId[id]; if (!p || disp(id) <= 0) return;
    var c = cart.find(function (x) { return x.id === id; });
    if (c) {
      if ((p.estoque || 1) > 1 && c.qtd < disp(id)) { c.qtd++; toast('Mais 1 no carrinho'); }
      else if (!abrir) { abrirCarrinho(); return; }
    } else { cart.push({ id: id, qtd: 1 }); toast('Adicionado ao carrinho 🩷'); track('track', 'AddToCart', { content_ids: [id], content_type: 'product', value: p.preco, currency: 'BRL' }); }
    frete = null; store.set('bz_frete', null);
    salvar(); render();
    var b = $('#openCart'); b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump');
    if (abrir) { fechar(); abrirCarrinho(); }
  }

  /* ---------- produto ---------- */
  function abrirProduto(id) {
    var p = porId[id]; if (!p) return;
    var off = disp(id) <= 0, st = status(id), inCart = qtdNoCarrinho(id) > 0;
    var unica = (p.estoque || 1) <= 1;
    $('#prodBody').innerHTML = '<div class="pv"><div class="gal"><div class="track" id="galTrack">' +
      p.fotos.map(function (f, i) { return '<img src="img/' + esc(f) + '" alt="' + esc(p.nome) + ' foto ' + (i + 1) + '"' + (i ? ' loading="lazy"' : '') + '>'; }).join('') + '</div>' +
      (p.fotos.length > 1 ? '<button class="nav l" data-gal="-1" aria-label="Foto anterior">‹</button><button class="nav r" data-gal="1" aria-label="Próxima foto">›</button><div class="dots" id="dots">' + p.fotos.map(function (_, i) { return '<i class="' + (i ? '' : 'on') + '"></i>'; }).join('') + '</div>' : '') +
      '</div><div><span class="cat">' + esc(p.categoria) + '</span><h4>' + esc(p.nome) + '</h4><div class="price">' + brl(p.preco) + (unica ? '' : ' <small style="font-size:14px;color:var(--ink-soft);font-weight:600">cada</small>') + '</div>' +
      '<p class="desc">' + esc(p.descricao) + '</p><ul class="facts">' +
      (unica ? '<li>💎 <span><b>Peça única.</b> Quando alguém finaliza o pedido, ela sai da vitrine.</span></li>' : '<li>📦 <span>' + (off ? 'Esgotado' : disp(id) + ' disponíveis') + '</span></li>') +
      '<li>🚚 <span>Envio pra todo o Brasil pelos Correios, ou retirada em Vargem Grande/RJ.</span></li>' +
      '<li>✅ <span>Usado, em bom estado. O que está nas fotos é exatamente o que vai.</span></li></ul>' +
      (off ? '<button class="btn ghost" disabled>' + (st === 'reservado' ? 'Reservado: alguém está pagando' : 'Vendido') + '</button>'
           : '<button class="btn" data-buy="' + esc(id) + '">' + (inCart && unica ? 'Já está no carrinho · ver carrinho' : 'Adicionar ao carrinho · ' + brlCurto(p.preco)) + '</button>') +
      '</div></div>';
    abrirSheet('#prodSheet');
    track('track', 'ViewContent', { content_ids: [id], content_type: 'product', value: p.preco, currency: 'BRL' });
    var t = $('#galTrack');
    if (t && p.fotos.length > 1) t.addEventListener('scroll', function () {
      var i = Math.round(t.scrollLeft / t.clientWidth);
      [].forEach.call($('#dots').children, function (d, k) { d.classList.toggle('on', k === i); });
    }, { passive: true });
  }

  /* ---------- carrinho ---------- */
  function totais() {
    var sub = cart.reduce(function (s, c) { return s + porId[c.id].preco * c.qtd; }, 0);
    var f = frete && frete.escolhido ? frete.opcoes.find(function (o) { return o.id === frete.escolhido; }) : null;
    return { sub: sub, frete: f, total: sub + (f ? f.preco : 0) };
  }
  function abrirCarrinho() { view = 'cart'; renderCarrinho(); abrirSheet('#cartSheet'); }

  function renderCarrinho() {
    var body = $('#cartBody'), foot = $('#cartFoot');
    if (!cart.length) {
      $('#cartTitle').textContent = 'Seu carrinho';
      body.innerHTML = '<div class="empty"><b>Carrinho vazio</b><p>Escolha uma peça na vitrine. Lembrando: cada uma é única!</p></div>';
      foot.innerHTML = '<button class="btn ghost" data-close>Ver a vitrine</button>';
      return;
    }
    var t = totais();
    if (view === 'cart') {
      $('#cartTitle').textContent = 'Seu carrinho';
      body.innerHTML = cart.map(function (c) {
        var p = porId[c.id], multi = (p.estoque || 1) > 1;
        return '<div class="line"><img src="img/' + esc(p.fotos[0]) + '" alt=""><div><b>' + esc(p.nome) + '</b><small>' + (multi ? brl(p.preco) + ' cada' : 'Peça única') + '</small><br>' +
          (multi ? '<span class="qty"><button data-q="' + esc(c.id) + '" data-d="-1" aria-label="Menos">−</button><span>' + c.qtd + '</span><button data-q="' + esc(c.id) + '" data-d="1" aria-label="Mais">+</button></span> ' : '') +
          '<button class="rm" data-rm="' + esc(c.id) + '">Remover</button></div><div class="val">' + brl(p.preco * c.qtd) + '</div></div>';
      }).join('') +
      '<div class="box"><h5>Entrega</h5><form class="cep" id="cepForm"><input id="cepIn" inputmode="numeric" autocomplete="postal-code" placeholder="Seu CEP" maxlength="9" value="' + esc(frete ? frete.cep : (cliente.cep || '')) + '"><button>Calcular</button></form>' +
      '<div id="freteBox">' + freteHTML() + '</div><p class="hint"><a href="https://buscacepinter.correios.com.br/" target="_blank" rel="noopener">Não sei meu CEP</a></p></div>' +
      '<div class="box"><div class="tot"><div><span>Produtos</span><span>' + brl(t.sub) + '</span></div><div><span>Entrega</span><span>' + (t.frete ? (t.frete.preco ? brl(t.frete.preco) : 'Grátis') : 'calcule acima') + '</span></div>' +
      '<div class="big"><span>Total</span><span>' + brl(t.total) + '</span></div></div></div>';
      foot.innerHTML = '<button class="btn" id="goCheckout"' + (t.frete ? '' : ' disabled') + '>' + (t.frete ? 'Continuar · ' + brl(t.total) : 'Calcule a entrega para continuar') + '</button>' +
        '<p class="secure">🔒 Ao finalizar, os itens ficam reservados só pra você.</p>';
      var ci = $('#cepIn'); ci.addEventListener('input', function () { var v = ci.value.replace(/\D/g, '').slice(0, 8); ci.value = v.length > 5 ? v.slice(0, 5) + '-' + v.slice(5) : v; });
    } else {
      $('#cartTitle').textContent = 'Finalizar pedido';
      var ret = t.frete && t.frete.id === 'retirada';
      var c = cliente;
      var f = function (k, label, attrs) { return '<label class="field"><span>' + label + '</span><input name="' + k + '" value="' + esc(c[k] || '') + '" ' + (attrs || '') + '></label>'; };
      body.innerHTML = '<button class="back" id="backCart">‹ Voltar ao carrinho</button>' +
        '<form id="ckForm" novalidate><div class="box"><h5>Seus dados</h5>' +
        f('nome', 'Nome e sobrenome', 'autocomplete="name" required') +
        f('telefone', 'WhatsApp com DDD', 'inputmode="tel" autocomplete="tel" placeholder="(21) 99999-9999" required') +
        f('email', 'E-mail (para o comprovante)', 'type="email" autocomplete="email" inputmode="email"') +
        (ret ? '' : f('cpf', 'CPF (exigido pelos Correios na etiqueta)', 'inputmode="numeric" placeholder="000.000.000-00"')) + '</div>' +
        (ret ? '<div class="box"><h5>Retirada</h5><p class="hint" style="margin:0">' + esc(t.frete.nome) + '</p></div>' :
        '<div class="box"><h5>Endereço de entrega</h5>' +
          '<div class="row">' + f('cep', 'CEP', 'inputmode="numeric" autocomplete="postal-code" readonly style="background:#f4ece4"') + f('numero', 'Número', 'inputmode="numeric" required') + '</div>' +
          f('rua', 'Rua', 'autocomplete="address-line1" required') + f('complemento', 'Complemento', 'placeholder="Apto, bloco, casa…"') + f('bairro', 'Bairro', 'required') +
          '<div class="row">' + f('cidade', 'Cidade', 'required') + '<label class="field" style="flex:.45"><span>UF</span><input name="uf" maxlength="2" value="' + esc(c.uf || '') + '" required style="text-transform:uppercase"></label></div></div>') +
        '<div class="box"><div class="tot"><div><span>Produtos</span><span>' + brl(t.sub) + '</span></div><div><span>' + esc(ret ? 'Retirada' : t.frete.nome) + '</span><span>' + (t.frete.preco ? brl(t.frete.preco) : 'Grátis') + '</span></div><div class="big"><span>Total</span><span>' + brl(t.total) + '</span></div></div></div>' +
        '<div class="msg" id="ckMsg"></div></form>';
      var online = modoPag === 'infinitepay' || modoPag === 'mercadopago';
      foot.innerHTML = '<button class="btn ' + (online ? '' : 'wa') + '" id="ckGo" form="ckForm">' +
        (online ? '🔒 Ir para o pagamento · ' + brl(t.total) : '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3Z"/></svg> Enviar pedido no WhatsApp') + '</button>' +
        '<p class="secure">' + (online ? 'Pix ou cartão em até 12x. Itens reservados por 30 minutos.' : 'A Cleide te responde com o Pix. Itens reservados por 2 horas.') + '</p>';
      if (!ret && frete && frete.cep && !c.rua) viaCep(frete.cep);
      var form = $('#ckForm');
      if (!ret) { form.cep.value = frete.cep; }
      var tel = form.telefone; tel.addEventListener('input', function () {
        var v = tel.value.replace(/\D/g, '').slice(0, 11);
        tel.value = v.length > 6 ? '(' + v.slice(0, 2) + ') ' + v.slice(2, v.length - 4) + '-' + v.slice(-4) : v.length > 2 ? '(' + v.slice(0, 2) + ') ' + v.slice(2) : v;
      });
    }
  }

  function freteHTML() {
    if (!frete || !frete.opcoes) return '';
    return (frete.semEnvio ? '<p class="msg on">Não consegui calcular o envio para esse CEP agora. Tente de novo em instantes ou chame a Cleide no WhatsApp.</p>' : '') + '<div class="opts">' + frete.opcoes.map(function (o) {
      var sub = o.id === 'retirada' ? 'Ou ponto de encontro, combinado no WhatsApp' : (o.prazo ? 'Chega em até ' + o.prazo + ' dias úteis após a postagem' : '');
      return '<label class="opt"><input type="radio" name="frete" value="' + esc(o.id) + '"' + (frete.escolhido === o.id ? ' checked' : '') + '><span class="nm">' + esc(o.nome) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span><b>' + (o.preco ? brl(o.preco) : 'Grátis') + '</b></label>';
    }).join('') + '</div>';
  }

  function calcularFrete(cep) {
    var box = $('#freteBox');
    box.innerHTML = '<p class="hint">Calculando…</p>';
    var itens = cart.map(function (c) { return { id: c.id, qtd: c.qtd }; });
    var req = apiOk
      ? fetch('api/frete.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cep: cep, itens: itens }) }).then(function (r) { return r.json(); })
      : Promise.resolve({ opcoes: [{ id: 'demo', nome: 'Correios (PAC) · demonstração', preco: 29.9, prazo: 6 }, { id: 'retirada', nome: 'Retirar em Vargem Grande (RJ)', preco: 0 }] });
    req.then(function (j) {
      if (j.erro) { box.innerHTML = '<p class="msg on">' + esc(j.erro) + '</p>'; return; }
      var correios = j.opcoes.filter(function (o) { return o.id !== 'retirada'; }).length;
      frete = { cep: cep, opcoes: j.opcoes, escolhido: j.opcoes[0] && j.opcoes[0].id, semEnvio: !correios };
      store.set('bz_frete', frete);
      renderCarrinho();
    }).catch(function () { box.innerHTML = '<p class="msg on">Não consegui calcular agora. Tente de novo.</p>'; });
  }

  function viaCep(cep) {
    fetch('https://viacep.com.br/ws/' + cep.replace(/\D/g, '') + '/json/').then(function (r) { return r.json(); }).then(function (j) {
      var form = $('#ckForm'); if (!form || j.erro) return;
      if (form.rua && !form.rua.value) form.rua.value = j.logradouro || '';
      if (form.bairro && !form.bairro.value) form.bairro.value = j.bairro || '';
      if (form.cidade && !form.cidade.value) form.cidade.value = j.localidade || '';
      if (form.uf && !form.uf.value) form.uf.value = j.uf || '';
      if (form.numero && form.rua.value) form.numero.focus({ preventScroll: true });
    }).catch(function () {});
  }

  function finalizar(e) {
    e.preventDefault();
    var form = $('#ckForm'), msg = $('#ckMsg'), btn = $('#ckGo');
    var dados = {};
    [].forEach.call(form.elements, function (el) { if (el.name) dados[el.name] = el.value.trim(); });
    cliente = Object.assign({}, cliente, dados); store.set('bz_cliente', cliente);
    var erro = '';
    if ((dados.nome || '').split(/\s+/).length < 2) erro = 'Escreva nome e sobrenome.';
    else if ((dados.telefone || '').replace(/\D/g, '').length < 10) erro = 'Coloque o WhatsApp com DDD.';
    else if (form.rua && (!dados.rua || !dados.numero || !dados.bairro || !dados.cidade || !dados.uf)) erro = 'Complete o endereço de entrega.';
    if (erro) { msg.textContent = erro; msg.className = 'msg on'; msg.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    if (!apiOk) { msg.textContent = 'Esta é uma prévia da loja: o pedido só funciona depois de publicada na Hostinger.'; msg.className = 'msg on'; return; }
    btn.disabled = true; var txt = btn.innerHTML; btn.textContent = 'Reservando seus itens…';
    track('track', 'InitiateCheckout', { value: totais().total, currency: 'BRL', num_items: cart.length });
    fetch('api/pedido.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ itens: cart, cliente: dados, frete_id: frete.escolhido }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        var j = res.j;
        if (!res.ok) {
          btn.disabled = false; btn.innerHTML = txt;
          msg.textContent = j.erro || 'Não deu certo. Tente de novo.'; msg.className = 'msg on';
          if (j.estoque) { estoque = j.estoque; limparCarrinho(true); render(); setTimeout(function () { view = 'cart'; renderCarrinho(); }, 2600); }
          if (j.refazer_frete) { frete = null; store.set('bz_frete', null); setTimeout(function () { view = 'cart'; renderCarrinho(); }, 2000); }
          return;
        }
        store.set('bz_ultimo', { id: j.id, url: j.url });
        cart = []; salvar(); frete = null; store.set('bz_frete', null);
        location.href = j.pagamento === 'whatsapp' ? 'pedido.html?id=' + encodeURIComponent(j.id) + '&novo=1' : j.url;
      })
      .catch(function () { btn.disabled = false; btn.innerHTML = txt; msg.textContent = 'Sem conexão. Tente de novo.'; msg.className = 'msg on'; });
  }

  /* ---------- sheets ---------- */
  function abrirSheet(sel) {
    document.querySelectorAll('.sheet.on').forEach(function (s) { s.classList.remove('on'); });
    $(sel).classList.add('on'); $('#scrim').classList.add('on'); document.body.classList.add('lock'); renderBadge();
  }
  function fechar() {
    document.querySelectorAll('.sheet.on').forEach(function (s) { s.classList.remove('on'); });
    $('#scrim').classList.remove('on'); document.body.classList.remove('lock'); renderBadge();
  }
  var tt; function toast(t) { var el = $('#toast'); el.textContent = t; el.classList.add('on'); clearTimeout(tt); tt = setTimeout(function () { el.classList.remove('on'); }, 2200); }

  /* ---------- eventos ---------- */
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-add],[data-open],[data-close],[data-buy],[data-rm],[data-q],[data-cat],[data-gal],#openCart,#dockBtn,#goCheckout,#backCart,#scrim');
    if (!t) return;
    if (t.dataset.add) { e.stopPropagation(); adicionar(t.dataset.add); return; }
    if (t.dataset.buy) { adicionar(t.dataset.buy, true); return; }
    if (t.dataset.open) { abrirProduto(t.dataset.open); return; }
    if (t.hasAttribute('data-close') || t.id === 'scrim') { fechar(); return; }
    if (t.dataset.cat) { filtro = t.dataset.cat; render(); return; }
    if (t.dataset.gal) { var g = $('#galTrack'); g.scrollBy({ left: g.clientWidth * +t.dataset.gal, behavior: 'smooth' }); return; }
    if (t.dataset.rm) { cart = cart.filter(function (c) { return c.id !== t.dataset.rm; }); frete = null; store.set('bz_frete', null); salvar(); render(); renderCarrinho(); return; }
    if (t.dataset.q) { var c = cart.find(function (x) { return x.id === t.dataset.q; }); c.qtd = Math.max(1, Math.min(disp(c.id), c.qtd + +t.dataset.d)); frete = null; store.set('bz_frete', null); salvar(); render(); renderCarrinho(); return; }
    if (t.id === 'openCart' || t.id === 'dockBtn') { abrirCarrinho(); return; }
    if (t.id === 'goCheckout') { view = 'checkout'; renderCarrinho(); $('#cartBody').scrollTop = 0; return; }
    if (t.id === 'backCart') { e.preventDefault(); view = 'cart'; renderCarrinho(); }
  });
  document.addEventListener('submit', function (e) {
    if (e.target.id === 'cepForm') { e.preventDefault(); var cep = $('#cepIn').value.replace(/\D/g, ''); if (cep.length !== 8) { toast('Digite os 8 números do CEP'); return; } calcularFrete(cep); }
    if (e.target.id === 'ckForm') finalizar(e);
  });
  document.addEventListener('change', function (e) {
    if (e.target.name === 'frete' && frete) { frete.escolhido = e.target.value; store.set('bz_frete', frete); renderCarrinho(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
  document.addEventListener('visibilitychange', function () { if (!document.hidden && produtos.length) atualizarEstoque(); });
  setInterval(function () { if (!document.hidden && produtos.length) atualizarEstoque(); }, 45000);

  // ?p=lote-1 abre o produto direto (para link em anúncio)
  carregar().then(function () {
    var m = location.search.match(/[?&]p=([\w-]+)/);
    if (m && porId[m[1]]) abrirProduto(m[1]);
  });
})();
