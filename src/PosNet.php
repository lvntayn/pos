<?php

namespace Mews\Pos;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Mews\Pos\Exceptions\UnsupportedPaymentModelException;
use Mews\Pos\Exceptions\UnsupportedTransactionTypeException;
use SimpleXMLElement;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class PosNet
 * @package Mews\Pos
 */
class PosNet implements PosInterface
{
    use PosHelpersTrait;

    /**
     * API URL
     *
     * @var string
     */
    public $url;

    /**
     * 3D Pay Gateway URL
     *
     * @var string
     */
    public $gateway;

    /**
     * Response Codes
     *
     * @var array
     */
    public $codes = [
        '0'     => 'declined',
        '1'     => 'approved',
        '2'     => 'declined',
        '00'    => 'approved',
        '0001'  => 'bank_call',
        '0005'  => 'reject',
        '0007'  => 'bank_call',
        '0012'  => 'reject',
        '0014'  => 'reject',
        '0030'  => 'bank_call',
        '0041'  => 'reject',
        '0043'  => 'reject',
        '0051'  => 'reject',
        '0053'  => 'bank_call',
        '0054'  => 'reject',
        '0057'  => 'reject',
        '0058'  => 'reject',
        '0062'  => 'reject',
        '0065'  => 'reject',
        '0091'  => 'bank_call',
        '0123'  => 'transaction_not_found',
        '0444'  => 'bank_call',
    ];

    /**
     * Transaction Types
     *
     * @var array
     */
    public $types = [
        'pay'   => 'Sale',
        'pre'   => 'Auth',
        'post'  => 'Capt',
    ];

    /**
     * Currencies
     *
     * @var array
     */
    public $currencies = [];

    /**
     * Fixed Currencies
     * @var array
     */
    protected $_currencies = [
        'TRY'   => 'TL',
        'USD'   => 'US',
        'EUR'   => 'EU',
        'GBP'   => 'GB',
        'JPY'   => 'JP',
        'RUB'   => 'RU',
    ];

    /**
     * Transaction Type
     *
     * @var string
     */
    public $type;

    /**
     * API Account
     *
     * @var array
     */
    protected $account = [];

    /**
     * Order Details
     *
     * @var array
     */
    protected $order = [];

    /**
     * Credit Card
     *
     * @var object
     */
    protected $card;

    /**
     * Request
     *
     * @var Request
     */
    protected $request;

    /**
     * Response Raw Data
     *
     * @var object
     */
    protected $data;

    /**
     * Processed Response Data
     *
     * @var mixed
     */
    public $response;

    /**
     * Configuration
     *
     * @var array
     */
    protected $config = [];

    /**
     * @var PosNetCrypt|null
     */
    public $crypt;

    /**
     * PosNet constructor.
     *
     * @param array $config
     * @param array $account
     * @param array $currencies
     */
    public function __construct($config, $account, array $currencies)
    {
    
        $request = Request::createFromGlobals();
        $this->request = $request->request;
       
        $this->crypt = function_exists('mcrypt_encrypt') ?
            new PosNetCrypt :
            null;
         

        $this->config = $config;
        $this->account = $account;
        $this->currencies = $currencies;

        $this->url = isset($this->config['urls'][$this->account->env]) ?
            $this->config['urls'][$this->account->env] :
            $this->config['urls']['production'];

        $this->gateway = isset($this->config['urls']['gateway'][$this->account->env]) ?
            $this->config['urls']['gateway'][$this->account->env] :
            $this->config['urls']['gateway']['production'];

        

        return $this;
    }

    /**
     * Get currency
     *
     * @return int|string
     */
    protected function getCurrency() {
        $search = array_search($this->order->currency, $this->currencies);
        $currency = $this->order->currency;
        if ($search) {
            $currency = $this->_currencies[$search];
        }

        return $currency;
    }

    /**
     * Get amount
     *
     * @return int
     */
    protected function getAmount()
    {
        return (int) str_replace('.', '', number_format($this->order->amount, 2, '.', ''));
    }

    /**
     * Get orderId
     *
     * @param int $pad_length
     * @return string
     */
    protected function getOrderId($pad_length = 24)
    {
        return (string) str_pad($this->order->id, $pad_length, '0', STR_PAD_LEFT);
    }

    /**
     * Get Installment
     *
     * @return int|string
     */
    protected function getInstallment()
    {
        $installment = (int) $this->order->installment;
        if (!$this->order->installment) {
            $installment = '00';
        }

        return $installment;
    }

    /**
     * Create Regular Payment XML
     *
     * @return string
     */
    protected function createRegularPaymentXML()
    {
        $transaction = strtolower($this->type);

        $nodes = [
            'posnetRequest'   => [
                'mid'               => $this->account->client_id,
                'tid'               => $this->account->terminal_id,
                'tranDateRequired'  => '1',
                $transaction  => [
                    'orderID'       => $this->getOrderId(),
                    'installment'   => $this->getInstallment(),
                    'amount'        => $this->getAmount(),
                    'currencyCode'  => $this->getCurrency(),
                    'ccno'          => $this->card->number,
                    'expDate'       => $this->card->year . $this->card->month,
                    'cvc'           => $this->card->cvv,
                ],
            ]
        ];

        return $this->createXML($nodes, $encoding = 'ISO-8859-9');
    }

    /**
     * Create Regular Payment Post XML
     *
     * @return string
     */
    protected function createRegularPostXML()
    {
        $nodes = [
            'posnetRequest'   => [
                'mid'               => $this->account->client_id,
                'tid'               => $this->account->terminal_id,
                'tranDateRequired'  => '1',
                'capt'  => [
                    'hostLogKey'    => $this->order->host_ref_num,
                    'amount'        => $this->getAmount(),
                    'currencyCode'  => $this->getCurrency(),
                    'installment'   => $this->order->installment ? $this->getInstallment() : null
                ],
            ]
        ];

        return $this->createXML($nodes);
    }

    public function hashString($originalString){
        return base64_encode(hash('sha256',($originalString),true));
    } 

    public function getMacData(){
        //var_dump($this->account->store_key . ";" . $this->account->terminal_id);
        $firstHash =  $this->hashString($this->account->store_key . ";" . $this->account->terminal_id);
        //var_dump($this->getOrderId(20) . ";" . $this->getAmount() . ";" . $this->getCurrency() . ";" . $this->account->client_id . ";" . $firstHash);

        $MAC = $this->hashString($this->getOrderId(20) . ";" . $this->getAmount() . ";" . $this->getCurrency() . ";" . $this->account->client_id . ";" . $firstHash);
        return str_replace('+', '%2B', utf8_encode($MAC));
    }
 
    /**
     * Create 3D Payment XML
     * @return string
     */
    protected function create3DPaymentXML()
    {

       
        $nodes = [
            'posnetRequest' => [
                'mid'   => $this->account->client_id,
                'tid'   => $this->account->terminal_id,
                'oosResolveMerchantData'    => [
                    'bankData'      => $this->request->get('BankPacket'),
                    'merchantData'  => $this->request->get('MerchantPacket'),
                    'sign'          => $this->request->get('Sign'),
                    'mac'           => $this->getMacData(),
                ],
            ]
        ];
        //print_r($nodes);

        return $this->createXML($nodes, 'ISO-8859-9');
    }

    /**
     * Get ProcReturnCode
     *
     * @return string|null
     */
    protected function getProcReturnCode()
    {
        return (string) $this->data->approved == '1' ? '00'  : $this->data->approved;
    }

    /**
     * Get Status Detail Text
     *
     * @return string|null
     */
    protected function getStatusDetail()
    {
        $proc_return_code = $this->getProcReturnCode();

        return isset($this->codes[$proc_return_code]) ? (string) $this->codes[$proc_return_code] : null;
    }

    /**
     * Get card exp date
     *
     * @return string
     */
    protected function getCardExpDate()
    {
        $year = (string) str_pad($this->card->year, 2, '0', STR_PAD_LEFT);
        $month = (string) str_pad($this->card->month, 2, '0', STR_PAD_LEFT);

        return (string) $year . $month;
    }

    /**
     * Get OOS transaction data
     *
     * @return object
     * @throws GuzzleException
     */
    public function getOosTransactionData()
    {
        $name = isset($this->card->name) ? $this->card->name : null;
        if (!$name) {
            $name = isset($this->order->name) ? $this->order->name : null;
        }

        $contents = $this->createXML([
            'posnetRequest' => [
                'mid'   => $this->account->client_id,
                'tid'   => $this->account->terminal_id,
                'oosRequestData' => [
                    'posnetid'          => $this->account->posnet_id,
                    'ccno'              => $this->card->number,
                    'expDate'           => $this->getCardExpDate(),
                    'cvc'               => $this->card->cvv,
                    'amount'            => $this->getAmount(),
                    'currencyCode'      => $this->getCurrency(),
                    'installment'       => $this->getInstallment(),
                    'XID'               => $this->getOrderId(20),
                    'cardHolderName'    => $name,
                    'tranType'          => $this->type,
                ]
            ],
        ]);


        $this->send($contents);

        return $this->data;
    }

    /**
     * Regular Payment
     *
     * @return $this
     * @throws GuzzleException
     */
    public function makeRegularPayment()
    {
        $contents = '';
        if (in_array($this->order->transaction, ['pay', 'pre'])) {
            $contents = $this->createRegularPaymentXML();
        } elseif ($this->order->transaction == 'post') {
            $contents = $this->createRegularPostXML();
        }

        $this->send($contents);

        $status = 'declined';
        $code = '1';
        $proc_return_code = '01';
        $obj = isset($this->data) ? $this->data : null;
        $error_code = isset($obj->respCode) ? $obj->respCode : null;
        $error_message = isset($obj->respText) ? $obj->respText : null;

        if ($this->getProcReturnCode() == '00' && $obj && !$error_code) {
            $status = 'approved';
            $code = isset($obj->approved) ? $obj->approved : null;
            $proc_return_code = $this->getProcReturnCode();
        }

        $this->response = (object) [
            'id'                => isset($obj->authCode) ? $this->printData($obj->authCode) : null,
            'order_id'          => $this->order->id,
            'fixed_order_id'    => $this->getOrderId(),
            'group_id'          => isset($obj->groupID) ? $this->printData($obj->Order->groupID) : null,
            'trans_id'          => isset($obj->authCode) ? $this->printData($obj->authCode) : null,
            'response'          => $this->getStatusDetail(),
            'transaction_type'  => $this->type,
            'transaction'       => $this->order->transaction,
            'auth_code'         => isset($obj->authCode) ? $this->printData($obj->authCode) : null,
            'host_ref_num'      => isset($obj->hostlogkey) ? $this->printData($obj->hostlogkey) : null,
            'ret_ref_num'       => isset($obj->hostlogkey) ? $this->printData($obj->hostlogkey) : null,
            'proc_return_code'  => $proc_return_code,
            'code'              => $code,
            'status'            => $status,
            'status_detail'     => $this->getStatusDetail(),
            'error_code'        => $error_code,
            'error_message'     => $error_message,
            'campaign_url'      => null,
            'extra'             => null,
            'all'               => $this->data,
            'original'          => $this->data,
        ];

        return $this;
    }

    /**
     * Get host name
     *
     * @param $url
     * @return string
     */
    public function getHostName($url)
    {
        $parse = parse_url($url);

        return $parse['host'];
    }

    /**
     * Check 3D Hash
     *
     * @return bool
     */
    protected function check3DHash()
    {
        $check = false;

        if ($this->crypt instanceof PosNetCrypt) {
            $decrypted_data = $this->crypt->decrypt($this->request->get('MerchantPacket'), $this->account->store_key);
            $this->crypt->deInit();
            $decrypted_data_array = explode(';', $decrypted_data);
            $original_data = array_map('strval', [
                $this->account->client_id,
                $this->account->terminal_id,
                $this->getAmount(),
                $this->getInstallment(),
                $this->getOrderId(20),
             //   $this->getHostName($this->url),
            ]);
            $decrypted_data_list = array_map('strval', [
                $decrypted_data_array[0],
                $decrypted_data_array[1],
                $decrypted_data_array[2],
                $decrypted_data_array[3],
                $decrypted_data_array[4],
               // $this->getHostName($decrypted_data_array[7]),
            ]); 
            
            if ($original_data == $decrypted_data_list) {
                $check = true;
            }
        } else {
            $check = false;
        }

        return $check;
    }

    /**
     * Make 3D Payment
     *
     * @return $this
     * @throws GuzzleException
     */
    public function make3DPayment()
    {
      
        $status = 'declined';
        $transaction_security = 'MPI fallback';


        if ($this->check3DHash()) {
            $contents = $this->create3DPaymentXML();
            $this->send($contents);
        }
       
        //print_r($this->data);
       
        if ($this->getProcReturnCode() == '00' && !empty($this->data->oosResolveMerchantDataResponse)) {
            if ($this->data->oosResolveMerchantDataResponse->mdStatus == '1') {
                $transaction_security = 'Full 3D Secure';
                $status = 'approved';
            } elseif (in_array($this->data->oosResolveMerchantDataResponse->mdStatus, [2, 3, 4])) {
                $transaction_security = 'Half 3D Secure';
                $status = 'approved';
            }

            $nodes = [
                'posnetRequest'   => [
                    'mid'   => $this->account->client_id,
                    'tid'   => $this->account->terminal_id,
                    'oosTranData' => [
                        'bankData'      => $this->request->get('BankPacket'),
                        'merchantData'  => $this->request->get('MerchantPacket'),
                        'sign'          => $this->request->get('Sign'),
                        'wpAmount'      => $this->data->oosResolveMerchantDataResponse->amount,
                        'mac'           => $this->getMacData(),
                    ],
                ]
            ];
            

           
            $contents = $this->createXML($nodes, $encoding = 'ISO-8859-9');
            $this->send($contents);
        }

        //print_r($this->data);

   
        $this->response = (object) $this->data;

        $this->response = (object) [
            'id'                    => isset($this->data->AuthCode) ? $this->printData($this->data->AuthCode) : null,
            'order_id'              => isset($this->order->id) ? $this->printData($this->order->id) : null,
            'group_id'              => isset($this->data->GroupId) ? $this->printData($this->data->GroupId) : null,
            'trans_id'              => isset($this->data->TransId) ? $this->printData($this->data->TransId) : null,
            'response'              => isset($this->data->Response) ? $this->printData($this->data->Response) : null,
            'transaction_type'      => $this->type,
            'transaction'           => $this->order->transaction,
            'transaction_security'  => $transaction_security,
            'auth_code'             => isset($this->data->AuthCode) ? $this->printData($this->data->AuthCode) : null,
            'host_ref_num'          => isset($this->data->HostRefNum) ? $this->printData($this->data->HostRefNum) : null,
            'proc_return_code'      => isset($this->data->ProcReturnCode) ? $this->printData($this->data->ProcReturnCode) : null,
            'code'                  => isset($this->data->ProcReturnCode) ? $this->printData($this->data->ProcReturnCode) : null,
            'status'                => $status,
            'status_detail'         => $this->getStatusDetail(),
            'error_code'            => isset($this->data->Extra->ERRORCODE) ? $this->printData($this->data->Extra->ERRORCODE) : null,
            'error_message'         => isset($this->data->Extra->ERRORCODE) ? $this->printData($this->data->ErrMsg) : null,
            'md_status'             => isset($this->data->oosResolveMerchantDataResponse->mdStatus) ? $this->printData($this->data->oosResolveMerchantDataResponse->mdStatus) : null,
            'hash'                  => [
                'merchant_packet'    => $this->request->get('MerchantPacket'),
                'bank_packet'        => $this->request->get('BankPacket'),
                'sign'               => $this->request->get('Sign'),
            ],
            'xid'                   => isset($this->data->oosResolveMerchantDataResponse->xid) ? $this->data->oosResolveMerchantDataResponse->xid : null,
            'md_error_message'      => isset($this->data->oosResolveMerchantDataResponse->mdErrorMessage) ? $this->data->oosResolveMerchantDataResponse->mdErrorMessage : null,
            'campaign_url'          => null,
            'all'                   => $this->data,
        ];

        if (empty($this->response->md_error_message)){
            if (!empty($this->response->all->respText)){
                $this->response->md_error_message = $this->response->all->respText.' '.$this->response->all->respCode.' '.$this->getOrderId(20);
            }
        }

        return $this;
    }

    /**
     * Make 3D Pay Payment
     *
     * @return $this
     */
    public function make3DPayPayment()
    {
        $this->make3DPayPayment();

        return $this;
    }

    /**
     * Get 3d Form Data
     *
     * @return array
     * @throws GuzzleException
     */
    public function get3DFormData()
    {
        $inputs = [];
        $data = null;

        if ($this->card && $this->order) {
            $data = $this->getOosTransactionData();

            if ($data->approved == 0){
                return $data->respText;
            }

            $inputs = [
                'posnetData'         => $data->oosRequestDataResponse->data1,
                'posnetData2'        => $data->oosRequestDataResponse->data2,
                'mid'                => $this->account->client_id,
                'posnetID'           => $this->account->posnet_id,
                'digest'             => $data->oosRequestDataResponse->sign,
                'vftCode'            => isset($this->account->promotion_code) ? $this->account->promotion_code : null,
                'merchantReturnURL'  => $this->order->success_url,
                'url'                => '',
                'lang'               => $this->order->lang,
            ];
        }

        return [
            'gateway'       => $this->gateway,
            'success_url'   => $this->order->success_url,
            'fail_url'      => $this->order->fail_url,
            'rand'          => $data->oosRequestDataResponse->sign,
            'hash'          => $data->oosRequestDataResponse->data1,
            'inputs'        => $inputs,
        ];
    }

    /**
     * Send contents to WebService
     *
     * @param $contents
     * @return $this
     * @throws GuzzleException
     */
    public function send($contents)
    {
        $client = new Client();

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];

        $response = $client->request('POST', $this->url, [
            'headers'   => $headers,
            'body'      => "xmldata=" . $contents,
        ]);

        $xml = new SimpleXMLElement($response->getBody());

        $this->data = (object) json_decode(json_encode($xml));

        return $this;
    }

    /**
     * Prepare Order
     *
     * @param object $order
     * @param object null $card
     * @return mixed
     * @throws UnsupportedTransactionTypeException
     */
    public function prepare($order, $card = null)
    {
        $this->type = $this->types['pay'];
        if (isset($order->transaction)) {
            if (array_key_exists($order->transaction, $this->types)) {
                $this->type = $this->types[$order->transaction];
            } else {
                throw new UnsupportedTransactionTypeException('Unsupported transaction type!');
            }
        }

        $this->order = $order;
        $this->card = $card;
    }

    /**
     * Make Payment
     *
     * @param object $card
     * @return mixed
     * @throws UnsupportedPaymentModelException
     * @throws GuzzleException
     */
    public function payment($card)
    {
        $this->card = $card;

        $model = 'regular';
        if (isset($this->account->model) && $this->account->model) {
            $model = $this->account->model;
        }

        if ($model == 'regular') {
            $this->makeRegularPayment();
        } elseif ($model == '3d') {
            $this->make3DPayment();
        } elseif ($model == '3d_pay') {
            $this->make3DPayPayment();
        } else {
            throw new UnsupportedPaymentModelException();
        }

        return $this;
    }

    /**
     * Refund or Cancel Order
     *
     * @param array $meta
     * @param string $type
     * @return $this
     * @throws GuzzleException
     */
    protected function refundOrCancel(array $meta, $type = 'cancel')
    {
        $this->order = (object) [
            'id'            => $meta['order_id'],
            'host_ref_num'  => isset($meta['host_ref_num']) ? $meta['host_ref_num'] : null,
            'auth_code'     => isset($meta['auth_code']) ? $meta['auth_code'] : null,
            'amount'        => isset($meta['amount']) ? $meta['amount'] : null,
            'currency'      => isset($meta['currency']) ? $this->_currencies[$meta['currency']] : null,
        ];

        $nodes = [
            'mid'               => $this->account->client_id,
            'tid'               => $this->account->terminal_id,
            'tranDateRequired'  => '1',
        ];

        if ($type == 'refund') {
            $return = [
                'amount'        => $this->getAmount(),
                'currencyCode'  => $this->getCurrency(),
                'orderID'       => $this->getOrderId(),
            ];

            if ($this->order->host_ref_num) {
                $return['hostLogKey'] = $this->order->host_ref_num;
                unset($return['orderID']);
            }

            $append = [
                'return' => $return,
            ];
        } else {
            $reverse = [
                'transaction'   => 'pointUsage',
                'orderID'       => $this->getOrderId(),
                'authCode'      => $this->order->auth_code,
            ];

            if ($this->order->host_ref_num) {
                $reverse = [
                    'transaction'   => 'pointUsage',
                    'hostLogKey'    => $this->order->host_ref_num,
                    'authCode'      => $this->order->auth_code,
                ];
            }

            $append = [
                'reverse' => $reverse,
            ];
        }

        $nodes = array_merge($nodes, $append);

        $xml = $this->createXML([
            'posnetRequest' => $nodes
        ]);

        $this->send($xml);

        $status = 'declined';
        $code = '1';
        $proc_return_code = '01';
        $obj = isset($this->data) ? $this->data : null;
        $error_code = isset($obj->respCode) ? $obj->respCode : null;
        $error_message = null;

        if ($this->getProcReturnCode() == '00' && $obj && !$error_code) {
            $status = 'approved';
            $code = isset($obj->approved) ? $obj->approved : null;
            $proc_return_code = $this->getProcReturnCode();
        }

        $error_message = isset($obj->respText) ? $obj->respText : null;

        $transaction = null;
        $transaction_type = null;
        $state = isset($obj->state) ? $obj->state : null;
        if ($state == 'Sale') {
            $transaction = 'pay';
            $transaction_type = $this->types[$transaction];
        } elseif ($state == 'Authorization') {
            $transaction = 'pre';
            $transaction_type = $this->types[$transaction];
        } elseif ($state == 'Capture') {
            $transaction = 'post';
            $transaction_type = $this->types[$transaction];
        }

        $data = [
            'id'                => isset($obj->transaction->authCode) ? $this->printData($obj->transaction->authCode) : null,
            'order_id'          => isset($this->order->id) ? $this->printData($this->order->id) : null,
            'fixed_order_id'    => isset($obj->transaction->orderID) ? $this->printData($obj->transaction->orderID) : null,
            'group_id'          => isset($obj->transaction->groupID) ? $this->printData($obj->transaction->groupID) : null,
            'trans_id'          => isset($obj->transaction->authCode) ? $this->printData($obj->transaction->authCode) : null,
            'response'          => $this->getStatusDetail(),
            'auth_code'         => isset($obj->transaction->authCode) ? $this->printData($obj->transaction->authCode) : null,
            'host_ref_num'      => isset($obj->transaction->authCode) ? $this->printData($obj->transaction->authCode) : null,
            'ret_ref_num'       => isset($obj->transaction->authCode) ? $this->printData($obj->transaction->authCode) : null,
            'transaction'       => $transaction,
            'transaction_type'  => $transaction_type,
            'state'             => $state,
            'date'              => isset($obj->transaction->tranDate) ? $this->printData($obj->transaction->tranDate) : null,
            'proc_return_code'  => $proc_return_code,
            'code'              => $code,
            'status'            => $status,
            'status_detail'     => $this->getStatusDetail(),
            'error_code'        => $error_code,
            'error_message'     => $error_message,
            'extra'             => null,
            'all'               => $this->data,
            'original'          => $this->data,
        ];

        $this->response = (object) $data;

        return $this;
    }

    /**
     * Refund Order
     *
     * @param $meta
     * @return $this
     * @throws GuzzleException
     */
    public function refund(array $meta)
    {
        return $this->refundOrCancel($meta, 'refund');
    }

    /**
     * Cancel Order
     *
     * @param array $meta
     * @return $this
     * @throws GuzzleException
     */
    public function cancel(array $meta)
    {
        return $this->refundOrCancel($meta, 'cancel');
    }

    /**
     * Order Status
     *
     * @param array $meta
     * @param bool $history
     * @return $this
     * @throws GuzzleException
     */
    public function status(array $meta, $history = false)
    {
        $this->order = (object) [
            'id' => isset($meta['order_id']) ? $meta['order_id'] : null,
        ];

        $xml = $this->createXML([
            'posnetRequest'   => [
                'mid'   => $this->account->client_id,
                'tid'   => $this->account->terminal_id,
                'agreement' => [
                    'orderID'   => $this->getOrderId(),
                ],
            ]
        ]);

        $this->send($xml);

        $status = 'declined';
        $code = '1';
        $proc_return_code = '01';
        $obj = isset($this->data->transactions) ? $this->data->transactions : null;
        $error_code = isset($this->data->respCode) ? $this->data->respCode : null;
        $error_message = null;

        if ($this->getProcReturnCode() == '00' && $obj && !$error_code) {
            $status = 'approved';
            $code = isset($obj->approved) ? $obj->approved : null;
            $proc_return_code = $this->getProcReturnCode();
        }

        $error_message = isset($this->data->respText) ? $this->data->respText : null;

        $transaction = null;
        $transaction_type = null;

        $state = null;
        $auth_code = null;
        $refunds = [];
        if (isset($this->data->transactions->transaction)) {
            $state = isset($this->data->transactions->transaction->state) ?
                $this->data->transactions->transaction->state :
                null;

            $auth_code = isset($obj->transaction->authCode) ? $this->printData($obj->transaction->authCode) : null;

            if (is_array($this->data->transactions->transaction) && count($this->data->transactions->transaction)) {
                $state = $this->data->transactions->transaction[0]->state;
                $auth_code = $this->data->transactions->transaction[0]->authCode;

                if (count($this->data->transactions->transaction) > 1 && $history) {
                    $_currencies = array_flip($this->_currencies);

                    foreach ($this->data->transactions->transaction as $key => $_transaction) {
                        if ($key > 0) {
                            $currency = isset($_currencies[$_transaction->currencyCode]) ?
                                (string) $_currencies[$_transaction->currencyCode] :
                                $_transaction->currencyCode;
                            $refunds[] = [
                                'amount'    => (double) $_transaction->amount,
                                'currency'  => $currency,
                                'auth_code' => $_transaction->authCode,
                                'date'      => $_transaction->tranDate,
                            ];
                        }
                    }
                }
            }
        }

        if ($state == 'Sale') {
            $transaction = 'pay';
            $state = $transaction;
            $transaction_type = $this->types[$transaction];
        } elseif ($state == 'Authorization') {
            $transaction = 'pre';
            $state = $transaction;
            $transaction_type = $this->types[$transaction];
        } elseif ($state == 'Capture') {
            $transaction = 'post';
            $state = $transaction;
            $transaction_type = $this->types[$transaction];
        } elseif ($state == 'Bonus_Reverse') {
            $state = 'cancel';
        } else {
            $state = 'mixed';
        }

        $data = [
            'id'                => $auth_code,
            'order_id'          => isset($this->order->id) ? $this->printData($this->order->id) : null,
            'fixed_order_id'    => $this->getOrderId(),
            'group_id'          => isset($obj->transaction->groupID) ? $this->printData($obj->transaction->groupID) : null,
            'trans_id'          => $auth_code,
            'response'          => $this->getStatusDetail(),
            'auth_code'         => $auth_code,
            'host_ref_num'      => null,
            'ret_ref_num'       => null,
            'transaction'       => $transaction,
            'transaction_type'  => $transaction_type,
            'state'             => $state,
            'date'              => isset($obj->transaction->tranDate) ? $this->printData($obj->transaction->tranDate) : null,
            'refunds'           => $refunds,
            'proc_return_code'  => $proc_return_code,
            'code'              => $code,
            'status'            => $status,
            'status_detail'     => $this->getStatusDetail(),
            'error_code'        => $error_code,
            'error_message'     => $error_message,
            'extra'             => null,
            'all'               => $this->data,
            'original'          => $this->data,
        ];

        if (!$history) {
            unset($data['refunds']);
        }

        $this->response = (object) $data;

        return $this;
    }

    /**
     * Order History
     *
     * @param array $meta
     * @return $this
     * @throws GuzzleException
     */
    public function history(array $meta)
    {
        return $this->status($meta, true);
    }
}
