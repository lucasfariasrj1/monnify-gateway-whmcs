<?php
/**
 * WHMCS Callback: Monnify Pix
 *
 * Caminho:
 *   /modules/gateways/callback/monnify.php
 *
 * Como configurar no seu backend/Monnify:
 * - URL do webhook/callback: https://SEU-DOMINIO/whmcs/modules/gateways/callback/monnify.php
 * - Enviar header: X-Monnify-Webhook-Token: <token configurado no gateway>
 *
 * Payload esperado (flexível):
 * - invoice_id pode vir em:
 *    metadata.invoice_id  OU  data.metadata.invoice_id  OU  data.invoice_id  OU  invoice_id
 * - charge_id pode vir em:
 *    data.charge_id  OU  data.id  OU  charge_id  OU  id
 * - status pode vir em:
 *    data.status  OU  status
 *
 * Status "pago":
 * - paid, pago, concluida, concluído, concluiu, completed, etc.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
  // Permite execução via web (callback), mas impede include direto em runtime estranho
  define('WHMCS', true);
}

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../monnify.php'; // usa helpers do gateway (monnify_is_paid_status, etc.)

header('Content-Type: application/json; charset=utf-8');

/**
 * Helpers
 */
function monnify_cb_get_raw_body()
{
  $raw = file_get_contents('php://input');
  return is_string($raw) ? $raw : '';
}

function monnify_cb_json()
{
  $raw = monnify_cb_get_raw_body();
  $json = json_decode($raw, true);
  return is_array($json) ? $json : null;
}

function monnify_cb_header($name)
{
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return $_SERVER[$key] ?? '';
}

function monnify_cb_pick($arr, $paths, $default = null)
{
  foreach ($paths as $path) {
    $parts = explode('.', $path);
    $cur = $arr;
    $found = true;
    foreach ($parts as $p) {
      if (!is_array($cur) || !array_key_exists($p, $cur)) {
        $found = false;
        break;
      }
      $cur = $cur[$p];
    }
    if ($found && $cur !== null && $cur !== '') {
      return $cur;
    }
  }
  return $default;
}

function monnify_cb_get_gateway_settings()
{
  // WHMCS armazena no tblpaymentgateways. module=monnify, setting=token/api_url/webhook_token
  $rows = Capsule::table('tblpaymentgateways')
    ->select('setting', 'value')
    ->where('gateway', 'monnify')
    ->get();

  $out = [];
  foreach ($rows as $r) {
    $out[(string)$r->setting] = (string)$r->value;
  }
  return $out;
}

function monnify_cb_find_record_by_charge($chargeId)
{
  if (!$chargeId) return null;

  return Capsule::table('mod_monnify_pix')
    ->where('charge_id', (string)$chargeId)
    ->first();
}

function monnify_cb_find_record_by_invoice($invoiceId)
{
  if (!$invoiceId) return null;

  return Capsule::table('mod_monnify_pix')
    ->where('invoice_id', (int)$invoiceId)
    ->first();
}

function monnify_cb_update_record($invoiceId, array $data)
{
  $now = date('Y-m-d H:i:s');
  Capsule::table('mod_monnify_pix')
    ->where('invoice_id', (int)$invoiceId)
    ->update(array_merge($data, ['updated_at' => $now]));
}

function monnify_cb_invoice_status($invoiceId)
{
  $inv = Capsule::table('tblinvoices')->where('id', (int)$invoiceId)->first();
  return $inv ? (string)$inv->status : null;
}

/**
 * 1) Segurança via token (opcional mas recomendado)
 */
$settings = monnify_cb_get_gateway_settings();
$expectedToken = trim($settings['webhook_token'] ?? '');

if ($expectedToken !== '') {
  $gotToken = trim(monnify_cb_header('X-Monnify-Webhook-Token'));
  if ($gotToken === '' || !hash_equals($expectedToken, $gotToken)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
  }
}

/**
 * 2) Lê payload
 */
$payload = monnify_cb_json();
if (!$payload) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid_json']);
  exit;
}

/**
 * 3) Extrai campos (flexível)
 */
$invoiceId = monnify_cb_pick($payload, [
  'metadata.invoice_id',
  'data.metadata.invoice_id',
  'data.invoice_id',
  'invoice_id'
], null);

$chargeId = monnify_cb_pick($payload, [
  'data.charge_id',
  'data.id',
  'charge_id',
  'id'
], null);

$status = monnify_cb_pick($payload, [
  'data.status',
  'status'
], 'pending');

$txid = monnify_cb_pick($payload, [
  'data.txid',
  'data.pix.txid',
  'txid'
], null);

// Normaliza tipos
$invoiceId = $invoiceId !== null ? (int)$invoiceId : 0;
$chargeId  = $chargeId !== null ? (string)$chargeId : '';
$statusStr = (string)$status;

/**
 * 4) Localiza registro
 */
$record = null;

if ($invoiceId > 0) {
  $record = monnify_cb_find_record_by_invoice($invoiceId);
}

if (!$record && $chargeId !== '') {
  $record = monnify_cb_find_record_by_charge($chargeId);
  if ($record) {
    $invoiceId = (int)$record->invoice_id;
  }
}

if (!$record || $invoiceId <= 0) {
  // Não falha (evita retries eternos), mas registra no log
  logActivity('Monnify Callback: registro/fatura não encontrado. charge_id=' . $chargeId . ' invoice_id=' . $invoiceId . ' payload=' . json_encode($payload));
  http_response_code(200);
  echo json_encode(['ok' => false, 'error' => 'record_not_found']);
  exit;
}

/**
 * 5) Atualiza DB do módulo
 */
monnify_cb_update_record($invoiceId, [
  'status' => $statusStr,
  'txid'   => $txid ?: ($record->txid ?? null),
  'raw_response' => json_encode($payload),
]);

/**
 * 6) Se pago, marca invoice como Paid no WHMCS (idempotente)
 */
if (function_exists('monnify_is_paid_status') ? monnify_is_paid_status($statusStr) : false) {
  $currentStatus = monnify_cb_invoice_status($invoiceId);

  if ($currentStatus && $currentStatus !== 'Paid') {
    // transactionId deve ser único
    $transactionId = $txid ?: ('MONNIFY-' . ($chargeId ?: $invoiceId));

    // Valor: pega do registro salvo (centavos) -> reais
    $amount = 0.0;
    if (!empty($record->amount_cents)) {
      $amount = ((int)$record->amount_cents) / 100;
    }

    // fallback: tenta pegar do payload
    if ($amount <= 0) {
      $amountPayload = monnify_cb_pick($payload, ['data.amount', 'amount'], null);
      if ($amountPayload !== null) {
        // se vier em centavos
        if (is_numeric($amountPayload) && (int)$amountPayload > 1000) {
          $amount = ((int)$amountPayload) / 100;
        } else {
          $amount = (float)$amountPayload;
        }
      }
    }

    // Marca pagamento
    addInvoicePayment(
      $invoiceId,
      $transactionId,
      $amount,
      0,
      'monnify'
    );

    logActivity("Monnify Callback: fatura #{$invoiceId} marcada como Paga. status={$statusStr} charge_id={$chargeId}");
  }
}

http_response_code(200);
echo json_encode([
  'ok' => true,
  'invoice_id' => $invoiceId,
  'charge_id' => $chargeId,
  'status' => $statusStr
]);