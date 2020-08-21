<?php
/**
 * Zota for WooCommerce
 *
 * @package ZotaWooCommerce
 * @author  Zota
 */

use \Zota\Zota_WooCommerce\Includes\Settings;
use \Zota\Zota_WooCommerce\Includes\Zotapay_Request;
use \Zota\Zota_WooCommerce\Includes\Zotapay_Response;
use \Zotapay\Zotapay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zota_WooCommerce class.
 *
 * @extends WC_Payment_Gateway
 */
class Zota_WooCommerce extends WC_Payment_Gateway {

	/**
	 * Zota Supported currencies
	 *
	 * @var array
	 */
	public static $supported_currencies = array(
		'USD',
		'EUR',
		'MYR',
		'VND',
		'THB',
		'IDR',
		'CNY',
	);

	/**
	 * The test prefix
	 *
	 * @var string
	 */
	public $test_prefix;

	/**
	 * Callback url
	 *
	 * @var string
	 */
	public $callback_url;

	/**
	 * The request object
	 *
	 * @var string
	 */
	public $request;

	/**
	 * Defines main properties, load settings fields and hooks
	 */
	public function __construct() {

		// Initial settings.
		$this->id                 = ZOTA_WC_GATEWAY_ID;
		$this->icon               = ZOTA_WC_URL . 'dist/img/icon.png';
		$this->has_fields         = false;
		$this->method_title       = ZOTA_WC_NAME;
		$this->method_description = esc_html__( 'Add card payments to WooCommerce with Zota', 'zota-woocommerce' );
		$this->supports           = array(
			'products',
		);
		$this->version            = ZOTA_WC_VERSION;
		$this->callback_url       = preg_replace( '/^http:/i', 'https:', home_url( '?wc-api=' . $this->id ) );

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Test prefix.
		$testmode = false === empty( $this->get_option( 'testmode' ) ) ? true : false;
		if ( empty( $this->get_option( 'test_prefix' ) ) ) {
			$this->update_option( 'test_prefix', hash( 'crc32', get_bloginfo( 'url' ) ) . '-test-' );
		}
		$this->test_prefix = $testmode ? $this->get_option( 'test_prefix' ) : '';

		// Texts.
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// Zotapay Configuration.
		$merchant_id         = $this->get_option( $testmode ? 'test_merchant_id' : 'merchant_id' );
		$merchant_secret_key = $testmode ? $this->get_option( 'test_merchant_secret_key' ) : $this->get_option( 'merchant_secret_key' );
		$endpoint            = $testmode ? 'test_endpoint_' : 'endpoint_';
		$api_base            = $testmode ? 'https://api.zotapay-sandbox.com' : 'https://api.zotapay.com';

		Zotapay::setMerchantId( $this->get_option( $testmode ? 'test_merchant_id' : 'merchant_id' ) );
		Zotapay::setMerchantSecretKey( $this->get_option( $testmode ? 'test_merchant_secret_key' : 'merchant_secret_key' ) );
		Zotapay::setEndpoint( $this->get_option( ( $testmode ? 'test_endpoint_' : 'endpoint_' ) . strtolower( get_woocommerce_currency() ) ) );
		Zotapay::setApiBase( $testmode ? 'https://api.zotapay-sandbox.com' : 'https://api.zotapay.com' );

		// Setup Zotapay request object.
		$this->request = new Zotapay_Request( $this );

		// Hooks.
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_' . $this->id, array( '\Zota\Zota_WooCommerce\Includes\Zotapay_Response', 'callback' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( '\Zota\Zota_WooCommerce\Includes\Zotapay_Response', 'redirect' ) );
	}

	/**
	 * Get supported currencies
	 *
	 * @return array
	 */
	public function supported_currencies() {
		return apply_filters( ZOTA_WC_GATEWAY_ID . '_supported_currencies', self::$supported_currencies );
	}

	/**
	 * Check if the currency is in the supported currencies
	 *
	 * @return bool
	 */
	public function is_supported() {
		return in_array( get_woocommerce_currency(), $this->supported_currencies(), true );
	}

	/**
	 * Check if the gateway is available.
	 *
	 * @return false|self
	 */
	public function is_available() {
		if ( ! $this->is_supported() ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Settings Form Fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		if ( false === $this->is_supported() ) {
			return;
		}

		$this->form_fields = Settings::form_fields();
	}

	/**
	 * Admin options scripts.
	 *
	 * @param  string $hook WooCommerce Hook.
	 * @return void
	 */
	public function admin_enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'zota-woocommerce', ZOTA_WC_URL . '/dist/js/admin.js', array(), ZOTA_WC_VERSION, true );
	}

	/**
	 * Admin Panel Options
	 *
	 * @return string|self
	 */
	public function admin_options() {
		if ( false === $this->is_supported() ) {
			?>
			<div class="inline error">
				<p>
					<strong><?php esc_html_e( 'Gateway Disabled', 'zota-woocommerce' ); ?></strong>:
					<?php esc_html_e( 'Zota does not support your store currency.', 'zota-woocommerce' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		parent::admin_options();
	}

	/**
	 * Process the payment and return the result
	 *
	 * @param  int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		global $woocommerce;

		$response = $this->request->deposit( $order_id );
		if ( null !== $response->getMessage() ) {
			wc_add_notice(
				'Zotapay Error: ' . esc_html( '(' . $response->getCode() . ') ' . $response->getMessage() ),
				'error'
			);
			return;
		}

		// Remove cart.
		$woocommerce->cart->empty_cart();

		// TODO add expiration time.

		return array(
			'result'   => 'success',
			'redirect' => $response->getDepositUrl(),
		);
	}
}
