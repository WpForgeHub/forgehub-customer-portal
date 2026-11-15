<?php
/**
 * Plugin Name:       WPForgeHub Digital Customer Portal & Downloads for WooCommerce
 * Plugin URI:        https://wpforgehub.com/plugins/customer-portal/
 * Description:       Modern, high-performance customer dashboard for WooCommerce digital products. Features 1-click passwordless Magic Link login, clean Downloads (.zip) manager, license management, and instant post-checkout redirect.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WPForgeHub
 * Author URI:        https://wpforgehub.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpfh-customer-portal
 * Domain Path:       /languages
 *
 * @package WPForgeHub\CustomerPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPFH_CP_VERSION', '1.1.0' );
define( 'WPFH_CP_FILE', __FILE__ );
define( 'WPFH_CP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPFH_CP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Freemius SDK Integration Wrapper.
 */
if ( ! function_exists( 'wpfh_cp_fs' ) ) {
    function wpfh_cp_fs() {
        global $wpfh_cp_fs;

        if ( ! isset( $wpfh_cp_fs ) ) {
            $fs_path = WPFH_CP_PATH . 'freemius/start.php';
            if ( file_exists( $fs_path ) ) {
                require_once $fs_path;
                $wpfh_cp_fs = fs_dynamic_init( [
                    'id'                  => '45001',
                    'slug'                => 'wpfh-customer-portal',
                    'type'                => 'plugin',
                    'public_key'          => 'pk_customer_portal_public_placeholder',
                    'is_premium'          => false,
                    'has_addons'          => false,
                    'has_paid_plans'      => true,
                    'menu'                => [
                        'slug'    => 'wpfh-customer-portal',
                        'parent'  => [
                            'slug' => 'woocommerce',
                        ],
                    ],
                ] );
            } else {
                // Fallback object for Free / Filter-based Pro distribution
                $wpfh_cp_fs = new class {
                    public function can_use_premium_code() {
                        return (bool) apply_filters( 'fs_can_use_premium_code__wpfh-customer-portal', false );
                    }
                    public function is__premium_only() {
                        return false;
                    }
                    public function is_plan( $plan ) {
                        return (bool) apply_filters( 'fs_is_plan__wpfh-customer-portal', false, $plan );
                    }
                    public function get_upgrade_url() {
                        return 'https://wpforgehub.com/plugins/customer-portal/#pricing';
                    }
                };
            }
        }

        return $wpfh_cp_fs;
    }

    wpfh_cp_fs();
}

/**
 * Check if WooCommerce is installed and active.
 */
function wpfh_cp_check_woocommerce_dependency() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><strong>WPForgeHub Customer Portal:</strong> <?php esc_html_e( 'WooCommerce is required for the Customer Portal to operate. Please install and activate WooCommerce.', 'wpfh-customer-portal' ); ?></p>
            </div>
            <?php
        } );
        return false;
    }
    return true;
}

/**
 * Initialize Plugin Core & Pro Modules.
 */
function wpfh_cp_init() {
    load_plugin_textdomain( 'wpfh-customer-portal', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    if ( ! wpfh_cp_check_woocommerce_dependency() ) {
        return;
    }

    require_once WPFH_CP_PATH . 'includes/class-wpfh-cp-portal.php';
    require_once WPFH_CP_PATH . 'includes/class-wpfh-cp-magic-link.php';

    WPFH_CP_Portal::init();
    WPFH_CP_Magic_Link::init();

    // Initialize Pro Features when license is active
    if ( function_exists( 'wpfh_cp_fs' ) && wpfh_cp_fs()->can_use_premium_code() ) {
        $pro_tickets = WPFH_CP_PATH . 'includes/pro/class-wpfh-cp-support-tickets.php';
        $pro_domains = WPFH_CP_PATH . 'includes/pro/class-wpfh-cp-domain-licenses.php';
        $pro_invoice = WPFH_CP_PATH . 'includes/pro/class-wpfh-cp-pdf-invoices.php';

        if ( file_exists( $pro_tickets ) ) {
            require_once $pro_tickets;
            WPFH_CP_Support_Tickets::init();
        }

        if ( file_exists( $pro_domains ) ) {
            require_once $pro_domains;
            WPFH_CP_Domain_Licenses::init();
        }

        if ( file_exists( $pro_invoice ) ) {
            require_once $pro_invoice;
            WPFH_CP_PDF_Invoices::init();
        }
    }
}
add_action( 'plugins_loaded', 'wpfh_cp_init', 20 );

/**
 * Activation & Flush Rewrites.
 */
register_activation_hook( __FILE__, function() {
    require_once WPFH_CP_PATH . 'includes/class-wpfh-cp-portal.php';
    WPFH_CP_Portal::add_portal_endpoints();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );
