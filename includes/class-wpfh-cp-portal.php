<?php
/**
 * WPForgeHub — Customer Portal Core Controller
 *
 * @package WPForgeHub\CustomerPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPFH_CP_Portal {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'add_portal_endpoints' ] );
        add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'customize_account_menu_items' ], 99 );
        add_action( 'woocommerce_account_support-tickets_endpoint', [ __CLASS__, 'render_support_tickets_endpoint' ] );
        add_action( 'woocommerce_before_account_downloads', [ __CLASS__, 'render_welcome_download_banner' ] );
        add_action( 'woocommerce_thankyou', [ __CLASS__, 'post_checkout_redirect_to_downloads' ], 1 );
        add_action( 'woocommerce_order_details_after_order_table', [ __CLASS__, 'display_license_in_order_details' ] );

        // Admin settings
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );

        // Assets
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_portal_assets' ] );
    }

    public static function get_default_settings() {
        return [
            'theme_mode'           => 'dark',
            'enable_magic_link'    => 1,
            'enable_auto_redirect' => 1,
            'support_email'        => get_option( 'admin_email' ),
            'welcome_heading'      => __( '🎉 Order Confirmed! Your Downloads Are Ready Below.', 'wpfh-customer-portal' ),
            'welcome_subheading'   => __( 'Thank you for your order! You can download your software files below and access them anytime from your account.', 'wpfh-customer-portal' ),
        ];
    }

    public static function get_settings() {
        return wp_parse_args( get_option( 'wpfh_cp_settings', [] ), self::get_default_settings() );
    }

    public static function add_portal_endpoints() {
        add_rewrite_endpoint( 'support-tickets', EP_ROOT | EP_PAGES );
    }

    public static function customize_account_menu_items( $items ) {
        $reordered = [
            'dashboard'       => __( '📊 Dashboard', 'wpfh-customer-portal' ),
            'downloads'       => __( '📥 Downloads (.zip)', 'wpfh-customer-portal' ),
            'orders'          => __( '📦 Orders & Licenses', 'wpfh-customer-portal' ),
            'edit-account'    => __( '👤 Profile & Password', 'wpfh-customer-portal' ),
            'support-tickets' => __( '💬 Support Desk', 'wpfh-customer-portal' ),
            'customer-logout' => __( '🚪 Log Out', 'wpfh-customer-portal' ),
        ];

        return $reordered;
    }

    public static function enqueue_portal_assets() {
        if ( ! is_account_page() && ! is_checkout() ) {
            return;
        }

        $css_path = WPFH_CP_PATH . 'assets/css/portal.css';
        $ver      = file_exists( $css_path ) ? filemtime( $css_path ) : WPFH_CP_VERSION;

        wp_enqueue_style( 'wpfh-cp-style', WPFH_CP_URL . 'assets/css/portal.css', [], $ver );

        $js_path = WPFH_CP_PATH . 'assets/js/portal.js';
        $js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : WPFH_CP_VERSION;

        wp_enqueue_script( 'wpfh-cp-script', WPFH_CP_URL . 'assets/js/portal.js', [ 'jquery' ], $js_ver, true );
        wp_localize_script( 'wpfh-cp-script', 'wpfhCpData', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'i18n'     => [
                'copied' => __( 'Copied to clipboard!', 'wpfh-customer-portal' ),
            ],
        ] );
    }

    /**
     * Post-checkout instant redirect to Customer Downloads.
     */
    public static function post_checkout_redirect_to_downloads( $order_id ) {
        if ( ! $order_id ) {
            return;
        }

        $settings = self::get_settings();
        if ( empty( $settings['enable_auto_redirect'] ) ) {
            return;
        }

        if ( is_user_logged_in() && ! isset( $_GET['noredirect'] ) ) {
            $downloads_url = add_query_arg( [
                'order_completed' => absint( $order_id ),
                'welcome'         => '1',
            ], wc_get_endpoint_url( 'downloads', '', wc_get_page_permalink( 'myaccount' ) ) );

            wp_safe_redirect( $downloads_url );
            exit;
        }
    }

    /**
     * Welcome banner on /my-account/downloads/.
     */
    public static function render_welcome_download_banner() {
        if ( isset( $_GET['welcome'] ) ) {
            $settings = self::get_settings();
            ?>
            <div class="wpfh-cp-welcome-banner">
                <span class="wpfh-cp-banner-icon">🎉</span>
                <div class="wpfh-cp-banner-text">
                    <h3><?php echo esc_html( $settings['welcome_heading'] ); ?></h3>
                    <p><?php echo esc_html( $settings['welcome_subheading'] ); ?></p>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Display License Key in Order Details table.
     */
    public static function display_license_in_order_details( $order ) {
        $license_key = $order->get_meta( '_freemius_license_key' );
        if ( empty( $license_key ) ) {
            $license_key = $order->get_meta( '_wpfh_license_key' );
        }

        if ( ! empty( $license_key ) ) {
            ?>
            <div class="wpfh-cp-license-box">
                <h4>🔑 <?php esc_html_e( 'Your Product License Key', 'wpfh-customer-portal' ); ?></h4>
                <p><?php esc_html_e( 'Use this license key inside your WordPress admin dashboard to activate Pro features and automated updates.', 'wpfh-customer-portal' ); ?></p>
                <div class="wpfh-cp-license-key-wrap">
                    <code><?php echo esc_html( $license_key ); ?></code>
                    <button type="button" class="button wpfh-cp-copy-key-btn" data-key="<?php echo esc_attr( $license_key ); ?>">
                        <?php esc_html_e( 'Copy Key', 'wpfh-customer-portal' ); ?>
                    </button>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Render Support Tickets Tab.
     */
    public static function render_support_tickets_endpoint() {
        $user     = wp_get_current_user();
        $settings = self::get_settings();
        $support_email = $settings['support_email'] ?: get_option( 'admin_email' );
        ?>
        <div class="wpfh-cp-support-card">
            <div class="wpfh-cp-support-header">
                <h3>💬 <?php esc_html_e( 'Customer Support Desk', 'wpfh-customer-portal' ); ?></h3>
                <p><?php esc_html_e( 'Have a question about installation, licensing, or feature requests? Send a message directly to our engineering team.', 'wpfh-customer-portal' ); ?></p>
            </div>

            <form method="post" action="mailto:<?php echo esc_attr( $support_email ); ?>" enctype="text/plain" class="wpfh-cp-support-form">
                <div class="wpfh-cp-form-group">
                    <label><?php esc_html_e( 'Your Name & Email Address', 'wpfh-customer-portal' ); ?></label>
                    <input type="text" readonly value="<?php echo esc_attr( $user->display_name . ' (' . $user->user_email . ')' ); ?>" class="regular-text" />
                </div>

                <div class="wpfh-cp-form-group">
                    <label><?php esc_html_e( 'Subject / Product Category', 'wpfh-customer-portal' ); ?></label>
                    <select name="subject" class="regular-text">
                        <option value="[Support Request] Digital Plugin Setup"><?php esc_html_e( 'Plugin Setup & Configuration', 'wpfh-customer-portal' ); ?></option>
                        <option value="[Billing] Order or License Assistance"><?php esc_html_e( 'Billing & License Assistance', 'wpfh-customer-portal' ); ?></option>
                        <option value="[Feature Request] Product Suggestion"><?php esc_html_e( 'Feature Request & Ideas', 'wpfh-customer-portal' ); ?></option>
                        <option value="[Other] General Question"><?php esc_html_e( 'General Inquiries', 'wpfh-customer-portal' ); ?></option>
                    </select>
                </div>

                <div class="wpfh-cp-form-group">
                    <label><?php esc_html_e( 'Message Details', 'wpfh-customer-portal' ); ?></label>
                    <textarea name="body" rows="6" placeholder="<?php esc_attr_e( 'Describe your issue, WordPress version, or question in detail...', 'wpfh-customer-portal' ); ?>" required></textarea>
                </div>

                <div class="wpfh-cp-form-submit">
                    <button type="submit" class="button button-primary">
                        🚀 <?php esc_html_e( 'Submit Support Ticket', 'wpfh-customer-portal' ); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Admin Settings Page.
     */
    public static function register_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Customer Portal Settings', 'wpfh-customer-portal' ),
            __( 'Customer Portal', 'wpfh-customer-portal' ),
            'manage_woocommerce',
            'wpfh-customer-portal',
            [ __CLASS__, 'render_admin_settings_page' ]
        );
    }

    public static function register_settings() {
        register_setting( 'wpfh_cp_settings_group', 'wpfh_cp_settings', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
        ] );
    }

    public static function sanitize_settings( $input ) {
        $clean = [];
        $clean['theme_mode']           = sanitize_key( $input['theme_mode'] ?? 'dark' );
        $clean['enable_magic_link']    = ! empty( $input['enable_magic_link'] ) ? 1 : 0;
        $clean['enable_auto_redirect'] = ! empty( $input['enable_auto_redirect'] ) ? 1 : 0;
        $clean['support_email']        = sanitize_email( $input['support_email'] ?? get_option( 'admin_email' ) );
        $clean['welcome_heading']      = sanitize_text_field( $input['welcome_heading'] ?? '' );
        $clean['welcome_subheading']   = sanitize_text_field( $input['welcome_subheading'] ?? '' );
        return $clean;
    }

    public static function render_admin_settings_page() {
        $settings = self::get_settings();
        $is_pro   = wpfh_cp_fs()->can_use_premium_code();
        ?>
        <div class="wrap wpfh-cp-admin-wrap">
            <h1><?php esc_html_e( 'WPForgeHub Customer Portal Settings', 'wpfh-customer-portal' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Configure the modern SaaS customer dashboard, passwordless magic links, and post-checkout download flow.', 'wpfh-customer-portal' ); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields( 'wpfh_cp_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wpfh_cp_theme_mode"><?php esc_html_e( 'Portal Theme Mode', 'wpfh-customer-portal' ); ?></label></th>
                        <td>
                            <select name="wpfh_cp_settings[theme_mode]" id="wpfh_cp_theme_mode">
                                <option value="dark" <?php selected( $settings['theme_mode'], 'dark' ); ?>><?php esc_html_e( 'Dark Slate (WPForgeHub Theme)', 'wpfh-customer-portal' ); ?></option>
                                <option value="light" <?php selected( $settings['theme_mode'], 'light' ); ?>><?php esc_html_e( 'Clean White (Classic)', 'wpfh-customer-portal' ); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Passwordless Magic Link', 'wpfh-customer-portal' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpfh_cp_settings[enable_magic_link]" value="1" <?php checked( $settings['enable_magic_link'], 1 ); ?> />
                                <?php esc_html_e( 'Enable 1-Click Passwordless Magic Link Login on the My Account page.', 'wpfh-customer-portal' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Post-Checkout Redirect', 'wpfh-customer-portal' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpfh_cp_settings[enable_auto_redirect]" value="1" <?php checked( $settings['enable_auto_redirect'], 1 ); ?> />
                                <?php esc_html_e( 'Automatically redirect customers to Downloads immediately upon order completion.', 'wpfh-customer-portal' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpfh_cp_support_email"><?php esc_html_e( 'Support Email Address', 'wpfh-customer-portal' ); ?></label></th>
                        <td>
                            <input name="wpfh_cp_settings[support_email]" type="email" id="wpfh_cp_support_email" value="<?php echo esc_attr( $settings['support_email'] ); ?>" class="regular-text" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpfh_cp_welcome_heading"><?php esc_html_e( 'Welcome Banner Heading', 'wpfh-customer-portal' ); ?></label></th>
                        <td>
                            <input name="wpfh_cp_settings[welcome_heading]" type="text" id="wpfh_cp_welcome_heading" value="<?php echo esc_attr( $settings['welcome_heading'] ); ?>" class="regular-text" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpfh_cp_welcome_subheading"><?php esc_html_e( 'Welcome Banner Subtext', 'wpfh-customer-portal' ); ?></label></th>
                        <td>
                            <input name="wpfh_cp_settings[welcome_subheading]" type="text" id="wpfh_cp_welcome_subheading" value="<?php echo esc_attr( $settings['welcome_subheading'] ); ?>" class="large-text" />
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
