<?php
/**
 * WPForgeHub Customer Portal — Pro Domain License Manager
 *
 * Provides customer-facing multi-site license delegation, active domain tracking,
 * and remote 1-click domain deactivation to release license activation seats.
 *
 * @package WPForgeHub\CustomerPortal\Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPFH_CP_Domain_Licenses {

    public static function init() {
        add_action( 'woocommerce_order_details_after_order_table', [ __CLASS__, 'render_domain_management_card' ], 20 );
        add_action( 'wp_ajax_wpfh_cp_deactivate_domain', [ __CLASS__, 'ajax_deactivate_domain' ] );
        add_action( 'wp_ajax_wpfh_cp_activate_domain', [ __CLASS__, 'ajax_activate_domain' ] );
    }

    /**
     * Get activated domains for an order.
     */
    public static function get_domains_for_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return [];
        }

        $domains = $order->get_meta( '_wpfh_activated_domains' );
        if ( ! is_array( $domains ) ) {
            // Default demo/seed domain if empty for testing
            $domains = [];
        }
        return $domains;
    }

    /**
     * Render domain management card inside order details.
     */
    public static function render_domain_management_card( $order ) {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $order_id = $order->get_id();
        $license_key = $order->get_meta( '_freemius_license_key' ) ?: $order->get_meta( '_wpfh_license_key' );
        
        // If license key not generated yet, provide mock for portal pro
        if ( empty( $license_key ) ) {
            $license_key = 'WPFH-' . strtoupper( substr( md5( 'order_' . $order_id ), 0, 16 ) );
        }

        $domains = self::get_domains_for_order( $order_id );
        $max_activations = absint( $order->get_meta( '_wpfh_license_quota' ) ?: 5 );
        $active_count    = count( $domains );
        ?>
        <div class="wpfh-cp-domain-manager-card" style="background:#0f172a; border:1px solid #1e293b; border-radius:12px; padding:24px; margin-top:28px;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #1e293b; padding-bottom:14px; margin-bottom:16px;">
                <div>
                    <h3 style="margin:0; color:#f8fafc; font-size:18px;">🌐 <?php esc_html_e( 'Active Domain Allocations (Pro License Manager)', 'wpfh-customer-portal' ); ?></h3>
                    <p style="margin:4px 0 0 0; color:#94a3b8; font-size:13px;"><?php esc_html_e( 'Manage domains connected to this license. Deactivate old staging or development domains to free up seats.', 'wpfh-customer-portal' ); ?></p>
                </div>
                <div style="text-align:right;">
                    <span style="background:#1e293b; color:#38bdf8; padding:6px 12px; border-radius:6px; font-weight:700; font-size:13px;">
                        <?php
                        /* translators: 1: Active domain activations count, 2: Maximum activations limit */
                        printf( esc_html__( '%1$d / %2$d Seats Used', 'wpfh-customer-portal' ), $active_count, $max_activations );
                        ?>
                    </span>
                </div>
            </div>

            <div id="wpfh-cp-domains-list-<?php echo esc_attr( $order_id ); ?>">
                <?php if ( empty( $domains ) ) : ?>
                    <p class="wpfh-no-domains-msg" style="color:#64748b; font-size:14px; font-style:italic;">
                        <?php esc_html_e( 'No domains are currently activated on this license key.', 'wpfh-customer-portal' ); ?>
                    </p>
                <?php else : ?>
                    <table style="width:100%; border-collapse:collapse; margin-bottom:16px;">
                        <thead>
                            <tr style="border-bottom:1px solid #334155; text-align:left;">
                                <th style="padding:8px 12px; color:#94a3b8; font-size:12px;"><?php esc_html_e( 'Domain Name', 'wpfh-customer-portal' ); ?></th>
                                <th style="padding:8px 12px; color:#94a3b8; font-size:12px;"><?php esc_html_e( 'Activated On', 'wpfh-customer-portal' ); ?></th>
                                <th style="padding:8px 12px; color:#94a3b8; font-size:12px; text-align:right;"><?php esc_html_e( 'Action', 'wpfh-customer-portal' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $domains as $index => $item ) :
                                $domain_name = is_array( $item ) ? ( $item['domain'] ?? '' ) : $item;
                                $date_added  = is_array( $item ) ? ( $item['date'] ?? 'N/A' ) : 'Active';
                                ?>
                                <tr style="border-bottom:1px solid #1e293b;" id="domain-row-<?php echo esc_attr( $order_id . '-' . $index ); ?>">
                                    <td style="padding:10px 12px; color:#38bdf8; font-family:monospace; font-size:14px;">
                                        🔗 <?php echo esc_html( $domain_name ); ?>
                                    </td>
                                    <td style="padding:10px 12px; color:#94a3b8; font-size:13px;">
                                        <?php echo esc_html( $date_added ); ?>
                                    </td>
                                    <td style="padding:10px 12px; text-align:right;">
                                        <button type="button" class="button wpfh-cp-deactivate-btn" 
                                                data-order-id="<?php echo esc_attr( $order_id ); ?>" 
                                                data-domain="<?php echo esc_attr( $domain_name ); ?>" 
                                                style="background:#3b1114; color:#f87171; border:1px solid #7f1d1d; border-radius:6px; font-size:12px; padding:4px 10px; cursor:pointer;">
                                            🗑️ <?php esc_html_e( 'Remote Deactivate', 'wpfh-customer-portal' ); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Manual Add Domain input for testing/delegation -->
            <div style="background:#131c2e; padding:12px 16px; border-radius:8px; display:flex; gap:10px; align-items:center;">
                <input type="text" id="wpfh-new-domain-<?php echo esc_attr( $order_id ); ?>" placeholder="e.g. example.com" style="flex:1; background:#1e293b; border:1px solid #334155; color:#f8fafc; padding:8px 12px; border-radius:6px; font-size:13px;" />
                <button type="button" class="button button-secondary wpfh-cp-add-domain-btn" data-order-id="<?php echo esc_attr( $order_id ); ?>" style="font-size:13px; font-weight:600;">
                    ➕ <?php esc_html_e( 'Register Domain', 'wpfh-customer-portal' ); ?>
                </button>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $(document).on('click', '.wpfh-cp-deactivate-btn', function(e) {
                e.preventDefault();
                var btn = $(this);
                var orderId = btn.data('order-id');
                var domain = btn.data('domain');

                if (!confirm('Are you sure you want to deactivate ' + domain + '?')) return;
                btn.prop('disabled', true).text('Deactivating...');

                $.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    action: 'wpfh_cp_deactivate_domain',
                    order_id: orderId,
                    domain: domain,
                    _ajax_nonce: '<?php echo wp_create_nonce( 'wpfh_cp_domain_nonce' ); ?>'
                }, function(res) {
                    if (res.success) {
                        btn.closest('tr').fadeOut(function() { $(this).remove(); });
                    } else {
                        alert(res.data || 'Failed to deactivate.');
                        btn.prop('disabled', false).text('Remote Deactivate');
                    }
                });
            });

            $(document).on('click', '.wpfh-cp-add-domain-btn', function(e) {
                e.preventDefault();
                var btn = $(this);
                var orderId = btn.data('order-id');
                var domainInput = $('#wpfh-new-domain-' + orderId);
                var domain = domainInput.val().trim();

                if (!domain) return;
                btn.prop('disabled', true);

                $.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    action: 'wpfh_cp_activate_domain',
                    order_id: orderId,
                    domain: domain,
                    _ajax_nonce: '<?php echo wp_create_nonce( 'wpfh_cp_domain_nonce' ); ?>'
                }, function(res) {
                    btn.prop('disabled', false);
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data || 'Failed to register domain.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    public static function ajax_deactivate_domain() {
        check_ajax_referer( 'wpfh_cp_domain_nonce' );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $domain   = sanitize_text_field( $_POST['domain'] ?? '' );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wpfh-customer-portal' ) );
        }

        $user_id = get_current_user_id();
        if ( (int) $order->get_customer_id() !== $user_id && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wpfh-customer-portal' ) );
        }

        $domains = $order->get_meta( '_wpfh_activated_domains' ) ?: [];
        $updated = [];
        $removed = false;

        foreach ( $domains as $item ) {
            $name = is_array( $item ) ? ( $item['domain'] ?? '' ) : $item;
            if ( strtolower( $name ) !== strtolower( $domain ) ) {
                $updated[] = $item;
            } else {
                $removed = true;
            }
        }

        if ( $removed ) {
            $order->update_meta_data( '_wpfh_activated_domains', $updated );
            $order->save();
            wp_send_json_success( [ 'message' => __( 'Domain deactivated.', 'wpfh-customer-portal' ), 'remaining' => count( $updated ) ] );
        }

        wp_send_json_error( __( 'Domain was not found in activation list.', 'wpfh-customer-portal' ) );
    }

    public static function ajax_activate_domain() {
        check_ajax_referer( 'wpfh_cp_domain_nonce' );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $domain   = sanitize_text_field( $_POST['domain'] ?? '' );
        $domain   = preg_replace( '#^https?://#', '', rtrim( $domain, '/' ) );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wpfh-customer-portal' ) );
        }

        $user_id = get_current_user_id();
        if ( (int) $order->get_customer_id() !== $user_id && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wpfh-customer-portal' ) );
        }

        $domains = $order->get_meta( '_wpfh_activated_domains' ) ?: [];
        $max_activations = absint( $order->get_meta( '_wpfh_license_quota' ) ?: 5 );

        if ( count( $domains ) >= $max_activations ) {
            /* translators: %d: Maximum license activation quota */
            wp_send_json_error( sprintf( __( 'Maximum license activation quota (%d) reached. Please upgrade or deactivate an unused domain.', 'wpfh-customer-portal' ), $max_activations ) );
        }

        // Add domain
        $domains[] = [
            'domain' => $domain,
            'date'   => current_time( 'Y-m-d H:i' ),
        ];

        $order->update_meta_data( '_wpfh_activated_domains', $domains );
        $order->save();

        wp_send_json_success( [ 'message' => __( 'Domain activated successfully.', 'wpfh-customer-portal' ) ] );
    }
}
