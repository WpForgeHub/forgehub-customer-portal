<?php
/**
 * WPForgeHub Customer Portal — Pro Automated PDF & Printable Invoice Generator
 *
 * Renders high-compliance, branded invoices & receipts with EU VAT data,
 * license key breakdowns, company details, and instant 1-click download.
 *
 * @package WPForgeHub\CustomerPortal\Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPFH_CP_PDF_Invoices {

    public static function init() {
        add_filter( 'woocommerce_my_account_my_orders_actions', [ __CLASS__, 'add_invoice_action_button' ], 10, 2 );
        add_action( 'woocommerce_order_details_after_order_table', [ __CLASS__, 'render_order_details_invoice_button' ], 25 );
        add_action( 'template_redirect', [ __CLASS__, 'handle_invoice_download_request' ] );
    }

    /**
     * Add "Invoice" action button in /my-account/orders/
     */
    public static function add_invoice_action_button( $actions, $order ) {
        $order_id = $order->get_id();
        $invoice_url = wp_nonce_url( add_query_arg( [
            'wpfh_action' => 'download_invoice',
            'order_id'    => $order_id,
        ], home_url( '/' ) ), 'wpfh_download_invoice_' . $order_id );

        $actions['wpfh_invoice'] = [
            'url'  => $invoice_url,
            'name' => __( '📄 Invoice', 'forgehub-customer-portal' ),
        ];

        return $actions;
    }

    /**
     * Render button inside single order view.
     */
    public static function render_order_details_invoice_button( $order ) {
        $order_id = $order->get_id();
        $invoice_url = wp_nonce_url( add_query_arg( [
            'wpfh_action' => 'download_invoice',
            'order_id'    => $order_id,
        ], home_url( '/' ) ), 'wpfh_download_invoice_' . $order_id );
        ?>
        <div style="margin-top:16px; margin-bottom:16px;">
            <a href="<?php echo esc_url( $invoice_url ); ?>" target="_blank" class="button" style="background:#1e293b; color:#38bdf8; border:1px solid #38bdf8; border-radius:6px; font-weight:600; padding:8px 16px;">
                📄 <?php esc_html_e( 'Download Official PDF Invoice', 'forgehub-customer-portal' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Catch invoice request and render printable invoice.
     */
    public static function handle_invoice_download_request() {
        if ( ! isset( $_GET['wpfh_action'] ) || $_GET['wpfh_action'] !== 'download_invoice' ) {
            return;
        }

        $order_id = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below.
        if ( ! $order_id ) {
            wp_die( esc_html__( 'Invalid order ID.', 'forgehub-customer-portal' ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'wpfh_download_invoice_' . $order_id ) ) {
            wp_die( esc_html__( 'Security token expired. Please refresh your account page.', 'forgehub-customer-portal' ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Please sign in to access your invoice.', 'forgehub-customer-portal' ), esc_html__( 'Authentication Required', 'forgehub-customer-portal' ), [ 'response' => 403 ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'forgehub-customer-portal' ) );
        }

        $user_id     = get_current_user_id();
        $customer_id = (int) $order->get_customer_id();
        if ( ! current_user_can( 'manage_woocommerce' ) && ( $customer_id <= 0 || $customer_id !== $user_id ) ) {
            wp_die( esc_html__( 'Access denied. You do not have permission to view this invoice.', 'forgehub-customer-portal' ), esc_html__( 'Access Denied', 'forgehub-customer-portal' ), [ 'response' => 403 ] );
        }

        self::render_invoice_html( $order );
        exit;
    }

    /**
     * Render branded, print-optimized invoice HTML.
     */
    private static function render_invoice_html( $order ) {
        $settings     = WPFH_CP_Portal::get_settings();
        $order_id     = $order->get_id();
        $created      = $order->get_date_created();
        $date         = $created ? $created->date( 'F j, Y' ) : gmdate( 'F j, Y' );
        $order_year   = $created ? $created->date( 'Y' ) : gmdate( 'Y' );
        $billing_name = $order->get_formatted_billing_full_name() ?: __( 'Valued Customer', 'forgehub-customer-portal' );
        $email        = $order->get_billing_email();
        $country      = $order->get_billing_country();
        $vat_id       = $order->get_meta( '_billing_vat_id' ) ?: $order->get_meta( '_vat_number' );
        $license_key  = $order->get_meta( '_freemius_license_key' ) ?: $order->get_meta( '_wpfh_license_key' );
        $invoice_no   = 'INV-' . $order_year . '-' . str_pad( (string) $order_id, 5, '0', STR_PAD_LEFT );

        // Dynamic store / seller metadata (zero hardcoded brand details).
        $store_name    = ! empty( $settings['company_name'] ) ? $settings['company_name'] : get_bloginfo( 'name' );
        $store_tagline = ! empty( $settings['company_tagline'] ) ? $settings['company_tagline'] : get_bloginfo( 'description' );
        $store_email   = ! empty( $settings['support_email'] ) ? $settings['support_email'] : get_option( 'admin_email' );
        $store_url     = home_url( '/' );
        $store_vat     = $settings['company_vat'] ?? '';
        $store_address = $settings['company_address'] ?? '';
        if ( empty( $store_address ) && function_exists( 'WC' ) && isset( WC()->countries ) ) {
            $addr_parts = array_filter( [
                get_option( 'woocommerce_store_address', '' ),
                get_option( 'woocommerce_store_city', '' ),
                WC()->countries->get_base_country(),
            ] );
            $store_address = implode( ', ', $addr_parts );
        }
        $support_url   = function_exists( 'wc_get_endpoint_url' ) ? wc_get_endpoint_url( 'support-tickets', '', wc_get_page_permalink( 'myaccount' ) ) : $store_url;
        $footer_note   = ! empty( $settings['invoice_footer'] )
            ? $settings['invoice_footer']
            : sprintf(
                /* translators: 1: Store name, 2: Support portal URL */
                __( 'Thank you for your order with %1$s. For support, visit %2$s.', 'forgehub-customer-portal' ),
                $store_name,
                $support_url
            );

        // Accurate order payment status badge.
        $order_status = $order->get_status();
        if ( 'refunded' === $order_status ) {
            $status_label = __( 'REFUNDED', 'forgehub-customer-portal' );
            $status_style = 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;';
        } elseif ( in_array( $order_status, [ 'cancelled', 'failed' ], true ) ) {
            $status_label = strtoupper( wc_get_order_status_name( $order_status ) );
            $status_style = 'background:#fee2e2; color:#991b1b; border:1px solid #fecaca;';
        } elseif ( $order->is_paid() ) {
            $status_label = __( '✓ PAID', 'forgehub-customer-portal' );
            $status_style = 'background:#dcfce7; color:#166534; border:1px solid #bbf7d0;';
        } else {
            $status_label = strtoupper( wc_get_order_status_name( $order_status ) );
            $status_style = 'background:#e0f2fe; color:#075985; border:1px solid #bae6fd;';
        }
        ?>
        <!DOCTYPE html>
        <html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
        <head>
            <meta charset="UTF-8">
            <title><?php echo esc_html( $invoice_no . ' — ' . $store_name ); ?></title>
            <style>
                @page { size: A4; margin: 20mm; }
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1e293b; margin: 0; padding: 40px; background: #fff; line-height: 1.5; font-size: 14px; }
                .invoice-box { max-width: 800px; margin: auto; }
                .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f172a; padding-bottom: 20px; margin-bottom: 30px; }
                .brand h1 { margin: 0; font-size: 26px; color: #0f172a; font-weight: 800; letter-spacing: -0.5px; }
                .brand p { margin: 4px 0 0 0; color: #64748b; font-size: 13px; }
                .inv-meta { text-align: right; }
                .inv-meta h2 { margin: 0; font-size: 20px; color: #0284c7; }
                .inv-meta p { margin: 3px 0 0 0; color: #475569; font-size: 13px; }
                .parties { display: flex; justify-content: space-between; margin-bottom: 30px; }
                .parties > div { flex: 1; }
                .party-title { font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; color: #94a3b8; margin-bottom: 6px; }
                table.items { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
                table.items th { background: #f8fafc; border-bottom: 2px solid #cbd5e1; text-align: left; padding: 12px; font-size: 12px; text-transform: uppercase; color: #475569; }
                table.items td { padding: 14px 12px; border-bottom: 1px solid #e2e8f0; }
                .totals { margin-left: auto; width: 320px; }
                .totals-row { display: flex; justify-content: space-between; padding: 6px 0; }
                .totals-row.grand { font-size: 18px; font-weight: 800; border-top: 2px solid #0f172a; padding-top: 10px; margin-top: 6px; color: #0f172a; }
                .badge-status { display: inline-block; font-weight: 800; font-size: 13px; padding: 6px 14px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.8px; }
                .footer { margin-top: 50px; border-top: 1px solid #e2e8f0; padding-top: 20px; font-size: 12px; color: #94a3b8; text-align: center; }
                @media print {
                    body { padding: 0; }
                    .no-print { display: none !important; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="max-width: 800px; margin: 0 auto 20px auto; display: flex; justify-content: space-between;">
                <button onclick="window.print()" style="background:#0284c7; color:#fff; border:none; padding:10px 20px; border-radius:6px; font-weight:600; cursor:pointer; font-size:14px;">
                    🖨️ <?php esc_html_e( 'Print / Save as PDF', 'forgehub-customer-portal' ); ?>
                </button>
                <button onclick="window.close()" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:10px 16px; border-radius:6px; cursor:pointer;">
                    <?php esc_html_e( 'Close', 'forgehub-customer-portal' ); ?>
                </button>
            </div>

            <div class="invoice-box">
                <div class="header">
                    <div class="brand">
                        <h1><?php echo esc_html( $store_name ); ?></h1>
                        <p>
                            <?php if ( ! empty( $store_tagline ) ) : ?>
                                <?php echo esc_html( $store_tagline ); ?><br>
                            <?php endif; ?>
                            <?php echo esc_html( $store_email ); ?> | <?php echo esc_html( $store_url ); ?>
                        </p>
                    </div>
                    <div class="inv-meta">
                        <h2><?php esc_html_e( 'INVOICE', 'forgehub-customer-portal' ); ?></h2>
                        <p><strong><?php esc_html_e( 'Invoice #:', 'forgehub-customer-portal' ); ?></strong> <?php echo esc_html( $invoice_no ); ?></p>
                        <p><strong><?php esc_html_e( 'Order #:', 'forgehub-customer-portal' ); ?></strong> #<?php echo esc_html( $order_id ); ?></p>
                        <p><strong><?php esc_html_e( 'Date:', 'forgehub-customer-portal' ); ?></strong> <?php echo esc_html( $date ); ?></p>
                        <p style="margin-top:6px;"><span class="badge-status" style="<?php echo esc_attr( $status_style ); ?>"><?php echo esc_html( $status_label ); ?></span></p>
                    </div>
                </div>

                <div class="parties">
                    <div>
                        <div class="party-title"><?php esc_html_e( 'Sold By', 'forgehub-customer-portal' ); ?></div>
                        <strong><?php echo esc_html( $store_name ); ?></strong><br>
                        <?php if ( ! empty( $store_address ) ) : ?>
                            <?php echo nl2br( esc_html( $store_address ) ); ?><br>
                        <?php endif; ?>
                        <?php if ( ! empty( $store_vat ) ) : ?>
                            <?php esc_html_e( 'VAT / Tax ID:', 'forgehub-customer-portal' ); ?> <?php echo esc_html( $store_vat ); ?><br>
                        <?php endif; ?>
                        <?php echo esc_html( $store_email ); ?>
                    </div>
                    <div>
                        <div class="party-title"><?php esc_html_e( 'Billed To', 'forgehub-customer-portal' ); ?></div>
                        <strong><?php echo esc_html( $billing_name ); ?></strong><br>
                        <?php echo esc_html( $email ); ?><br>
                        <?php if ( $order->get_billing_address_1() ) : ?>
                            <?php echo esc_html( $order->get_billing_address_1() ); ?><br>
                        <?php endif; ?>
                        <?php if ( ! empty( $country ) ) : ?>
                            <?php esc_html_e( 'Country:', 'forgehub-customer-portal' ); ?> <?php echo esc_html( $country ); ?><br>
                        <?php endif; ?>
                        <?php if ( ! empty( $vat_id ) ) : ?>
                            <strong><?php esc_html_e( 'VAT / Tax ID:', 'forgehub-customer-portal' ); ?></strong> <?php echo esc_html( $vat_id ); ?><br>
                        <?php endif; ?>
                    </div>
                </div>

                <table class="items">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Item', 'forgehub-customer-portal' ); ?></th>
                            <th style="text-align:center;"><?php esc_html_e( 'Qty', 'forgehub-customer-portal' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Price', 'forgehub-customer-portal' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Total', 'forgehub-customer-portal' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $order->get_items() as $item ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $item->get_name() ); ?></strong>
                                    <?php if ( ! empty( $license_key ) ) : ?>
                                        <div style="font-family:monospace; font-size:11px; color:#0284c7; margin-top:4px;">
                                            <?php esc_html_e( 'Key:', 'forgehub-customer-portal' ); ?> <?php echo esc_html( $license_key ); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;"><?php echo esc_html( $item->get_quantity() ); ?></td>
                                <td style="text-align:right;"><?php echo wp_kses_post( wc_price( $item->get_subtotal() / max( 1, $item->get_quantity() ), [ 'currency' => $order->get_currency() ] ) ); ?></td>
                                <td style="text-align:right; font-weight:600;"><?php echo wp_kses_post( wc_price( $item->get_total(), [ 'currency' => $order->get_currency() ] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="totals">
                    <div class="totals-row">
                        <span><?php esc_html_e( 'Subtotal:', 'forgehub-customer-portal' ); ?></span>
                        <span><?php echo wp_kses_post( wc_price( $order->get_subtotal(), [ 'currency' => $order->get_currency() ] ) ); ?></span>
                    </div>
                    <?php if ( (float) $order->get_total_tax() > 0 ) : ?>
                        <div class="totals-row">
                            <span><?php esc_html_e( 'Tax / VAT:', 'forgehub-customer-portal' ); ?></span>
                            <span><?php echo wp_kses_post( wc_price( $order->get_total_tax(), [ 'currency' => $order->get_currency() ] ) ); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="totals-row grand">
                        <span><?php echo $order->is_paid() ? esc_html__( 'Total Paid:', 'forgehub-customer-portal' ) : esc_html__( 'Total:', 'forgehub-customer-portal' ); ?></span>
                        <span><?php echo wp_kses_post( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ); ?></span>
                    </div>
                    <div style="font-size:12px; color:#64748b; margin-top:8px; text-align:right;">
                        <?php esc_html_e( 'Payment Method:', 'forgehub-customer-portal' ); ?> <?php echo esc_html( $order->get_payment_method_title() ?: __( 'N/A', 'forgehub-customer-portal' ) ); ?>
                    </div>
                </div>

                <div class="footer">
                    <p><?php echo esc_html( $footer_note ); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
}
