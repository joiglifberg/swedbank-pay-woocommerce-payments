<?php

defined( 'ABSPATH' ) || exit;

use SwedbankPay\Payments\WooCommerce\WC_Swedbank_Pay_Transactions;
use SwedbankPay\Core\Adapter\WC_Adapter;
use SwedbankPay\Core\Core;

class WC_Gateway_Swedbank_Pay_Invoice extends WC_Gateway_Swedbank_Pay_Cc {

	/**
	 * Merchant Token
	 * @var string
	 */
	public $merchant_token = '';

	/**
	 * Payee Id
	 * @var string
	 */
	public $payee_id = '';

	/**
	 * Subsite
	 * @var string
	 */
	public $subsite = '';

	/**
	 * Test Mode
	 * @var string
	 */
	public $testmode = 'yes';

	/**
	 * Debug Mode
	 * @var string
	 */
	public $debug = 'yes';

	/**
	 * Locale
	 * @var string
	 */
	public $culture = 'en-US';

	/**
	 * Init
	 */
	public function __construct() {
		$this->transactions = WC_Swedbank_Pay_Transactions::instance();

		$this->id           = 'payex_psp_invoice';
		$this->has_fields   = true;
		$this->method_title = __( 'Invoice', 'swedbank-pay-woocommerce-payments' );
		//$this->icon         = apply_filters( 'wc_swedbank_pay_invoice_icon', plugins_url( '/assets/images/invoice.png', dirname( __FILE__ ) ) );
		$this->supports = array(
			'products',
			'refunds',
		);

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		$this->enabled        = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
		$this->title          = isset( $this->settings['title'] ) ? $this->settings['title'] : '';
		$this->description    = isset( $this->settings['description'] ) ? $this->settings['description'] : '';
		$this->merchant_token = isset( $this->settings['merchant_token'] ) ? $this->settings['merchant_token'] : $this->merchant_token;
		$this->payee_id       = isset( $this->settings['payee_id'] ) ? $this->settings['payee_id'] : $this->payee_id;
		$this->subsite        = isset( $this->settings['subsite'] ) ? $this->settings['subsite'] : $this->subsite;
		$this->testmode       = isset( $this->settings['testmode'] ) ? $this->settings['testmode'] : $this->testmode;
		$this->debug          = isset( $this->settings['debug'] ) ? $this->settings['debug'] : $this->debug;
		$this->culture        = isset( $this->settings['culture'] ) ? $this->settings['culture'] : $this->culture;
		$this->terms_url      = isset( $this->settings['terms_url'] ) ? $this->settings['terms_url'] : get_site_url();
		$this->logo_url         = isset( $this->settings['logo_url'] ) ? $this->settings['logo_url'] : $this->logo_url;

		// TermsOfServiceUrl contains unsupported scheme value http in Only https supported.
		if ( ! filter_var( $this->terms_url, FILTER_VALIDATE_URL ) ) {
			$this->terms_url = '';
		} elseif ( 'https' !== parse_url( $this->terms_url, PHP_URL_SCHEME ) ) {
			$this->terms_url = '';
		}

		// Actions
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array(
				$this,
				'process_admin_options',
			)
		);

		add_action(
			'woocommerce_thankyou_' . $this->id,
			array(
				$this,
				'thankyou_page',
			)
		);

		// Payment listener/API hook
		add_action(
			'woocommerce_api_' . strtolower( __CLASS__ ),
			array(
				$this,
				'return_handler',
			)
		);

		// Payment confirmation
		add_action( 'the_post', array( $this, 'payment_confirm' ) );

		// Pending Cancel
		add_action(
			'woocommerce_order_status_pending_to_cancelled',
			array(
				$this,
				'cancel_pending',
			),
			10,
			2
		);

		$this->adapter = new WC_Adapter( $this );
		$this->core    = new Core( $this->adapter );
	}

	/**
	 * Initialise Settings Form Fields
	 * @return string|void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable plugin', 'swedbank-pay-woocommerce-payments' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __(
					'This controls the title which the user sees during checkout.',
					'swedbank-pay-woocommerce-payments'
				),
				'default'     => __( 'Invoice', 'swedbank-pay-woocommerce-payments' ),
			),
			'description'    => array(
				'title'       => __( 'Description', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __(
					'This controls the description which the user sees during checkout.',
					'swedbank-pay-woocommerce-payments'
				),
				'default'     => __( 'Invoice', 'swedbank-pay-woocommerce-payments' ),
			),
			'payee_id'       => array(
				'title'       => __( 'Payee Id', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Payee Id', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->payee_id,
			),
			'merchant_token' => array(
				'title'       => __( 'Merchant Token', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Merchant Token', 'swedbank-pay-woocommerce-payments' ),
				'default'     => $this->merchant_token,
			),
			'subsite'        => array(
				'title'       => __( 'Subsite', 'woocommerce-gateway-payex-checkout' ),
				'type'        => 'text',
				'description' => __( 'Subsite', 'woocommerce-gateway-payex-checkout' ),
				'default'     => $this->subsite,
			),
			'testmode'       => array(
				'title'   => __( 'Test Mode', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Swedbank Pay Test Mode', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->testmode,
			),
			'debug'          => array(
				'title'   => __( 'Debug', 'swedbank-pay-woocommerce-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'swedbank-pay-woocommerce-payments' ),
				'default' => $this->debug,
			),
			'culture'        => array(
				'title'       => __( 'Language', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'select',
				'options'     => array(
					'en-US' => 'English',
					'sv-SE' => 'Swedish',
					'nb-NO' => 'Norway',
				),
				'description' => __(
					'Language of pages displayed by Swedbank Pay during payment.',
					'swedbank-pay-woocommerce-payments'
				),
				'default'     => $this->culture,
			),
			'terms_url'      => array(
				'title'       => __( 'Terms & Conditions Url', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'Terms & Conditions Url', 'swedbank-pay-woocommerce-payments' ),
				'default'     => get_site_url(),
			),
			'logo_url'              => array(
				'title'       => __( 'Logo Url', 'swedbank-pay-woocommerce-payments' ),
				'type'        => 'text',
				'description' => __( 'The URL that will be used for showing the customer logo. Must be a picture with maximum 50px height and 400px width. Require https.', 'swedbank-pay-woocommerce-payments' ),
				'desc_tip'    => true,
				'default'     => '',
				'sanitize_callback' => function( $value ) {
					if ( ! empty( $value ) ) {
						if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
							throw new Exception( __( 'Logo Url is invalid.', 'swedbank-pay-woocommerce-payments' ) );
						} elseif ( 'https' !== parse_url( $value, PHP_URL_SCHEME ) ) {
							throw new Exception( __( 'Logo Url should use https scheme.', 'swedbank-pay-woocommerce-payments' ) );
						}
					}

					return $value;
				},
			),
		);
	}

	/**
	 * If There are no payment fields show the description if set.
	 */
	public function payment_fields() {
		parent::payment_fields();
		?>
		<p class="form-row form-row-wide">
			<label for="social-security-number">
				<?php echo __( 'Social Security Number', 'swedbank-pay-woocommerce-payments' ); ?>
				<abbr class="required">*</abbr>
			</label>
			<input type="text" class="input-text required-entry" name="social-security-number" id="social-security-number" value="" autocomplete="off">
		</p>
		<?php
	}

	/**
	 * Validate frontend fields.
	 *
	 * Validate payment fields on the frontend.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		if ( empty( $_POST['billing_country'] ) ) {
			wc_add_notice( __( 'Please specify country.', 'swedbank-pay-woocommerce-payments' ), 'error' );

			return false;
		}

		if ( empty( $_POST['billing_postcode'] ) ) {
			wc_add_notice( __( 'Please specify postcode.', 'swedbank-pay-woocommerce-payments' ), 'error' );

			return false;
		}

		if ( ! in_array( mb_strtoupper( $_POST['billing_country'], 'UTF-8' ), array( 'SE', 'NO', 'FI' ), true ) ) {
			wc_add_notice(
				__( 'This country is not supported by the payment system.', 'swedbank-pay-woocommerce-payments' ),
				'error'
			);

			return false;
		}

		// Validate country phone code
		if ( in_array( $_POST['billing_country'], array( 'SE', 'NO' ), true ) ) {
			$phone_code = mb_substr( ltrim( $_POST['billing_phone'], '+' ), 0, 2, 'UTF-8' );
			if ( ! in_array( $phone_code, array( '46', '47' ), true ) ) {
				wc_add_notice(
					__(
						'Invalid phone number. Phone code must include country phone code.',
						'swedbank-pay-woocommerce-payments'
					),
					'error'
				);

				return false;
			}
		}

		if ( empty( $_POST['social-security-number'] ) ) {
			wc_add_notice(
				__(
					'Please enter your Social Security Number and confirm your order.',
					'swedbank-pay-woocommerce-payments'
				),
				'error'
			);

			return false;
		}

		return true;

	}

	/**
	 * Thank you page
	 *
	 * @param $order_id
	 *
	 * @return void
	 */
	public function thankyou_page( $order_id ) {
		//
	}

	/**
	 * Process Payment
	 *
	 * @param int $order_id
	 *
	 * @return array|false
	 */
	public function process_payment( $order_id ) {
		$order    = wc_get_order( $order_id );
		$postcode = $order->get_billing_postcode();
		$ssn      = wc_clean( $_POST['social-security-number'] );

		// Process payment
		try {
			$result = $this->core->initiateInvoicePayment( $order_id );

			// Save payment ID
			update_post_meta( $order_id, '_payex_payment_id', $result['payment']['id'] );

			// Authorization
			$create_authorize_href = $result->getOperationByRel( 'create-authorization' );

			// Approved Legal Address
			$legal_address_href = $result->getOperationByRel( 'create-approved-legal-address' );

			// Get Approved Legal Address
			$address = $this->core->getApprovedLegalAddress( $legal_address_href, $ssn, $postcode );

			// Save legal address
			$legal_address = $address['approvedLegalAddress'];
			update_post_meta( $order_id, '_payex_legal_address', $legal_address );

			// Transaction Activity: FinancingConsumer
			$result = $this->core->transactionFinancingConsumer(
				$create_authorize_href,
				$order_id,
				$ssn,
				$legal_address['addressee'],
				$legal_address['coAddress'],
				$legal_address['streetAddress'],
				$legal_address['zipCode'],
				$legal_address['city'],
				$legal_address['countryCode']
			);
		} catch ( \Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );

			return false;
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Process Refund
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund
	 * a passed in amount.
	 *
	 * @param int $order_id
	 * @param float $amount
	 * @param string $reason
	 *
	 * @return  bool|wp_error True or false based on success, or a WP_Error object
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Full Refund
		if ( is_null( $amount ) ) {
			$amount = $order->get_total();
		}

		try {
			// Disable status change hook
			remove_action(
				'woocommerce_order_status_changed',
				'\SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
				10
			);

			$this->core->refundInvoice( $order->get_id(), $amount );

			return true;
		} catch ( \Exception $e ) {
			return new WP_Error( 'refund', $e->getMessage() );
		}
	}

	/**
	 * Capture
	 *
	 * @param WC_Order|int $order
	 * @param mixed $amount
	 * @param mixed $vat_amount
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function capture_payment( $order, $amount = false, $vat_amount = 0 ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		// Order Info
		$info = $this->get_order_info( $order );

		try {
			// Disable status change hook
			remove_action(
				'woocommerce_order_status_changed',
				'\SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
				10
			);

			$this->core->captureInvoice( $order->get_id(), $amount, $vat_amount, $info['items'] );
		} catch ( \SwedbankPay\Core\Exception $e ) {
			throw new Exception( $e->getMessage() );
		}
	}

	/**
	 * Cancel
	 *
	 * @param WC_Order|int $order
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function cancel_payment( $order ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		try {
			// Disable status change hook
			remove_action(
				'woocommerce_order_status_changed',
				'\SwedbankPay\Payments\WooCommerce\WC_Swedbank_Plugin::order_status_changed',
				10
			);

			$this->core->cancelInvoice( $order->get_id() );
		} catch ( \SwedbankPay\Core\Exception $e ) {
			throw new Exception( $e->getMessage() );
		}
	}

	/**
	 * Get Order Lines
	 *
	 * @param \WC_Order $order
	 *
	 * @return array
	 */
	private function get_order_items( $order ) {
		$item = array();

		foreach ( $order->get_items() as $order_item ) {
			/** @var \WC_Order_Item_Product $order_item */
			$price          = $order->get_line_subtotal( $order_item, false, false );
			$price_with_tax = $order->get_line_subtotal( $order_item, true, false );
			$tax            = $price_with_tax - $price;
			$tax_percent    = ( $tax > 0 ) ? round( 100 / ( $price / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'product',
				'name'              => $order_item->get_name(),
				'qty'               => $order_item->get_quantity(),
				'price_with_tax'    => sprintf( '%.2f', $price_with_tax ),
				'price_without_tax' => sprintf( '%.2f', $price ),
				'tax_price'         => sprintf( '%.2f', $tax ),
				'tax_percent'       => sprintf( '%.2f', $tax_percent ),
			);
		};

		// Add Shipping Line
		if ( (float) $order->get_shipping_total() > 0 ) {
			$shipping          = $order->get_shipping_total();
			$tax               = $order->get_shipping_tax();
			$shipping_with_tax = $shipping + $tax;
			$tax_percent       = ( $tax > 0 ) ? round( 100 / ( $shipping / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'shipping',
				'name'              => $order->get_shipping_method(),
				'qty'               => 1,
				'price_with_tax'    => sprintf( '%.2f', $shipping_with_tax ),
				'price_without_tax' => sprintf( '%.2f', $shipping ),
				'tax_price'         => sprintf( '%.2f', $tax ),
				'tax_percent'       => sprintf( '%.2f', $tax_percent ),
			);
		}

		// Add fee lines
		foreach ( $order->get_fees() as $order_fee ) {
			/** @var \WC_Order_Item_Fee $order_fee */
			$fee          = $order_fee->get_total();
			$tax          = $order_fee->get_total_tax();
			$fee_with_tax = $fee + $tax;
			$tax_percent  = ( $tax > 0 ) ? round( 100 / ( $fee / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'fee',
				'name'              => $order_fee->get_name(),
				'qty'               => 1,
				'price_with_tax'    => sprintf( '%.2f', $fee_with_tax ),
				'price_without_tax' => sprintf( '%.2f', $fee ),
				'tax_price'         => sprintf( '%.2f', $tax ),
				'tax_percent'       => sprintf( '%.2f', $tax_percent ),
			);
		}

		// Add discount line
		if ( $order->get_total_discount( false ) > 0 ) {
			$discount          = $order->get_total_discount( true );
			$discount_with_tax = $order->get_total_discount( false );
			$tax               = $discount_with_tax - $discount;
			$tax_percent       = ( $tax > 0 ) ? round( 100 / ( $discount / $tax ) ) : 0;

			$item[] = array(
				'type'              => 'discount',
				'name'              => __( 'Discount', 'swedbank-pay-woocommerce-payments' ),
				'qty'               => 1,
				'price_with_tax'    => sprintf( '%.2f', - 1 * $discount_with_tax ),
				'price_without_tax' => sprintf( '%.2f', - 1 * $discount ),
				'tax_price'         => sprintf( '%.2f', - 1 * $tax ),
				'tax_percent'       => sprintf( '%.2f', $tax_percent ),
			);
		}

		return $item;
	}

	/**
	 * Get Order Info
	 *
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	private function get_order_info( $order ) {
		$amount       = 0;
		$vat_amount   = 0;
		$descriptions = array();
		$items        = $this->get_order_items( $order );
		foreach ( $items as $item ) {
			$amount        += $item['price_with_tax'];
			$vat_amount    += $item['tax_price'];
			$unit_price     = sprintf( '%.2f', $item['price_without_tax'] / $item['qty'] );
			$descriptions[] = array(
				'product'    => $item['name'],
				'quantity'   => $item['qty'],
				'unitPrice'  => (int) round( $unit_price * 100 ),
				'amount'     => (int) round( $item['price_with_tax'] * 100 ),
				'vatAmount'  => (int) round( $item['tax_price'] * 100 ),
				'vatPercent' => sprintf( '%.2f', $item['tax_percent'] ),
			);
		}

		return array(
			'amount'     => $amount,
			'vat_amount' => $vat_amount,
			'items'      => $descriptions,
		);
	}
}
