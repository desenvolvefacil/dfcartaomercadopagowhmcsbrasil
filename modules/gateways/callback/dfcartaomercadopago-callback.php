<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

header('Content-Type: application/json');

$gateway="dfcartaomercadopago";
$params=getGatewayVariables($gateway);

$data=json_decode(file_get_contents("php://input"),true);

$invoiceId=checkCbInvoiceID($data['invoiceid'],$gateway);

$invoice=Capsule::table('tblinvoices')
    ->where('id',$invoiceId)
    ->first();

$clientId=$invoice->userid;
$amount=$invoice->total;

$formData=$data['formData'];
$token=$formData['token'];

$accessToken=$params['AccessTokenProducao'];

$idempotencyKey = md5($invoiceId . $amount);


function mpRequest($url, $token, $data = null, $idempotencyKey = null) {

    if (!$idempotencyKey) {
        $idempotencyKey = uniqid('mp_', true);
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => $data != null,
        CURLOPT_POSTFIELDS => $data ? json_encode($data) : null,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer ".$token,
            "Content-Type: application/json",
            "x-integrator-id: dev_5f464b885a5611f09813c2",
            "X-Idempotency-Key: ".$idempotencyKey
        ]
    ]);

    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $r;
}


$email=$formData['payer']['email'];

$search=mpRequest(
    "https://api.mercadopago.com/v1/customers/search?email=".$email,
    $accessToken
);

if(!empty($search['results']))
    $customerId=$search['results'][0]['id'];
else{
    $c=mpRequest(
        "https://api.mercadopago.com/v1/customers",
        $accessToken,
        ["email"=>$email]
    );
    $customerId=$c['id'];
}

$card=mpRequest(
    "https://api.mercadopago.com/v1/customers/$customerId/cards",
    $accessToken,
    ["token"=>$token]
);

$cardId=$card['id'];

Capsule::table('tblclients')
    ->where('id',$clientId)
    ->update([
        'gatewayid'=>$customerId.'|'.$cardId
    ]);

$payment=mpRequest(
    "https://api.mercadopago.com/v1/payments",
    $accessToken,
    [
        "transaction_amount"=>(float)$amount,
        "token"=>$token,
        "installments"=>$formData['installments'],
        "payment_method_id"=>$formData['payment_method_id'],
        "payer"=>["email"=>$email]
    ]
);

if($payment['status']=='approved'){

    $fee = 0;
    
    if (!empty($payment['fee_details'][0]['amount'])) {
        $fee = $payment['fee_details'][0]['amount'];
    }
    
    addInvoicePayment(
        $invoiceId,
        $payment['id'],
        $amount,
        $fee,
        $gateway
    );
    logTransaction($gateway, $payment, "Approved");
    
     echo json_encode([
        'success' => true
    ]);
    exit;
}else{
    logTransaction($gateway, $payment, "Recusado");
    
    echo json_encode([
        'success' => false,
        'message' => $payment['status_detail'] ?? 'Pagamento recusado'
    ]);
    exit;
}

