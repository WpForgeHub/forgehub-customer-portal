<?php
/**
 * WPForgeHub — Passwordless Magic Link Authentication Engine
 *
 * @package WPForgeHub\CustomerPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPFH_CP_Magic_Link {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'process_magic_link_auth' ] );
        add_action( 'woocommerce_login_form_end', [ __CLASS__, 'render_magic_link_box' ] );
        add_action( 'wp_ajax_nopriv_wpfh_cp_send_magic_link', [ __CLASS__, 'ajax_send_magic_link' ] );
        add_action( 'wp_ajax_wpfh_cp_send_magic_link', [ __CLASS__, 'ajax_send_magic_link' ] );
    }

    /**
     * Render Magic Link box on WooCommerce login screen.
     */
    public static function render_magic_link_box() {
        $settings = WPFH_CP_Portal::get_settings();
        if ( empty( $settings['enable_magic_link'] ) ) {
            return;
        }
        ?>
        <div class="wpfh-cp-magic-link-box">
            <h4>✨ <?php esc_html_e( 'Passwordless Magic Link Login', 'forgehub-customer-portal' ); ?></h4>
            <p><?php esc_html_e( 'Sign in without typing a password! Enter your account email below to receive an instant 1-click login link.', 'forgehub-customer-portal' ); ?></p>
            <div class="wpfh-cp-magic-input-group">
                <input type="email" id="wpfh_cp_magic_email" placeholder="<?php esc_attr_e( 'your-email@example.com', 'forgehub-customer-portal' ); ?>" />
                <button type="button" id="wpfh_cp_send_magic_btn" class="button">
                    <?php esc_html_e( 'Send Magic Link', 'forgehub-customer-portal' ); ?>
                </button>
            </div>
            <div id="wpfh_cp_magic_feedback" class="wpfh-cp-feedback-msg" style="display:none;"></div>
        </div>
        <?php
    }

    /**
     * AJAX handler to generate and dispatch magic link.
     */
    public static function ajax_send_magic_link() {
        check_ajax_referer( 'wpfh_cp_magic_nonce', 'security' );

        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'forgehub-customer-portal' ) ] );
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            // Security: Avoid email enumeration; return consistent success notification
            wp_send_json_success( [ 'message' => __( 'If an account exists with this email address, an instant login link has been dispatched! Please check your inbox.', 'forgehub-customer-portal' ) ] );
        }

        // Generate 24-byte cryptographic token
        $token     = bin2hex( random_bytes( 24 ) );
        $token_key = 'wpfh_cp_magic_' . hash( 'sha256', $token );

        // 15-minute expiration
        set_transient( $token_key, $user->ID, 15 * MINUTE_IN_SECONDS );

        $login_url = add_query_arg( [
            'wpfh_magic_token' => $token,
        ], wc_get_page_permalink( 'myaccount' ) );

        /* translators: %s: Site title / blog name */
        $subject = sprintf( __( '✨ Your 1-Click Login Link for %s', 'forgehub-customer-portal' ), get_bloginfo( 'name' ) );
        $message = sprintf(
            /* translators: 1: User display name, 2: Magic login URL, 3: Site title */
            __( "Hello %s,\n\nClick the secure link below to sign in instantly to your Customer Portal without a password:\n\n%s\n\nThis single-use link will expire in 15 minutes.\n\nWarm regards,\n%s", 'forgehub-customer-portal' ),
            $user->display_name,
            $login_url,
            get_bloginfo( 'name' )
        );

        wp_mail( $email, $subject, $message, [ 'Content-Type: text/plain; charset=UTF-8' ] );

        wp_send_json_success( [ 'message' => __( '✨ Instant magic link sent! Please check your email inbox to log in with 1 click.', 'forgehub-customer-portal' ) ] );
    }

    /**
     * Authenticate user from URL token.
     */
    public static function process_magic_link_auth() {
        if ( empty( $_GET['wpfh_magic_token'] ) ) {
            return;
        }

        $token     = sanitize_text_field( wp_unslash( $_GET['wpfh_magic_token'] ) );
        $token_key = 'wpfh_cp_magic_' . hash( 'sha256', $token );
        $user_id   = get_transient( $token_key );

        if ( $user_id && ( $user = get_user_by( 'id', $user_id ) ) ) {
            delete_transient( $token_key );

            wp_set_current_user( $user->ID );
            wp_set_auth_cookie( $user->ID, true );
            do_action( 'wp_login', $user->user_login, $user );

            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        } else {
            wp_die(
                esc_html__( 'This login link has expired or has already been used. Please request a new link from the login page.', 'forgehub-customer-portal' ),
                esc_html__( 'Login Link Expired', 'forgehub-customer-portal' ),
                [ 'response' => 403, 'back_link' => true ]
            );
        }
    }
}
