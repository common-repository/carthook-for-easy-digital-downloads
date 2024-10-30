<?php

/*
 * Plugin Name: CartHook for Easy Digital Downloads
 * Plugin URI: https://carthook.com/
 * Description: CartHook helps you increase revenue by automatically recovering abandoned carts.
 * Version: 1.0.6
 * Author: CartHook
 * Author URI: https://carthook.com/
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

class CartHook_EDD
{


    /**
     * Constructor for the plugin.
     *
     * @access        public
     */
    public function __construct()
    {

        if( ! class_exists('Easy_Digital_Downloads'))
        {
            add_action('admin_init', array($this, 'carthook_nag_ignore'));
            add_action('admin_notices', array($this, 'edd_missing_notice'));

            return;
        }

        // Add the plugin page Settings and Docs links
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'carthook_plugin_links' ));

        // Hooks
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'admin_menu', array( $this, 'admin_page' ) );
        add_action( 'wp_footer', array( $this, 'add_footer_scripts' ) );
        add_action( 'init', array( $this, 'regenerate_cart_from_url' ) );
        add_action( 'edd_complete_purchase', array( $this, 'POST_carthook_complete' ) );

    }

    /**
     *
     * Posts a COMPLETE status to the CartHook API
     *
     * Avoiding wp_remote_post because of HTTPS issues.
     *
     * @param $payment_id
     * The input variable from the edd_complete_purchase action
     *
     */

    public function POST_carthook_complete( $payment_id )
    {

        $url  = 'https://api.carthook.com/api/v1/rest/track/complete';
        $data = array(
          '_em'  => edd_get_payment_user_email($payment_id),
          '_mid' => get_option('carthook_merchant_id')
        );

        // Get cURL resource
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_RETURNTRANSFER => 1,
          CURLOPT_URL            => $url,
          CURLOPT_POST           => 1,
          CURLOPT_POSTFIELDS     => $data
        ));

        // Send the request & save response to $resp
        $resp = curl_exec($curl);

        // Close request to clear up some resources
        curl_close($curl);

    }

    /**
     *
     * Check if the cart url contains cart parameter.
     *
     * This is used for cart regeneration
     *
     * Update 1.0.3:
     * - Changed JSON_UNESCAPED_SLASHES for the numeric value of the constant to add PHP 5.3 support.
     *
     */

    public function regenerate_cart_from_url()
    {


        // If cart regeneration url param is available
        if ( isset( $_GET['cart'] ) ) {

            $cart = $_GET['cart'];

            // Clear any old cart_contents
            edd_empty_cart();

            $cart_items = json_decode( stripslashes_deep(urldecode($cart)), 64);

            foreach ( $cart_items as $item ) {

                $download_id = $item['item_id'];
                $options = isset( $item['item_price_id'] ) ? array( 'price_id' => $item['item_price_id'], 'quantity' => $item['item_quantity'] ) : array( 'quantity' => $item['item_quantity'] );

                edd_add_to_cart($download_id, $options);

            }
        }
    }

    /**
     * Set up admin notices
     *
     * @access        public
     * @return        void
     */
    public function admin_notices() {

        // If the Merchant ID field is empty
        if ( ! get_option( 'carthook_merchant_id' ) ) :
            ?>
            <div class="updated">
                <p><?php echo __( sprintf( 'CartHook requires a Merchant ID, please fill one out <a href="%s">here.</a>', admin_url( 'admin.php?page=carthook' ) ), 'carthook_edd' ); ?></p>
            </div>
            <?php
        endif;
    }

    /**
     * Initialize the CartHook menu
     *
     * @access        public
     * @return        void
     */
    public function admin_page() {
        add_menu_page( 'CartHook', 'CartHook', 'manage_options', 'carthook', array( &$this, 'admin_options' ), plugins_url( 'images/carthook.png', __FILE__ ), 58 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Register settings for CartHook
     *
     * @access        public
     * @return        void
     */
    public function register_settings() {
        register_setting( 'carthook-settings-group', 'carthook_merchant_id' );
    }


    /**
     * Add options to the CartHook menu
     *
     * @access        public
     * @return        void
     */
    public function admin_options() {
        ?>
        <div class="wrap">
            <h2>CartHook for Easy Digital Downloads</h2>

            <form method="post" action="options.php">
                <?php settings_fields( 'carthook-settings-group' ); ?>
                <?php do_settings_sections( 'carthook-settings-group' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">CartHook Merchant ID</th>
                        <td><input type="text" name="carthook_merchant_id" value="<?php echo get_option( 'carthook_merchant_id' ); ?>" /></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
            <p>Need your Merchant ID?  Follow these steps:</p>
            <ol>
                <li><a href="https://carthook.com/register">Create your CartHook account</a></li>
                <li>Set up your abandoned cart email campaign</li>
                <li>You'll find your Merchant ID in Step 3 of the Setup page. Simply click on the "Easy Digital Downloads" tab and copy and paste your Merchant ID above.</li>
                <li>Click on the Save Changes button on this page</li>
                <li>Go back to the Setup page of your CartHook account and make sure to click the "I've installed the tracking code" button in Step 3.</li>
            </ol>
            <p>Have any questions? Contact us at 1-800-816-9316 or email <a href="mailto:jordan.gal@carthook.com">jordan@carthook.com</a></p>
        </div>
        <?php

    }

    /**
     * Add scripts to the footer of th checkout or thank you page
     *
     * @access        public
     * @return        void
     */
    public function add_footer_scripts() {
        if ( edd_is_checkout() ) {
            $this->add_checkout_script();
        }
    }

    /**
     * Checkout page script
     *
     * @access        public
     * @return        void
     */
    public function add_checkout_script() {

        $crthk_cart = $this->format_carthook_cart();

        if ( !is_null($crthk_cart))
        {
            ?>
            <script type='text/javascript'>
                var crthk_setup = '<?php echo get_option( 'carthook_merchant_id' ); ?>';
                var crthk_cart = <?php echo $crthk_cart ?>;

                (function() {
                    var ch = document.createElement('script'); ch.type = 'text/javascript'; ch.async = true;
                    ch.src = 'https://api.carthook.com/api/js/';
                    var x = document.getElementsByTagName('script')[0]; x.parentNode.insertBefore(ch, x);
                })();
            </script>
            <?php
        }

    }

    /**
     * Format a JSON object that CartHook can work with
     *
     * @access        public
     * @return        string
     */
    public function format_carthook_cart() {


        $carthook_cart = array(
            'price' => edd_get_cart_total(),
            'carturl' => edd_get_checkout_uri()
        );

        // Format the cart items in the carthook format
        $cart_items = edd_get_cart_content_details();

        if ( !empty($cart_items) && is_array($cart_items))
        {
            foreach ( $cart_items as $item_key => $item ) {

                // Force price formatting to include 2 decimal places
                $eachItemCost = number_format( $item['price'], 2, '.', '' );
                $totalItemCost = number_format( $item['price'] * $item['quantity'], 2, '.', '' );

                // Get image URL
                $imageURL = wp_get_attachment_url( get_post_thumbnail_id( $item['id']  ) );

                $carthook_cart['items'][] = array(
                    'imgUrl' => $imageURL ? $imageURL : '',
                    'url' => get_permalink( $item['id'] ),
                    'name' => $item['name'],
                    'eachItemCost' => strval( $eachItemCost ),
                    'totalItemCost' => strval( $totalItemCost ),
                    'item_id' => $item['id'],
                    'item_quantity' => $item['quantity'],
                    'item_price_id' => isset($item['item_number']['options']['price_id']) ? $item['item_number']['options']['price_id'] : null // for item variations
                );
            }

            /**
             * Json encode + URL encode cart contents.
             *
             * Update 1.0.3:
             * - Changed JSON_UNESCAPED_SLASHES for the numeric value of the constant to add PHP 5.3 support.
             *
             */

            $carthook_cart['carturl'] .= '?cart='.urlencode( json_encode($carthook_cart['items'] , 64 ) );


            return json_encode( $carthook_cart );

        }

        return null;

    }


    /**
     * Thank you page script
     *
     * @access        public
     * @return        void
     */
    public function add_thankyou_script() {
        ?>
        <script type='text/javascript'>
            var crthk_setup = '<?php echo get_option( 'carthook_merchant_id' ); ?>';
            var crthk_complete = true;

            (function() {
                var ch = document.createElement('script'); ch.type = 'text/javascript'; ch.async = true;
                ch.src = 'https://api.carthook.com/api/js/';
                var x = document.getElementsByTagName('script')[0]; x.parentNode.insertBefore(ch, x);
            })();
        </script>
        <?php
    }

    /**
     * Plugin page links
     *
     * @param array $links
     * @return array
     */
    function carthook_plugin_links( $links ) {

        $links['settings']      = '<a href="' . admin_url( 'admin.php?page=carthook&settings-updated=true' ) . '">' . __( 'Settings', 'carthook_edd' ) . '</a>';
        $links['feedback']      = '<a href="mailto:jordan@carthook.com">' . __( 'Feedback', 'carthook_edd' ) . '</a>';
        $links['documentation'] = '<a href="https://carthook.com/dashboard/help">' . __( 'Documentation', 'carthook_edd' ) . '</a>';

        return $links;

    }


    /**
     * Easy Digital Downloads plugin missing notice.
     *
     * @return string
     */
    public function edd_missing_notice() {

        global $current_user ;

        $user_id = $current_user->ID;

        if ( ! get_user_meta( $user_id, 'carthook_edd_missing_nag' ) ) {
            echo '<div class="error"><p>' . sprintf( __( 'CartHook for Easy Digital Downloads requires %s to be installed and active. | <a href="%s">Hide Notice</a>', 'carthook_edd' ), '<a href="https://easydigitaldownloads.com/" target="_blank">' . __( 'Easy_Digital_Downloads', 'carthook_edd' ) . '</a>', '?carthook_edd_missing_nag=0' ) . '</p></div>';
        }
    }


    /**
     *  Remove the nag if user chooses
     */
    function carthook_nag_ignore() {

        global $current_user;

        $user_id = $current_user->ID;

        if ( isset( $_GET['carthook_edd_missing_nag'] ) && '0' == $_GET['carthook_edd_missing_nag'] ) {
            add_user_meta( $user_id, 'carthook_edd_missing_nag', 'true', true );
        }

    }
}


/**
 * Load CartHook
 */
function carthook_plugins_loaded() {
    new CartHook_EDD();
}
add_action( 'plugins_loaded', 'carthook_plugins_loaded' );
