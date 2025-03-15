<?php
class ModelExtensionPaymentAzericard extends Model {
    public function getMethod($address, $total) {
        $this->load->language('extension/payment/azericard');

        // Geo Zone kontrolleri
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_azericard_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

        // Toplam kontrolü
        if ($this->config->get('payment_azericard_total') > 0 && $this->config->get('payment_azericard_total') > $total) {
            $status = false;
        } elseif (!$this->config->get('payment_azericard_geo_zone_id')) {
            // Geo zone ID kontrolü yoksa durumu doğru kabul et
            $status = true;
        } elseif ($query->num_rows) {
            $status = true;
        } else {
            $status = false;
        }

        // Ödeme yöntemi verilerini döndür
        $method_data = array();

        if ($status) {
            $method_data = array(
                'code'       => 'azericard',
                'title'      => $this->language->get('text_title'), // Dil dosyasından başlık al
                'terms'      => '', // Şartlar (isteğe bağlı)
                'sort_order' => $this->config->get('payment_azericard_sort_order') // Sıralama
            );
        }

        return $method_data;
    }
}
?>
