<?php
require __DIR__ . '/lib.php';
expirar_reservas();
json_out(['estoque' => estoque(), 'pagamento' => cfg('pagamento'), 'retirada' => cfg('retirada_ativa') ? cfg('retirada_texto') : null, 'agora' => time()]);
