<?php

if (!defined("WHMCS")) {
  die("Este arquivo não pode ser acessado diretamente");
}

use Illuminate\Database\Capsule\Manager as Capsule;

function monnify_MetaData()
{
  return [
    'DisplayName' => 'Monnify Pix',
    'APIVersion'  => '1.0',
  ];
}

function monnify_config()
{
  return [
    'FriendlyName' => [
      'Type'  => 'System',
      'Value' => 'Monnify Pix',
    ],

    'api_url' => [
      'FriendlyName' => 'API URL',
      'Type'         => 'text',
      'Size'         => '80',
      'Default'      => 'https://api.monnify.com.br',
      'Description'  => 'Ex: https://api.monnify.com.br',
    ],

    'token' => [
      'FriendlyName' => 'Token Bearer',
      'Type'         => 'text',
      'Size'         => '120',
      'Default'      => '',
      'Description'  => 'Token JWT Bearer para autenticação na API Monnify',
    ],

    'webhook_token' => [
      'FriendlyName' => 'Webhook Token (opcional)',
      'Type'         => 'text',
      'Size'         => '80',
      'Default'      => '',
      'Description'  => 'Se você for usar callback/webhook, valide com este token (header X-Monnify-Webhook-Token).',
    ],

    'auto_check_status' => [
      'FriendlyName' => 'Checar status automaticamente (polling)',
      'Type'         => 'yesno',
      'Default'      => 'on',
      'Description'  => 'Ao abrir a fatura, consulta /tenant/charges/{id}/status e marca como paga se estiver pago.',
    ],
  ];
}

/**
 * HTTP helper usando cURL (sem Guzzle)
 */
function monnify_http($method, $url, $token, $payload = null)
{
  $ch = curl_init();

  $headers = [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token,
  ];

  curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);

  if ($payload !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  }

  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

  curl_close($ch);

  if ($err) {
    return ['ok' => false, 'code' => 0, 'error' => $err, 'raw' => null, 'json' => null];
  }

  $json = json_decode($body, true);

  return [
    'ok'    => ($code >= 200 && $code < 300),
    'code'  => $code,
    'raw'   => $body,
    'json'  => is_array($json) ? $json : null,
    'error' => null,
  ];
}

function monnify_is_paid_status($status)
{
  $s = mb_strtolower(trim((string)$status));
  $paid = ['paid', 'pago', 'concluida', 'concluída', 'concluido', 'concluído', 'completed'];
  return in_array($s, $paid, true);
}

function monnify_get_record_by_invoice($invoiceId)
{
  return Capsule::table('mod_monnify_pix')->where('invoice_id', (int)$invoiceId)->first();
}

/**
 * Salva/atualiza registro.
 * - Se existir coluna "amount", salva nela.
 * - Se não existir, tenta salvar em amount_cents (compatibilidade), mas com valor em centavos DESATIVADO.
 */
function monnify_save_record($invoiceId, $clientId, array $data)
{
  $now = date('Y-m-d H:i:s');

  // Detecta se a coluna "amount" existe (opcional)
  $hasAmountCol = false;
  try {
    $cols = Capsule::select("SHOW COLUMNS FROM mod_monnify_pix LIKE 'amount'");
    $hasAmountCol = !empty($cols);
  } catch (Throwable $e) {
    $hasAmountCol = false;
  }

  $payload = [
    'invoice_id'    => (int)$invoiceId,
    'client_id'     => (int)$clientId,
    'charge_id'     => $data['charge_id'] ?? null,
    'reference_id'  => $data['reference_id'] ?? null,
    'txid'          => $data['txid'] ?? null,
    'status'        => $data['status'] ?? null,
    'qr_code_url'   => $data['qr_code_url'] ?? null,
    'copia_e_cola'  => $data['copia_e_cola'] ?? null,
    'checkout_url'  => $data['checkout_url'] ?? null,
    'raw_response'  => $data['raw_response'] ?? null,
    'updated_at'    => $now,
  ];

  // Salva amount em reais, se coluna existir
  if ($hasAmountCol) {
    $payload['amount'] = $data['amount'] ?? null; // ex: "10.00"
  }

  $exists = Capsule::table('mod_monnify_pix')->where('invoice_id', (int)$invoiceId)->exists();

  if ($exists) {
    Capsule::table('mod_monnify_pix')->where('invoice_id', (int)$invoiceId)->update($payload);
  } else {
    $payload['created_at'] = $now;
    Capsule::table('mod_monnify_pix')->insert($payload);
  }
}

function monnify_create_charge($params)
{
  $apiUrl = rtrim($params['api_url'], '/');
  $token  = trim((string)$params['token']);

  if (!$token) {
    return ['ok' => false, 'error' => 'Token Bearer não configurado no gateway.'];
  }

  $invoiceId = (int)$params['invoiceid'];
  $clientId  = (int)$params['clientdetails']['userid'];
  $fullName  = (string)$params['clientdetails']['fullname'];
  $email     = (string)$params['clientdetails']['email'];
  $phone     = (string)($params['clientdetails']['phonenumber'] ?? '');

  // ✅ SEM conversão para centavos
  // WHMCS já vem em reais. Vamos mandar como decimal "10.00"
  $amount = number_format((float)$params['amount'], 2, '.', '');

  $payload = [
    'type'        => 'immediate',
    'amount'      => $amount,
    'customer_id' => $clientId,
    'description' => 'Fatura WHMCS #' . $invoiceId,
    'metadata' => [
      'nome'       => $fullName,
      'email'      => $email,
      'phone'      => $phone,
      'invoice_id' => $invoiceId,
      'client_id'  => $clientId,
    ],
  ];

  $res = monnify_http('POST', $apiUrl . '/tenant/charges', $token, $payload);

  if (!$res['ok'] || empty($res['json']['success'])) {
    $msg = 'Falha ao criar cobrança Pix.';
    if (!empty($res['json']['message'])) $msg .= ' ' . $res['json']['message'];
    return ['ok' => false, 'error' => $msg, 'debug' => $res];
  }

  $data = $res['json']['data'] ?? [];
  $pix  = $data['pix'] ?? ($data['payment'] ?? []);

  $charge_id    = $data['id'] ?? null;
  $reference_id = $data['reference_id'] ?? null;
  $status       = $data['status'] ?? 'pending';

  $copia_e_cola = $pix['copia_e_cola'] ?? $pix['qr_code'] ?? ($pix['emv'] ?? null);
  $qr_code_url  = $pix['qr_code_url'] ?? $pix['qrcode_image_url'] ?? $pix['qr_code_base64'] ?? null;
  $txid         = $pix['txid'] ?? null;
  $checkout_url = $pix['checkout_url'] ?? null;

  return [
    'ok' => true,
    'data' => [
      'charge_id'    => (string)$charge_id,
      'reference_id' => (string)$reference_id,
      'txid'         => (string)$txid,
      'status'       => (string)$status,
      'amount'       => $amount, // ✅ reais
      'qr_code_url'  => (string)$qr_code_url,
      'copia_e_cola' => (string)$copia_e_cola,
      'checkout_url' => (string)$checkout_url,
      'raw_response' => json_encode($res['json']),
    ],
  ];
}

function monnify_check_and_mark_paid($params, $record)
{
  if (empty($params['auto_check_status']) || $params['auto_check_status'] !== 'on') {
    return $record;
  }

  $token = trim((string)$params['token']);
  if (!$token) return $record;

  $apiUrl = rtrim($params['api_url'], '/');

  $chargeId  = $record->charge_id ?? null;
  $invoiceId = (int)$params['invoiceid'];

  if (!$chargeId) return $record;

  $res = monnify_http('GET', $apiUrl . '/tenant/charges/' . rawurlencode($chargeId) . '/status', $token);

  if (!$res['ok'] || empty($res['json']['success'])) {
    return $record;
  }

  $data   = $res['json']['data'] ?? [];
  $status = (string)($data['status'] ?? 'pending');

  // Atualiza DB
  monnify_save_record($invoiceId, (int)$params['clientdetails']['userid'], [
    'status'       => $status,
    'raw_response' => json_encode($res['json']),
  ]);

  // Se pago, marca fatura no WHMCS
  if (monnify_is_paid_status($status)) {
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

    if ($invoice && $invoice->status !== 'Paid') {
      $transactionId = $record->txid ?: ($data['reference_id'] ?? ('MONNIFY-' . $chargeId));

      // ✅ valor sempre do WHMCS (reais)
      $paymentAmount = (float)$params['amount'];

      addInvoicePayment(
        $invoiceId,
        (string)$transactionId,
        $paymentAmount,
        0,
        'monnify'
      );

      logActivity("Monnify Pix: fatura #{$invoiceId} marcada como Paga. Status={$status} Charge={$chargeId}");
    }
  }

  return monnify_get_record_by_invoice($invoiceId) ?: $record;
}

function monnify_link($params)
{
  $invoiceId = (int)$params['invoiceid'];
  $clientId  = (int)$params['clientdetails']['userid'];

  $record = monnify_get_record_by_invoice($invoiceId);

  if (!$record) {
    $created = monnify_create_charge($params);

    if (empty($created['ok'])) {
      $msg = $created['error'] ?? 'Erro ao gerar Pix.';
      logActivity('Monnify Pix: ' . $msg);

      return '<div style="padding:12px;border:1px solid #f3c2c2;background:#fff5f5;border-radius:10px;">
        <strong>Erro ao gerar Pix</strong><br>' . htmlspecialchars($msg) . '
      </div>';
    }

    monnify_save_record($invoiceId, $clientId, $created['data']);
    $record = monnify_get_record_by_invoice($invoiceId);
  }

  if ($record) {
    $record = monnify_check_and_mark_paid($params, $record);
  }

  $status   = $record->status ?? 'pending';
  $qrUrl    = $record->qr_code_url ?? '';
  $copia    = $record->copia_e_cola ?? '';
  $txid     = $record->txid ?? '';
  $checkout = $record->checkout_url ?? '';

  $statusLabel = monnify_is_paid_status($status) ? 'PAGO' : 'PENDENTE';

  $html  = '<div style="font-family: Arial, sans-serif; max-width: 520px; margin: 0 auto; text-align: center; padding: 0;">';
  $html .= '<div style="padding:14px;border:1px solid #e5e7eb;border-radius:12px;">';
  $html .= '<h3 style="margin:0 0 6px 0;">Pague com Pix</h3>';
  $html .= '<div style="font-size:13px;opacity:.9;margin-bottom:10px;">Status: <strong>' . htmlspecialchars($statusLabel) . '</strong></div>';

  if ($checkout) {
    $html .= '<p style="margin:0 0 10px 0;"><a href="' . htmlspecialchars($checkout) . '" target="_blank" rel="noopener">Abrir checkout Pix</a></p>';
  }

  if ($qrUrl) {
    $html .= '<div style="margin:10px 0;">';
    $html .= '<img src="' . htmlspecialchars($qrUrl) . '" alt="QR Code Pix" style="max-width:320px;width:100%;height:auto;border-radius:12px;border:1px solid #eee;">';
    $html .= '</div>';
  } else {
    $html .= '<p style="color:#b42318;">QR Code não disponível</p>';
  }

  if ($copia) {
    $html .= '<p style="margin:10px 0 6px;"><strong>Copia e Cola</strong></p>';
    $html .= '<textarea id="monnifyPixCode" readonly style="width:100%;min-height:110px;white-space:pre-wrap;padding:10px;border-radius:10px;border:1px solid #e5e7eb;">'
      . htmlspecialchars($copia) . '</textarea>';

    $html .= '<p style="margin:10px 0 0;">';
    $html .= '<button type="button" id="monnifyCopyBtn" style="padding:10px 16px;border-radius:10px;border:0;cursor:pointer;">Copiar código Pix</button>';
    $html .= '</p>';

    $html .= '<script>
      (function(){
        var btn = document.getElementById("monnifyCopyBtn");
        var ta  = document.getElementById("monnifyPixCode");
        if(!btn || !ta) return;
        btn.addEventListener("click", async function(){
          try {
            await navigator.clipboard.writeText(ta.value);
            btn.innerText = "Copiado!";
            setTimeout(function(){ btn.innerText = "Copiar código Pix"; }, 1500);
          } catch(e) {
            ta.focus(); ta.select();
            document.execCommand("copy");
            btn.innerText = "Copiado!";
            setTimeout(function(){ btn.innerText = "Copiar código Pix"; }, 1500);
          }
        });
      })();
    </script>';
  }

  if ($txid) {
    $html .= '<div style="font-size:12px;opacity:.75;margin-top:12px;">TXID: ' . htmlspecialchars($txid) . '</div>';
  }

  $html .= '</div></div>';

  return $html;
}