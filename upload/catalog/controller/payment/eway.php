<?php
class ControllerPaymentEway extends Controller {

	public function index() {
		$this->load->language('payment/eway');

		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['text_credit_card'] = $this->language->get('text_credit_card');
		$data['entry_cc_name'] = $this->language->get('entry_cc_name');
		$data['entry_cc_number'] = $this->language->get('entry_cc_number');
		$data['entry_cc_expire_date'] = $this->language->get('entry_cc_expire_date');
		$data['entry_cc_cvv2'] = $this->language->get('entry_cc_cvv2');

		$data['text_card_type_pp'] = $this->language->get('text_card_type_pp');
		$data['text_card_type_mp'] = $this->language->get('text_card_type_mp');
		$data['text_card_type_vm'] = $this->language->get('text_card_type_vm');
		$data['text_type_help'] = $this->language->get('text_type_help');

		$data['help_cvv'] = $this->language->get('help_cvv');
		$data['help_cvv_amex'] = $this->language->get('help_cvv_amex');

		$data['payment_type'] = $this->config->get('eway_payment_type');

		$data['months'] = array();

		for ($i = 1; $i <= 12; $i++) {
			$data['months'][] = array(
				'text' => sprintf('%02d', $i),
				'value' => sprintf('%02d', $i)
			);
		}

		$today = getdate();
		$data['year_expire'] = array();

		for ($i = $today['year']; $i < $today['year'] + 11; $i++) {
			$data['year_expire'][] = array(
				'text' => strftime('%Y', mktime(0, 0, 0, 1, 1, $i)),
				'value' => strftime('%Y', mktime(0, 0, 0, 1, 1, $i))
			);
		}

		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

		$amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);

		if ($this->config->get('eway_test')) {
			$data['text_testing'] = $this->language->get('text_testing');
		}

		$request = new stdClass();

		$request->Customer = new stdClass();
		$request->Customer->Title = 'Mr.';
		$request->Customer->FirstName = strval($order_info['payment_firstname']);
		$request->Customer->LastName = strval($order_info['payment_lastname']);
		$request->Customer->CompanyName = strval($order_info['payment_company']);
		$request->Customer->Street1 = strval($order_info['payment_address_1']);
		$request->Customer->Street2 = strval($order_info['payment_address_2']);
		$request->Customer->City = strval($order_info['payment_city']);
		$request->Customer->State = strval($order_info['payment_zone']);
		$request->Customer->PostalCode = strval($order_info['payment_postcode']);
		$request->Customer->Country = strtolower($order_info['payment_iso_code_2']);
		$request->Customer->Email = $order_info['email'];
		$request->Customer->Phone = $order_info['telephone'];

		$request->ShippingAddress = new stdClass();
		$request->ShippingAddress->FirstName = strval($order_info['shipping_firstname']);
		$request->ShippingAddress->LastName = strval($order_info['shipping_lastname']);
		$request->ShippingAddress->Street1 = strval($order_info['shipping_address_1']);
		$request->ShippingAddress->Street2 = strval($order_info['shipping_address_2']);
		$request->ShippingAddress->City = strval($order_info['shipping_city']);
		$request->ShippingAddress->State = strval($order_info['shipping_zone']);
		$request->ShippingAddress->PostalCode = strval($order_info['shipping_postcode']);
		$request->ShippingAddress->Country = strtolower($order_info['shipping_iso_code_2']);
		$request->ShippingAddress->Email = $order_info['email'];
		$request->ShippingAddress->Phone = $order_info['telephone'];
		$request->ShippingAddress->ShippingMethod = "Unknown";

		$invoice_desc = '';
		foreach ($this->cart->getProducts() as $product) {
			$item_price = $this->currency->format($product['price'], $order_info['currency_code'], false, false);
			$item_total = $this->currency->format($product['total'], $order_info['currency_code'], false, false);
			$item = new stdClass();
			$item->SKU = strval($product['product_id']);
			$item->Description = strval($product['name']);
			$item->Quantity = strval($product['quantity']);
			$item->UnitCost = strval($item_price * 100);
			$item->Total = strval($item_total * 100);
			$request->Items[] = $item;
			$invoice_desc .= $product['name'] . ', ';
		}
		$invoice_desc = substr($invoice_desc, 0, -2);
		if (strlen($invoice_desc) > 64)
			$invoice_desc = substr($invoice_desc, 0, 61) . '...';

		$shipping = $this->currency->format($order_info['total'] - $this->cart->getSubTotal(), $order_info['currency_code'], false, false);

		if ($shipping > 0) {
			$item = new stdClass();
			$item->SKU = '';
			$item->Description = $this->language->get('text_shipping');
			$item->Quantity = 1;
			$item->UnitCost = $shipping * 100;
			$item->Total = $shipping * 100;
			$request->Items[] = $item;
		}

		$opt1 = new stdClass();
		$opt1->Value = $order_info['order_id'];
		$request->Options = array($opt1);

		$request->Payment = new stdClass();
		$request->Payment->TotalAmount = number_format($amount, 2, '.', '') * 100;
		$request->Payment->InvoiceNumber = $this->session->data['order_id'];
		$request->Payment->InvoiceDescription = $invoice_desc;
		$request->Payment->InvoiceReference = $this->config->get('config_name') . ' - #' . $order_info['order_id'];
		$request->Payment->CurrencyCode = $order_info['currency_code'];

		$request->RedirectUrl = $this->url->link('payment/eway/callback', '', 'SSL');
		if ($this->config->get('eway_transaction_method') == 'auth') {
			$request->Method = 'Authorise';
		} else {
			$request->Method = 'ProcessPayment';
		}
		$request->TransactionType = 'Purchase';
		$request->DeviceID = 'opencart-'.VERSION.' eway-trans-2.0';
		$request->CustomerIP = $this->request->server['REMOTE_ADDR'];

		$this->load->model('payment/eway');
		$result = $this->model_payment_eway->getAccessCode($request);

		// Check if any error returns
		if (isset($result->Errors)) {
			$error_array = explode(",", $result->Errors);
			$lbl_error = "";
			foreach ($error_array as $error) {
				$error = $this->language->get($error);
				$lbl_error .= $error . "<br />\n";
			}
			$this->log->write('eWAY Payment error: ' . $lbl_error);
		}

		if (isset($lbl_error)) {
			$data['error'] = $lbl_error;
		} else {
			$data['action'] = $result->FormActionURL;
			$data['AccessCode'] = $result->AccessCode;
		}

		if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/eway.tpl')) {
			return $this->load->view($this->config->get('config_template') . '/template/payment/eway.tpl', $data);
		} else {
			return $this->load->view('default/template/payment/eway.tpl', $data);
		}
	}

	public function callback() {
		$this->load->language('payment/eway');

		if (isset($this->request->get['AccessCode']) || isset($this->request->get['amp;AccessCode'])) {

			$this->load->model('payment/eway');

			if (isset($this->request->get['amp;AccessCode'])) {
				$access_code = $this->request->get['amp;AccessCode'];
			} else {
				$access_code = $this->request->get['AccessCode'];
			}

			$result = $this->model_payment_eway->getAccessCodeResult($access_code);

			$is_error = false;

			// Check if any error returns
			if (isset($result->Errors)) {
				$error_array = explode(",", $result->Errors);
				$is_error = true;
				$lbl_error = '';
				foreach ($error_array as $error) {
					$error = $this->language->get('text_card_message_'.$error);
					$lbl_error .= $error . ", ";
				}
				$this->log->write('eWAY error: ' . $lbl_error);
			}
			if (!$is_error) {
				$fraud = false;
				if (!$result->TransactionStatus) {
					$error_array = explode(", ", $result->ResponseMessage);
					$is_error = true;
					$lbl_error = '';
					$log_error = '';
					foreach ($error_array as $error) {
						// Don't show fraud issues to customers
						if (stripos($error, 'F') === false) {
							$lbl_error .= $this->language->get('text_card_message_'.$error);
						} else {
							$fraud = true;
						}
						$log_error .= $this->language->get('text_card_message_'.$error) . ", ";
					}
					$log_error = substr($log_error, 0, -2);
					$this->log->write('eWAY payment failed: ' . $log_error);
				}
			}

			$this->load->model('checkout/order');

			if ($is_error) {
				if ($fraud) {
					$message = "Possible Fraud\n";
					$message .= 'Transaction ID: '.$result->TransactionID."\n";
					$message .= 'Fraud reason: '.$log_error."\n";
					$message .= 'Beagle Score: '.$result->BeagleScore."\n";
					$this->model_checkout_order->addOrderHistory($order_id, $this->config->get('eway_order_status_fraud_id'), $message);
					$this->response->redirect($this->url->link('checkout/failure', '', 'SSL'));
				} else {
					$this->session->data['error'] = $this->language->get('text_transaction_failed').$lbl_error;
					$this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
				}
			} else {
				$order_id = $result->Options[0]->Value;

				$order_info = $this->model_checkout_order->getOrder($order_id);

				$this->load->model('payment/eway');
				$eway_order_data = array(
					'order_id' => $order_id,
					'transaction_id' => $result->TransactionID,
					'amount' => $result->TotalAmount / 100,
					'currency_code' => $order_info['currency_code'],
					'debug_data' => json_encode($result)
				);

				$eway_order_id = $this->model_payment_eway->addOrder($eway_order_data);
				$this->model_payment_eway->addTransaction($eway_order_id, $this->config->get('eway_transaction_method'), $result->TransactionID, $order_info);

				$message = 'Transaction ID: '.$result->TransactionID."\n";
				$message .= 'Authorisation Code: '.$result->AuthorisationCode."\n";
				$message .= 'Card Response Code: '.$result->ResponseCode."\n";

				if ($this->config->get('eway_transaction_method') == 'payment') {
					$this->model_checkout_order->addOrderHistory($order_id, $this->config->get('eway_order_status_id'), $message);
				} else {
					$this->model_checkout_order->addOrderHistory($order_id, $this->config->get('eway_order_status_auth_id'), $message);
				}

				if (!empty($result->Customer->TokenCustomerID) && $this->customer->isLogged() && !$this->model_checkout_order->checkToken($result->Customer->TokenCustomerID)) {
					$card_data = array();
					$card_data['customer_id'] = $this->customer->getId();
					$card_data['Token'] = $result->Customer->TokenCustomerID;
					$card_data['Last4Digits'] = substr(str_replace(' ', '', $result->Customer->CardDetails->Number), -4, 4);
					$card_data['ExpiryDate'] = $result->Customer->CardDetails->ExpiryMonth . '/' . $result->Customer->CardDetails->ExpiryYear;
					$card_data['CardType'] = '';
					$this->model_payment_eway->addFullCard($this->session->data['order_id'], $card_data);
				}

				$this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
			}
		}
	}
}