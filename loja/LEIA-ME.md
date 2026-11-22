# Loja do Bazar da Cleide

Vitrine com carrinho, frete pela SuperFrete, pagamento (InfinitePay, Mercado Pago ou pedido no WhatsApp) e reserva automática para não vender a mesma peça duas vezes. Roda em qualquer hospedagem com PHP 8 (Hostinger serve).

## Como funciona a reserva (sem venda duplicada)

1. A pessoa finaliza o pedido: o servidor trava o estoque por um instante, confere se a peça ainda está livre e já reserva.
2. Na vitrine a peça aparece como **Reservado** (os outros não conseguem comprar).
3. Pagou: vira **Vendido** sozinho (InfinitePay/Mercado Pago) ou a Cleide clica em "Pix recebido" no painel (modo WhatsApp).
4. Não pagou no prazo (30 min no site, 2 h no WhatsApp): a peça volta para a vitrine sozinha.
5. Se alguém pagar depois que a reserva venceu e outra pessoa já tiver levado a peça, o pedido aparece no painel como **devolver** e a Cleide recebe aviso.

## Publicar na Hostinger (uns 10 minutos)

1. hPanel > **Arquivos > Gerenciador de arquivos** > `public_html` do domínio (ex.: ofertasquente.online).
2. Crie a pasta `loja`, entre nela, envie o `loja.zip` e clique com o botão direito > **Extrair**.
3. Em `loja/api/`, renomeie `config.exemplo.php` para `config.php` e edite:
   - `site_url`: ex. `https://ofertasquente.online/loja`
   - `pagamento`: `infinitepay`, `mercadopago` ou `whatsapp`
   - `infinitepay_handle`: a InfiniteTag da Cleide, sem o `$` (ou `mercadopago_token`)
   - `superfrete_token` e `cep_origem` (CEP de onde ela posta)
   - `admin_senha`: troque!
4. Abra `https://SEU-DOMINIO/loja` (loja) e `https://SEU-DOMINIO/loja/admin` (painel da Cleide).

A pasta `loja/data` guarda os pedidos e é bloqueada para acesso externo. Faça backup dela de vez em quando.

## Token da SuperFrete

web.superfrete.com > **Integrações > Nova integração** > API > **Gerar token**. É gratuito. Sem o token o envio não é calculado (só aparece a retirada). O frete é o real da SuperFrete para o pacote do carrinho inteiro (soma peso e medidas dos itens).

## Produtos

Ficam em `produtos.json` (nome, preço, estoque, fotos, peso e medidas da caixa para o frete). Fotos em `img/`. Para tirar um item sem apagar, use o painel > Produtos > "Esconder da loja". Vendeu fora do site? Painel > Produtos > "Vendido fora do site".

Link direto para um produto (bom para anúncio): `https://SEU-DOMINIO/loja/?p=lote-1`

## Avisos para a Cleide

- Sempre: o cliente cai numa página com botão que abre o WhatsApp da Cleide com o pedido pronto.
- Opcional: `email_aviso` (e-mail a cada pedido/pagamento) e `callmebot_apikey` (mensagem automática no WhatsApp dela via CallMeBot, grátis: ela manda "I allow callmebot to send me messages" para o número do CallMeBot e recebe a chave).
