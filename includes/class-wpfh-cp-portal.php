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
        add_action( 'template_redirect', [ __CLASS__, 'post_checkout_redirect_to_downloads' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_free_support_submission' ] );
        add_action( 'woocommerce_order_details_after_order_table', [ __CLASS__, 'display_license_in_order_details' ] );
        add_filter( 'body_class', [ __CLASS__, 'add_theme_mode_body_class' ] );

        // Admin settings
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_menu' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( WPFH_CP_FILE ), [ __CLASS__, 'add_action_links' ] );
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
            'company_name'         => get_bloginfo( 'name' ),
            'company_tagline'      => get_bloginfo( 'description' ),
            'company_address'      => '',
            'company_vat'          => '',
            'invoice_footer'       => '',
            'welcome_heading'      => __( '🎉 Order Confirmed! Your Downloads Are Ready Below.', 'forgehub-customer-portal' ),
            'welcome_subheading'   => __( 'Thank you for your order! You can download your software files below and access them anytime from your account.', 'forgehub-customer-portal' ),
        ];
    }

    public static function get_settings() {
        return wp_parse_args( get_option( 'wpfh_cp_settings', [] ), self::get_default_settings() );
    }

    public static function add_portal_endpoints() {
        add_rewrite_endpoint( 'support-tickets', EP_ROOT | EP_PAGES );
    }

    public static function add_theme_mode_body_class( $classes ) {
        if ( is_account_page() || is_checkout() ) {
            $settings = self::get_settings();
            $mode     = ( 'light' === ( $settings['theme_mode'] ?? 'dark' ) ) ? 'light' : 'dark';
            $classes[] = 'wpfh-cp-theme-' . $mode;
        }
        return $classes;
    }

    public static function customize_account_menu_items( $items ) {
        $core_labels = [
            'dashboard'       => __( '📊 Dashboard', 'forgehub-customer-portal' ),
            'downloads'       => __( '📥 Downloads (.zip)', 'forgehub-customer-portal' ),
            'orders'          => __( '📦 Orders & Licenses', 'forgehub-customer-portal' ),
            'edit-account'    => __( '👤 Profile & Password', 'forgehub-customer-portal' ),
        ];

        $reordered = [];
        foreach ( $core_labels as $key => $label ) {
            if ( isset( $items[ $key ] ) || 'downloads' === $key ) {
                $reordered[ $key ] = $label;
            }
        }

        // Preserve any third-party plugin tabs (e.g. subscriptions, memberships, addresses).
        $skip = array_merge( array_keys( $core_labels ), [ 'support-tickets', 'customer-logout' ] );
        foreach ( $items as $key => $label ) {
            if ( ! in_array( $key, $skip, true ) ) {
                $reordered[ $key ] = $label;
            }
        }

        $reordered['support-tickets'] = __( '💬 Support Desk', 'forgehub-customer-portal' );

        if ( isset( $items['customer-logout'] ) ) {
            $reordered['customer-logout'] = __( '🚪 Log Out', 'forgehub-customer-portal' );
        }

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
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'magic_nonce' => wp_create_nonce( 'wpfh_cp_magic_nonce' ),
            'i18n'        => [
                'copied' => __( 'Copied to clipboard!', 'forgehub-customer-portal' ),
            ],
        ] );
    }

    /**
     * Post-checkout instant redirect to Customer Downloads (runs on template_redirect before headers are sent).
     */
    public static function post_checkout_redirect_to_downloads() {
        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }

        $settings = self::get_settings();
        if ( empty( $settings['enable_auto_redirect'] ) ) {
            return;
        }

        if ( ! is_user_logged_in() || isset( $_GET['noredirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
            return;
        }

        $order_id = absint( get_query_var( 'order-received' ) );
        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( (int) $order->get_customer_id() !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( ! $order->has_downloadable_item() ) {
            return;
        }

        $downloads_url = add_query_arg( [
            'order_completed' => $order_id,
            'welcome'         => '1',
        ], wc_get_endpoint_url( 'downloads', '', wc_get_page_permalink( 'myaccount' ) ) );

        wp_safe_redirect( $downloads_url );
        exit;
    }

    /**
     * Handle Free Support Desk form submission via wp_mail().
     */
    public static function handle_free_support_submission() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        if ( empty( $_POST['wpfh_cp_free_support'] ) ) {
            return;
        }

        check_admin_referer( 'wpfh_cp_free_support_action', 'wpfh_cp_free_support_nonce' );

        $user          = wp_get_current_user();
        $settings      = self::get_settings();
        $support_email = is_email( $settings['support_email'] ) ? $settings['support_email'] : get_option( 'admin_email' );
        $subject       = sanitize_text_field( wp_unslash( $_POST['subject'] ?? __( '[Support Request]', 'forgehub-customer-portal' ) ) );
        $body_text     = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );

        if ( empty( $body_text ) ) {
            wc_add_notice( __( 'Please enter your message details before submitting.', 'forgehub-customer-portal' ), 'error' );
            return;
        }

        $mail_body = sprintf(
            /* translators: 1: Customer name, 2: Customer email, 3: Message body */
            __( "Customer: %1\$s (%2\$s)\n\nMessage:\n%3\$s", 'forgehub-customer-portal' ),
            $user->display_name,
            $user->user_email,
            $body_text
        );

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $user->display_name . ' <' . $user->user_email . '>',
        ];

        $sent = wp_mail( $support_email, $subject, $mail_body, $headers );
        if ( $sent ) {
            wc_add_notice( __( 'Your support message has been sent. Our team will reply by email shortly.', 'forgehub-customer-portal' ), 'success' );
        } else {
            wc_add_notice( __( 'Unable to send your message right now. Please try again later.', 'forgehub-customer-portal' ), 'error' );
        }

        wp_safe_redirect( wc_get_endpoint_url( 'support-tickets', '', wc_get_page_permalink( 'myaccount' ) ) );
        exit;
    }

    /**
     * Welcome banner on /my-account/downloads/.
     */
    public static function render_welcome_download_banner() {
        if ( isset( $_GET['welcome'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
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
                <h4>🔑 <?php esc_html_e( 'Your Product License Key', 'forgehub-customer-portal' ); ?></h4>
                <p><?php esc_html_e( 'Use this license key inside your WordPress admin dashboard to activate Pro features and automated updates.', 'forgehub-customer-portal' ); ?></p>
                <div class="wpfh-cp-license-key-wrap">
                    <code><?php echo esc_html( $license_key ); ?></code>
                    <button type="button" class="button wpfh-cp-copy-key-btn" data-key="<?php echo esc_attr( $license_key ); ?>">
                        <?php esc_html_e( 'Copy Key', 'forgehub-customer-portal' ); ?>
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
                <h3>💬 <?php esc_html_e( 'Customer Support Desk', 'forgehub-customer-portal' ); ?></h3>
                <p><?php esc_html_e( 'Have a question about installation, licensing, or feature requests? Send a message directly to our engineering team.', 'forgehub-customer-portal' ); ?></p>
            </div>

            <form method="post" action="" class="wpfh-cp-support-form">
                <input type="hidden" name="wpfh_cp_free_support" value="1" />
                <?php wp_nonce_field( 'wpfh_cp_free_support_action', 'wpfh_cp_free_support_nonce' ); ?>
                <div class="wpfh-cp-form-group">
                    <label><?php esc_html_e( 'Your Name & Email Address', 'forgehub-customer-portal' ); ?></label>
                    <input type="text" readonly value="<?php echo esc_attr( $user->display_name . ' (' . $user->user_email . ')' ); ?>" class="regular-text" />
                </div>

                <div class="wpfh-cp-form-group">
                    <label><?php esc_html_e( 'Subject / Product Category', 'forgehub-customer-portal' ); ?></label>
                    <select name="subject" class="regular-text">
                        <option value="[Support Request] Digital Plugin Setup"><?php esc_html_e( 'Plugin Setup & Configuration', 'forgehub-customer-portal' ); ?></option>
                        <option value="[Billing] Order or License Assistance"><?php esc_html_e( 'Billing & License Assistance', 'forgehub-customer-portal' ); ?></option>
                        <option value="[Feature Request] Product Suggestion"><?php esc_html_e( 'Feature Request & Ideas', 'forgehub-customer-portal' ); ?></option>
                        <option value="[Other] General Question"><?php esc_html_e( 'General Inquiries', 'forgehub-customer-portal' ); ?></option>
                    </select>
                </div>

                <div class="wpfh-cp-form-group">
                    <label><?php esc_html_e( 'Message Details', 'forgehub-customer-portal' ); ?></label>
                    <textarea name="body" rows="6" placeholder="<?php esc_attr_e( 'Describe your issue, WordPress version, or question in detail...', 'forgehub-customer-portal' ); ?>" required></textarea>
                </div>

                <div class="wpfh-cp-form-submit">
                    <button type="submit" class="button button-primary">
                        🚀 <?php esc_html_e( 'Submit Support Ticket', 'forgehub-customer-portal' ); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Admin Settings Page.
     */
    /**
     * Settings link on the Plugins screen.
     *
     * @param array $links Existing action links.
     * @return array
     */
    public static function add_action_links( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=forgehub-customer-portal' ) ) . '">' . esc_html__( 'Settings', 'forgehub-customer-portal' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public static function register_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Customer Portal Settings', 'forgehub-customer-portal' ),
            __( 'Customer Portal', 'forgehub-customer-portal' ),
            'manage_woocommerce',
            'forgehub-customer-portal',
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
        $clean['theme_mode']           = in_array( $input['theme_mode'] ?? 'dark', [ 'dark', 'light' ], true ) ? $input['theme_mode'] : 'dark';
        $clean['enable_magic_link']    = ! empty( $input['enable_magic_link'] ) ? 1 : 0;
        $clean['enable_auto_redirect'] = ! empty( $input['enable_auto_redirect'] ) ? 1 : 0;
        $clean['support_email']        = sanitize_email( $input['support_email'] ?? get_option( 'admin_email' ) );
        $clean['company_name']         = sanitize_text_field( $input['company_name'] ?? get_bloginfo( 'name' ) );
        $clean['company_tagline']      = sanitize_text_field( $input['company_tagline'] ?? '' );
        $clean['company_address']      = sanitize_textarea_field( $input['company_address'] ?? '' );
        $clean['company_vat']          = sanitize_text_field( $input['company_vat'] ?? '' );
        $clean['invoice_footer']       = sanitize_text_field( $input['invoice_footer'] ?? '' );
        $clean['welcome_heading']      = sanitize_text_field( $input['welcome_heading'] ?? '' );
        $clean['welcome_subheading']   = sanitize_text_field( $input['welcome_subheading'] ?? '' );
        return $clean;
    }

    public static function render_admin_settings_page() {
        $settings = self::get_settings();
        $is_pro   = wpfh_cp_fs()->can_use_premium_code();
        ?>
        <div class="wrap wpfh-cp-admin-wrap">
            <h1><?php esc_html_e( 'Customer Portal Settings', 'forgehub-customer-portal' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Configure the modern SaaS customer dashboard, passwordless magic links, and post-checkout download flow.', 'forgehub-customer-portal' ); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields( 'wpfh_cp_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wpfh_cp_theme_mode"><?php esc_html_e( 'Portal Theme Mode', 'forgehub-customer-portal' ); ?></label></th>
                        <td>
                            <select name="wpfh_cp_settings[theme_mode]" id="wpfh_cp_theme_mode">
                                <option value="dark" <?php selected( $settings['theme_mode'], 'dark' ); ?>><?php esc_html_e( 'Dark Slate', 'forgehub-customer-portal' ); ?></option>
                                <option value="light" <?php selected( $settings['theme_mode'], 'light' ); ?>><?php esc_html_e( 'Clean White (Classic)', 'forgehub-customer-portal' ); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Passwordless Magic Link', 'forgehub-customer-portal' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpfh_cp_settings[enable_magic_link]" value="1" <?php checked( $settings['enable_magic_link'], 1 ); ?> />
                                <?php esc_html_e( 'Enable 1-Click Passwordless Magic Link Login on the My Account page.', 'forgehub-customer-portal' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Post-Checkout Redirect', 'forgehub-customer-portal' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpfh_cp_settings[enable_auto_redirect]" value="1" <?php checked( $settings['enable_auto_redirect'], 1 ); ?> />
                                <?php esc_html_e( 'Automatically redirect customers to Downloads immediately upon order completion.', 'forgehub-customer-portal' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpfh_cp_support_email"><?php esc_html_e( 'Support Email Address', 'forgehub-customer-portal' ); ?></label></th>
                        <td>
                            <input name="wpfh_cp_settings[support_email]" type="email" id="wpfh_cp_support_email" value="<?php echo esc_attr( $settings['support_email'] ); ?>" class="regular-text" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpfh_cp_welcome_heading"><?php esc_html_e( 'Welcome Banner Heading', 'forgehub-customer-portal' ); ?></label></th>
                        <td>
                            <input name="wpfh_cp_settings[welcome_heading]" type="text" id="wpfh_cp_welcome_heading" value="<?php echo esc_attr( $settings['welcome_heading'] ); ?>" class="regular-text" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpfh_cp_welcome_subheading"><?php esc_html_e( 'Welcome Banner Subtext', 'forgehub-customer-portal' ); ?></label></th>
                        <td>
                            <input name="wpfh_cp_settings[welcome_subheading]" type="text" id="wpfh_cp_welcome_subheading" value="<?php echo esc_attr( $settings['welcome_subheading'] ); ?>" class="large-text" />
                        </td>
                    </tr>

                    <?php if ( $is_pro ) : ?>
                    <tr>
                        <th scope="row"><label for="wpfh_cp_company_name"><?php esc_html_e( 'Invoice Company Name', 'forgehub-customer-portal' ); ?></label></th>
                        <td>
                            <input name="wpfh_cp_settings[company_name]" type="text" id="wpfh_cp_company_name" value="<?php echo esc_attr( $settings['company_name'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpfh_cp_company_tagline"><?php esc_html_e( 'Invoice Tagline', 'forgehub-customer-portal' ); ?></label></th>
                        <td>
                            <input name="wpfh_cp_settings[company_tagline]" type="text" id="wpfh_cp_company_tagline" value="<?php echo esc_attr( $settings['company_tagline'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpfh_cp_company_address"><?php esc_html_e( 'Invoice Business Address', 'forgehub-customer-portal' ); ?></label></th>
                        <td>
                            <textarea name="wpfh_cp_settings[company_address]" id="wpfh_cp_company_address" rows="3" class="regular-text"><?php echo esc_textarea( $settings['company_address'] ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpfh_cp_company_vat"><?php esc_html_e( 'Business VAT / Tax ID', 'forgehub-customer-portal' ); ?></label></th>
                        <td>
                            <input name="wpfh_cp_settings[company_vat]" type="text" id="wpfh_cp_company_vat" value="<?php echo esc_attr( $settings['company_vat'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpfh_cp_invoice_footer"><?php esc_html_e( 'Invoice Footer Note', 'forgehub-customer-portal' ); ?></label></th>
                        <td>
                            <input name="wpfh_cp_settings[invoice_footer]" type="text" id="wpfh_cp_invoice_footer" value="<?php echo esc_attr( $settings['invoice_footer'] ); ?>" class="large-text" />
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
