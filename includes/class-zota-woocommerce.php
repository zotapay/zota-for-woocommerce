<?php
/**
 * Zota for WooCommerce
 *
 * @package ZotaWooCommerce
 * @author  Zota
 */

use \Zota\Zota_WooCommerce\Includes\Settings;
use \Zota\Zota_WooCommerce\Includes\Order;
use \Zota\Zota_WooCommerce\Includes\Response;
use \Zotapay\Zotapay;
use \Zotapay\Deposit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zota_WooCommerce class.
 *
 * @extends WC_Payment_Gateway
 */
class Zota_WooCommerce extends WC_Payment_Gateway {

	const ZOTAPAY_WAITING_APPROVAL = '4'; // Hours for waiting approval.

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
	public static $test_prefix;

	/**
	 * Redirect url
	 *
	 * @var string
	 */
	public static $redirect_url;

	/**
	 * Callback url
	 *
	 * @var string
	 */
	public static $callback_url;

	/**
	 * Checkout url
	 *
	 * @var string
	 */
	public static $checkout_url;


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

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Test prefix.
		$testmode = false === empty( $this->get_option( 'testmode' ) ) ? true : false;
		if ( empty( $this->get_option( 'test_prefix' ) ) ) {
			$this->update_option( 'test_prefix', hash( 'crc32', get_bloginfo( 'url' ) ) . '-test-' );
		}
		self::$test_prefix = $testmode ? $this->get_option( 'test_prefix' ) : '';

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

		// Logging destination.
		Settings::log_destination();

		// Logging treshold.
		if ( 'yes' === $this->get_option( 'logging' ) ) {
			Settings::log_treshold();
		}

		// Scheduled pending payments check.
		$next_scheduled_time = wp_next_scheduled( 'zota_scheduled_order_status' );
		if ( ! $next_scheduled_time ) {
			wp_schedule_event( time(), 'hourly', 'zota_scheduled_order_status' );
		}

		// Hooks.
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_' . $this->id, array( '\Zota\Zota_WooCommerce\Includes\Response', 'callback' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( '\Zota\Zota_WooCommerce\Includes\Response', 'redirect' ) );
		add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'order_status_button' ) );
		add_action( 'save_post', array( $this, 'order_status_request' ) );
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
		$order = wc_get_order( $order_id );

		// Zotapay urls.
		self::$redirect_url = $this->get_return_url( $order );
		self::$callback_url = preg_replace( '/^http:/i', 'https:', home_url( '?wc-api=' . $this->id ) );
		self::$checkout_url = $this->get_return_url( $order );

		// Deposit order.
		$deposit_order = Order::deposit_order( $order_id );
		$deposit       = new Deposit();

		// Deposit request.
		$response = $deposit->request( $deposit_order );
		if ( null !== $response->getMessage() ) {
			wc_add_notice(
				'Zotapay Error: ' . esc_html( '(' . $response->getCode() . ') ' . $response->getMessage() ),
				'error'
			);
			return;
		}

		// Remove cart.
		$woocommerce->cart->empty_cart();

		// Add order meta.
		if ( null !== $response->getMerchantOrderID() ) {
			add_post_meta( $order_id, '_zotapay_merchant_order_id', sanitize_text_field( $response->getMerchantOrderID() ) );
		}
		if ( null !== $response->getOrderID() ) {
			add_post_meta( $order_id, '_zotapay_order_id', sanitize_text_field( $response->getOrderID() ) );
		}

		// Add expiration time.
		Order::set_expiration_time( $order_id );

		return array(
			'result'   => 'success',
			'redirect' => $response->getDepositUrl(),
		);
	}


	/**
	 * Order Status button.
	 */
	public function order_status_button() {

		global $post;

		// Get the order.
		$order = wc_get_order( $post->ID );
		if ( ! $order ) {
			return;
		}

		// Check if payment method is Zota Woocommerce.
		if ( ZOTA_WC_GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		?>
			<button type="submit" name="zota-order-status" class="button zota-order-status" value="1">
				<?php esc_html_e( 'Order Status', 'zota-woocommerce' ); ?>
			</button>
		<?php
	}


	/**
	 * Order Status request.
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_status_request( $order_id ) {

		// Get the order.
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Check if payment method is Zota Woocommerce.
		if ( ZOTA_WC_GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		// Check if is order status request.
		if ( ! isset( $_POST['zota-order-status'] ) || '1' !== $_POST['zota-order-status'] ) { // phpcs:ignore
			return;
		}

		// Order status request.
		$response = Order::order_status( $order_id );

		if ( false === $response ) {
			$order->add_order_note( esc_html__( 'Order Status admin request failed. Maybe order not yet sent to Zotapay.', 'zota-woocommerce' ) );
			$order->save();
			return;
		}

		$status = ! empty( $response->getStatus() ) ? $response->getStatus() : $response->getErrorMessage();

		$note = sprintf(
			// translators: %1$s Status, %2$s Order ID, %3$s Merchant Order ID.
			esc_html__( 'Order Status request from administration: %1$s. Order ID #%2$s / Merchant Order ID #%3$s', 'zota-woocommerce' ),
			$status,
			$response->getOrderID(),
			$response->getMerchantOrderID()
		);
		$order->add_order_note( $note );
		$order->save();

		// order status update.
		Order::update_status( $order_id, $response );
	}
}
