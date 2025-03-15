<?php

class ControllerExtensionPaymentAzeriCard extends Controller {
    private $error = array();



    public function index() {
        // Load language and model
        $this->load->language('extension/payment/azericard');
        $this->load->model('setting/setting');


        if (!$this->config->get('payment_azericard_test_mode')) {
            $this->model_setting_setting->editSetting('payment_azericard_test_mode', [
                'payment_azericard_test_mode' => '1',
            ]);
        }

        if (!$this->config->get('payment_azericard_email')) {
            $this->model_setting_setting->editSetting('payment_azericard', [
                'payment_azericard_email' => 'default_email',
            ]);
        }

        if (!$this->config->get('payment_azericard_url')) {
            $this->model_setting_setting->editSetting('payment_azericard_url', [
                'payment_azericard_url' => 'default_url',
            ]);
        }


        // Set the breadcrumb and title
        $this->document->setTitle($this->language->get('heading_title'));

        // Handle form submission
        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            if (!isset($this->request->post['payment_azericard_test_mode'])) {
                $this->request->post['payment_azericard_test_mode'] = '0';
            }
            // Save settings if there are any changes
            $this->model_setting_setting->editSetting('payment_azericard', $this->request->post);

            // Set success message and redirect
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'], true));
        }

        // Set data for the view
        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');

        $data['action'] = $this->url->link('extension/payment/azericard', 'user_token=' . $this->session->data['user_token'], true);

        // Add the form fields, check if values exist in configuration or use default values
        $data['payment_azericard_terminal'] = isset($this->request->post['payment_azericard_terminal']) ? $this->request->post['payment_azericard_terminal'] : $this->config->get('payment_azericard_terminal');
        $data['payment_azericard_status'] = isset($this->request->post['payment_azericard_status']) ? $this->request->post['payment_azericard_status'] : $this->config->get('payment_azericard_status');
        $data['payment_azericard_email'] = isset($this->request->post['payment_azericard_email']) ? $this->request->post['payment_azericard_email'] : $this->config->get('payment_azericard_email');
        $data['payment_azericard_url'] = isset($this->request->post['payment_azericard_url']) ? $this->request->post['payment_azericard_url'] : $this->config->get('payment_azericard_url');
        $data['payment_azericard_test_mode'] = isset($this->request->post['payment_azericard_test_mode']) ? $this->request->post['payment_azericard_test_mode'] : $this->config->get('payment_azericard_test_mode');


        // Load language entries
        $data['entry_terminal'] = $this->language->get('entry_terminal');
        $data['entry_url'] = $this->language->get('entry_url');
        $data['entry_email'] = $this->language->get('entry_email');
        $data['entry_status'] = $this->language->get('entry_status');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['button_save'] = $this->language->get('button_save');
        $data['entry_test_mode'] = $this->language->get('entry_test_mode');

        // Error handling
        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->error['terminal'])) {
            $data['error_terminal'] = $this->error['terminal'];
        } else {
            $data['error_terminal'] = '';
        }

        if (isset($this->error['entry_test_mode'])) {
            $data['entry_test_mode'] = $this->error['entry_test_mode'];
        } else {
            $data['entry_test_mode'] = '';
        }
        if (isset($this->error['entry_url'])) {
            $data['entry_url'] = $this->error['entry_url'];
        } else {
            $data['entry_url'] = '';
        }

        if (isset($this->error['status'])) {
            $data['error_status'] = $this->error['status'];
        } else {
            $data['error_status'] = '';
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        // Return the view template
        $this->response->setOutput($this->load->view('extension/payment/azericard', $data));
    }


    // Validation for settings form
    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/azericard')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->request->post['payment_azericard_terminal']) {
            $this->error['terminal'] = $this->language->get('error_terminal');
        }


        

        return !$this->error;
    }
}
?>
