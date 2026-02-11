<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("Acesso negado");
}

define('PAYMENT_METHOD_MP_CARTAO', 'dfcartaomercadopago');

function dfcartaomercadopago_MetaData() {
    return [
        'DisplayName' => 'Cart達o Mercado Pago',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
    ];
}

function dfcartaomercadopago_config() {
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Cartao Mercado Pago',
        ],
        'AccessTokenProducao' => [
            'FriendlyName' => 'Access Token',
            'Type' => 'text',
        ],
        'PublicKeyProducao' => [
            'FriendlyName' => 'Public Key',
            'Type' => 'text',
        ],
    ];
}

function dfcartaomercadopago_link($params)
{
    $amount = number_format($params['amount'], 2, '.', '');
    $invoiceId = $params['invoiceid'];

    $publicKey = trim($params['PublicKeyProducao']);
    $email = json_encode($params['clientdetails']['email']);
    $first = json_encode($params['clientdetails']['firstname']);
    $last = json_encode($params['clientdetails']['lastname']);

    $documento = preg_replace('/\D/', '', $params['clientdetails']['customfields1'] ?? '');
    $tipoDocumento = strlen($documento) > 11 ? 'CNPJ' : 'CPF';

    return '
<div id="cardPaymentBrick_container"></div>

<script src="https://sdk.mercadopago.com/js/v2"></script>
<script>
const mp = new MercadoPago("'.$publicKey.'",{locale:"pt-BR"});
const bricksBuilder = mp.bricks();

const renderBrick = async () => {
  const settings = {
    initialization: {
      amount: '.$amount.',
      payer: {
        email: '.$email.',
        firstName: '.$first.',
        lastName: '.$last.',
        identification: {
          type: "'.$tipoDocumento.'",
          number: "'.$documento.'"
        }
      }
    },
    callbacks: {
    onReady: () => {
    },
      onError: (error) => {
        // callback chamado para todos os casos de erro do Brick 
        console.error(error);
      },
      onSubmit: (formData, additionalData) => {
        return new Promise((resolve,reject)=>{
          fetch("modules/gateways/callback/dfcartaomercadopago-callback.php",{
            method:"POST",
            headers:{"Content-Type":"application/json"},
            body: JSON.stringify({
              invoiceid:"'.$invoiceId.'",
              formData,
              additionalData
            })
          })
          .then(r=>r.json())
          .then(response => {
            if (!response.success) {
                alert("Pagamento recusado: " + response.message);
                reject();
                return;
            }
        
            resolve();
            location.reload();
        })
          .catch(()=>reject());
        });
      }
    }
  };

  await bricksBuilder.create(
    "cardPayment",
    "cardPaymentBrick_container",
    settings
  );
};

renderBrick();
</script>';
}

function dfcartaomercadopago_capture($params)
{
    $accessToken = $params['AccessTokenProducao'];

    if (empty($params['clientdetails']['gatewayid'])) {
        return ['status'=>'error'];
    }

    list($customerId, $cardId) =
        explode('|', $params['clientdetails']['gatewayid']);

    $paymentData = [
        "transaction_amount" => (float)$params['amount'],
        "description" => "Invoice #".$params['invoiceid'],
        "installments" => 1,
        "payer" => [
            "type"=>"customer",
            "id"=>$customerId
        ],
        "card_id"=>$cardId
    ];

    $ch = curl_init("https://api.mercadopago.com/v1/payments");

    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($paymentData),
        CURLOPT_HTTPHEADER=>[
            "Authorization: Bearer ".$accessToken,
            "Content-Type: application/json"
        ]
    ]);

    $result=json_decode(curl_exec($ch),true);
    curl_close($ch);

    if ($result['status']=='approved') {
        return [
            'status'=>'success',
            'transid'=>$result['id']
        ];
    }

    return ['status'=>'declined'];
}

function dfcartaomercadopago_refund($params)
{
    $accessToken = $params['AccessTokenProducao'];

    $paymentId = $params['transid']; // ID pagamento MP
    $amount = (float) $params['amount'];

    if (!$paymentId) {
        return [
            'status' => 'error',
            'rawdata' => 'Transação não encontrada'
        ];
    }

    $payload = [
        "amount" => $amount
    ];

    $ch = curl_init(
        "https://api.mercadopago.com/v1/payments/$paymentId/refunds"
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer ".$accessToken,
            "Content-Type: application/json",
            "X-Idempotency-Key: refund-".$paymentId
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    logTransaction('dfcartaomercadopago', $result, "Refund");

    if (isset($result['id'])) {
        return [
            'status' => 'success',
            'transid' => $result['id'],
            'rawdata' => $result
        ];
    }

    return [
        'status' => 'error',
        'rawdata' => $result
    ];
}

