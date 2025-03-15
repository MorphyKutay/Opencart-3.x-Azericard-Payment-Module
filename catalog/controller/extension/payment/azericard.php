<?php
class ControllerExtensionPaymentAzericard extends Controller {

    /**
 * HTTP POST request for calling the external URL
 */
private function get_web_page($url, $data) {
    // Initialize cURL session
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url); // The URL to send the request to
    curl_setopt($ch, CURLOPT_POST, 1); // Set the request type to POST
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data); // Set the POST data
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // Return the response as a string
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // Follow redirects if any
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); // Disable SSL verification (useful for test environments)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // Disable SSL peer verification (useful for test environments)

    // Execute the request and store the result
    $response = curl_exec($ch);

    // Check for errors
    if(curl_errno($ch)) {
        $this->log->write('Curl error: ' . curl_error($ch));
        $response = false;
    }

    // Get response info (status code, etc.)
    $response_info = curl_getinfo($ch);

    // Close the cURL session
    curl_close($ch);

    // Return the result
    return [
        'content' => $response,
        'status_code' => $response_info['http_code']
    ];
}

    
/**
 * Prepares and sets up the payment form for AzeriCard.
 *
 * This method loads the necessary language and model files, retrieves the order information,
 * and constructs the required parameters for the payment form. It determines the action URL
 * based on the test mode configuration, calculates the P_SIGN for validation, and sets up
 * the data array for the view. The method then returns the payment form view with the
 * necessary parameters for processing the payment.
 */

    public function index() {
        $this->load->language('extension/payment/azericard');

        // Sipariş bilgilerini al
        $this->load->model('checkout/order');
        $order_id = $this->session->data['order_id'];
        $order_info = $this->model_checkout_order->getOrder($order_id);

        // Konfigürasyon ayarlarını yükle
        $terminal   = $this->config->get('payment_azericard_terminal');
        $currency   = $this->config->get('payment_azericard_currency') ?: 'AZN';

        $test_mode = $this->config->get('payment_azericard_test_mode');

        // Eğer ayar kaydedilmemişse, varsayılan olarak '0' kabul et
        if ($test_mode === null || $test_mode === '') {
            $test_mode = '0';
        }
        
        // Doğrudan if-else kullanarak kontrol et
        if ($test_mode === '0') {
            $action_url = 'https://mpi.3dsecure.az/cgi-bin/cgi_link';  // Live URL
        } else {
            $action_url = 'https://testmpi.3dsecure.az/cgi-bin/cgi_link';  // Test URL
        }
        

        // Zorunlu ödeme parametreleri
        $params = array(
            'AMOUNT'     => number_format($order_info['total'], 2, '.', ''),
            'CURRENCY'   => $currency,
            'ORDER'      => str_pad((string)$order_id, 6, '0', STR_PAD_LEFT), // 6 karakterli yapma
            'DESC'       => 'Sipariş #' . $order_id,
            'MERCH_NAME' => $this->config->get('config_name'),
            'MERCH_URL'  => $this->config->get('payment_azericard_url'),
            'MERCH_GMT'  => '+4',
            'TERMINAL'   => $terminal,
            'EMAIL'      => $order_info['email'],
            'TRTYPE'     => '0',
            'COUNTRY'    => 'AZ',
            'TIMESTAMP'  => date("YmdHis"),
            'NONCE'      => bin2hex(random_bytes(16)), // 16 baytlık rastgele HEX nonce
            'BACKREF'    => $this->url->link('extension/payment/azericard/callback', '', true),
            'LANG'       => $this->session->data['language'],
            'NAME'       => $order_info['payment_firstname'] . ' ' . $order_info['payment_lastname'],
            'M_INFO'     => base64_encode(json_encode([
                "browserScreenHeight" => "1920",
                "browserScreenWidth"  => "1080",
                "browserTZ"           => "0",
                "mobilePhone"         => ["cc" => "994", "subscriber" => "5077777777"]
            ])),
        );


        // P_SIGN hesapla ve ekle
        $params['P_SIGN'] = $this->generatePSign($params);

        // Görünüm için değişkenleri ayarla
        $data['action'] = $action_url;
        foreach ($params as $key => $value) {
            $data[$key] = $value;
        }
        $data['button_confirm'] = $this->language->get('button_confirm');

        return $this->load->view('extension/payment/azericard', $data);
    }

    /**
     * P_SIGN (MAC) hesaplama
     */
    private function generatePSign($params) {
        // Özel anahtarı yükle
        $privateKey = file_get_contents(DIR_STORAGE . "/vendor/merchant_private_key.pem");      
        
        // MAC oluştur
        $macSourceString = $this->buildMacSource($params);

        // SHA256 ile OpenSSL imzalama
        $P_SIGN = '';
        openssl_sign($macSourceString, $P_SIGN,$privateKey, OPENSSL_ALGO_SHA256);

        // Bin2Hex dönüşümü
        return bin2hex($P_SIGN);
    }

    /**
     * MAC oluşturma (Sıralı ve uzunluk bilgisi eklenmiş)
     */
    private function buildMacSource($params) {
        $trtype = $params['TRTYPE'] ?? '0';
        $fields = [];
    
        switch ($trtype) {
            case '0': // Pre-authorization
            case '1': // Authorization
                $fields = ['AMOUNT', 'CURRENCY', 'TERMINAL', 'TRTYPE', 'TIMESTAMP', 'NONCE', 'MERCH_URL'];
                break;
            case '21': // Completion
                $fields = ['AMOUNT', 'CURRENCY', 'TERMINAL', 'TRTYPE', 'ORDER', 'RRN', 'INT_REF'];
                break;
            case '22': // Reversal (İade)
                $fields = ['AMOUNT', 'CURRENCY', 'TERMINAL', 'TRTYPE', 'ORDER', 'RRN', 'INT_REF'];
                break;
            default:
                $this->log->write("Unknown TRTYPE: $trtype");
                return '';
        }
    
        $source = '';
        foreach ($fields as $field) {
            if (isset($params[$field])) {
                $value = (string)$params[$field];
                $source .= strlen($value) . $value;
            }
        }
        return $source;
    }

    /**
     * Callback: AzeriCard'dan gelen cevabı doğrular
     */

    /**
     * AzeriCard payment callback
     * 
     * This method is called by AzeriCard when a payment is made.
     * It validates the required fields, checks the payment status and updates the order history accordingly.
     * 
     * Supported payment states:
     *  0 - Payment is successful
     *  1 - Duplicated order
     *  2 - Order is rejected
     *  3 - Error during processing
     *  6 - Repeated rejected order
     *  7 - Error during verification
     *  8 - Response timeout
     * 
     * If the payment is successful, it updates the order history and redirects to the checkout success page.
     * If the payment is not successful, it redirects to the checkout failure page.
     * 
     * @return void
     */
     public function callback() {
        $postData = $this->request->post;
    
        // Log the received data
        $this->log->write("Received data: " . print_r($postData, true));
    
        // Validate required fields
        $required = ['ACTION', 'ORDER', 'AMOUNT', 'CURRENCY', 'RRN', 'INT_REF'];
        foreach ($required as $field) {
            if (!isset($postData[$field])) {
                $this->log->write("Missing field: $field");
                die("Invalid response.");
            }
        }
    
        if ($postData['ACTION'] !== "0") {
            die("Payment failed.");
        }
    
        // Prepare parameters for TRTYPE=21
        $params = [
            'AMOUNT'    => $postData['AMOUNT'],
            'CURRENCY'  => $postData['CURRENCY'],
            'ORDER'     => $postData['ORDER'],
            'RRN'       => $postData['RRN'],
            'INT_REF'   => $postData['INT_REF'],
            'TERMINAL'  => $this->config->get('payment_azericard_terminal'),
            'TRTYPE'    => '21',
            'TIMESTAMP' => date("YmdHis"),
            'NONCE'     => bin2hex(random_bytes(16)),
        ];
    
        $ORDER = $postData['ORDER'];
        $params['P_SIGN'] = $this->generatePSign($params);
    
        $test_mode = $this->config->get('payment_azericard_test_mode');

        // Eğer ayar kaydedilmemişse, varsayılan olarak '0' kabul et
        if ($test_mode === null || $test_mode === '') {
            $test_mode = '0';
        }
        
        // Doğrudan if-else kullanarak kontrol et
        if ($test_mode === '0') {
            $action_url = 'https://mpi.3dsecure.az/cgi-bin/cgi_link';  // Live URL
        } else {
            $action_url = 'https://testmpi.3dsecure.az/cgi-bin/cgi_link';  // Test URL
        }
        
        $result = $this->get_web_page($action_url, http_build_query($params));
        
    
        // Log Azericard response
        $this->log->write("AzeriCard response: " . print_r($result, true));
    
        if ($result['content'] === '0') {
            //$this->log->write("(1) Loading checkout/order model...");
            $this->load->model('checkout/order');

            
            // Fix: Remove substr if ORDER is pure order_id
            $order_id = (int)$ORDER; // Doğru order_id'yi al
            
            // Log the extracted order_id
            $this->log->write("Updating order history for Order ID: " . $order_id);
            
            // Get the correct status ID
            $status_id = $this->config->get('payment_azericard_order_status_id') ?: 1; // Varsayılan olarak 1 yap
            //$this->log->write("Using status ID: " . $status_id);
            
            // Update order status
            try {
                //$this->log->write("(3) Calling addOrderHistory...");
                $this->model_checkout_order->addOrderHistory(
                    $order_id,
                    $status_id
                );

            } catch (Exception $e) {
                $this->log->write("(ERROR) Exception: " . $e->getMessage(). "/ ORDER ID : ".$order_id);
            }
            //$this->log->write("Order history updated."); // Bu satıra ulaşılıyor mu?
            

        } else if($result['content'] === '1'){
            $order_id = (int)$ORDER;
            $this->log->write("DUPLICATED ORDER " . $result['content']."ORDER ID : ".$order_id);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }else if($result['content'] === '2'){
            $order_id = (int)$ORDER;
            $this->log->write("ORDER REJECTED " . $result['content']."ORDER ID : ".$order_id);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }
        else if($result['content'] === '3'){
            $order_id = (int)$ORDER;
            $this->log->write("İşlem işleme hatası " . $result['content']."ORDER ID : ".$order_id);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }
        else if($result['content'] === '6'){
            $order_id = (int)$ORDER;
            $this->log->write(" Reddedilen bir işlemin tekrarlanması " . $result['content']."ORDER ID : ".$order_id);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }else if($result['content'] === '7'){
            $order_id = (int)$ORDER;
            $this->log->write(" Doğrulama hatası olan bir işlemin yeniden denenmesi " . $result['content']."ORDER ID : ".$order_id);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }else if($result['content'] === '8'){
            $order_id = (int)$ORDER;
            $this->log->write(" Yanıt verilmeden kesintiye uğrayan bir işlemin tekrarlanması " . $result['content']."ORDER ID : ".$order_id);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }else{
            $order_id = (int)$ORDER;
            $this->log->write(" UNKNOWN ERROR " . $result['content']."ORDER ID : ".$order_id);
            $this->response->redirect($this->url->link('checkout/failure', '', true));

        }
    }
    
    /**
     * Reversal method for Azericard payment gateway.
     *
     * This method is used to refund a previously processed payment.
     *
     * @return void
     */
    public function reversal() {
        // Log başlat
        $this->log->write("Refund process started");

        // POST verilerini al
        $json = array();
        
        // Gerekli parametreleri kontrol et
        $required_fields = array('ORDER', 'AMOUNT', 'RRN', 'INT_REF');
        foreach ($required_fields as $field) {
            if (!isset($this->request->post[$field])) {
                $json['error'] = 'Missing required field: ' . $field;
                $this->log->write("Refund Error: " . $json['error']);
                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode($json));
                return;
            }
        }

        // Parametreleri hazırla
        $params = array(
            'AMOUNT'     => number_format($this->request->post['AMOUNT'], 2, '.', ''),
            'CURRENCY'   => $this->config->get('payment_azericard_currency') ?: 'AZN',
            'ORDER'      => $this->request->post['ORDER'],
            'RRN'        => $this->request->post['RRN'],
            'INT_REF'    => $this->request->post['INT_REF'],
            'TERMINAL'   => $this->config->get('payment_azericard_terminal'),
            'TRTYPE'     => '22',
            'TIMESTAMP'  => date("YmdHis"),
            'NONCE'      => bin2hex(random_bytes(16)),
        );

        // P_SIGN oluştur
        $params['P_SIGN'] = $this->generatePSign($params);

        // Test modu kontrolü
        $test_mode = $this->config->get('payment_azericard_test_mode');
        if ($test_mode === null || $test_mode === '') {
            $test_mode = '0';
        }
        
        // URL'yi belirle
        if ($test_mode === '0') {
            $url = 'https://mpi.3dsecure.az/cgi-bin/cgi_link';  // Live URL
        } else {
            $url = 'https://testmpi.3dsecure.az/cgi-bin/cgi_link';  // Test URL
        }

        // İade isteğini gönder
        $result = $this->get_web_page($url, http_build_query($params));
        
        // Sonucu logla
        $this->log->write("Refund Response: " . print_r($result, true));

        // Sonucu kontrol et
        if ($result['content'] === '0') {
            $json['success'] = true;
            $json['message'] = 'Refund successful';
            
            // Sipariş durumunu güncelle
            $this->load->model('checkout/order');
            $this->model_checkout_order->addOrderHistory(
                $this->request->post['order_id'],
                2, // Refunded status
                'Amount refunded: ' . $params['AMOUNT'] . ' ' . $params['CURRENCY'],
                true
            );
        } else {
            $json['error'] = 'Refund failed. Error code: ' . $result['content'];
        }

        // JSON yanıtı gönder
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}
?>
