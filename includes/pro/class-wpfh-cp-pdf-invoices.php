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

        $order_id = absint( $_GET['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_die( __( 'Invalid order ID.', 'forgehub-customer-portal' ) );
        }

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wpfh_download_invoice_' . $order_id ) ) {
            wp_die( __( 'Security token expired. Please refresh your account page.', 'forgehub-customer-portal' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( __( 'Order not found.', 'forgehub-customer-portal' ) );
        }

        $user_id = get_current_user_id();
        if ( ! current_user_can( 'manage_woocommerce' ) && (int) $order->get_customer_id() !== $user_id ) {
            wp_die( __( 'Access denied. You do not have permission to view this invoice.', 'forgehub-customer-portal' ) );
        }

        self::render_invoice_html( $order );
        exit;
    }

    /**
     * Render branded, print-optimized invoice HTML.
     */
    private static function render_invoice_html( $order ) {
        $order_id     = $order->get_id();
        $date         = $order->get_date_created() ? $order->get_date_created()->date( 'F j, Y' ) : date( 'F j, Y' );
        $billing_name = $order->get_formatted_billing_full_name() ?: 'Valued Customer';
        $email        = $order->get_billing_email();
        $country      = $order->get_billing_country();
        $vat_id       = $order->get_meta( '_billing_vat_id' ) ?: $order->get_meta( '_vat_number' );
        $license_key  = $order->get_meta( '_freemius_license_key' ) ?: $order->get_meta( '_wpfh_license_key' );
        $invoice_no   = 'INV-' . date( 'Y' ) . '-' . str_pad( (string) $order_id, 5, '0', STR_PAD_LEFT );
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title><?php echo esc_html( $invoice_no ); ?> — WPForgeHub Official Invoice</title>
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
                .badge-paid { display: inline-block; background: #dcfce7; color: #166534; font-weight: 800; font-size: 14px; padding: 6px 16px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 1px; border: 1px solid #bbf7d0; }
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
                    🖨️ Print / Save as PDF
                </button>
                <button onclick="window.close()" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:10px 16px; border-radius:6px; cursor:pointer;">
                    Close
                </button>
            </div>

            <div class="invoice-box">
                <div class="header">
                    <div class="brand">
                        <h1>WPForgeHub</h1>
                        <p>High-Performance WordPress Micro-Plugins<br>support@wpforgehub.com | https://wpforgehub.com</p>
                    </div>
                    <div class="inv-meta">
                        <h2>INVOICE</h2>
                        <p><strong>Invoice #:</strong> <?php echo esc_html( $invoice_no ); ?></p>
                        <p><strong>Order #:</strong> #<?php echo esc_html( $order_id ); ?></p>
                        <p><strong>Date:</strong> <?php echo esc_html( $date ); ?></p>
                        <p style="margin-top:6px;"><span class="badge-paid">✓ PAID</span></p>
                    </div>
                </div>

                <div class="parties">
                    <div>
                        <div class="party-title">Sold By</div>
                        <strong>WPForgeHub LLC</strong><br>
                        Digital Software Operations<br>
                        EU OSS / VAT Registry: EU372049281<br>
                        Tallinn, Estonia / Global CDN
                    </div>
                    <div>
                        <div class="party-title">Billed To</div>
                        <strong><?php echo esc_html( $billing_name ); ?></strong><br>
                        <?php echo esc_html( $email ); ?><br>
                        <?php if ( $order->get_billing_address_1() ) : ?>
                            <?php echo esc_html( $order->get_billing_address_1() ); ?><br>
                        <?php endif; ?>
                        Country: <?php echo esc_html( $country ); ?><br>
                        <?php if ( ! empty( $vat_id ) ) : ?>
                            <strong>VAT / Tax ID:</strong> <?php echo esc_html( $vat_id ); ?><br>
                        <?php endif; ?>
                    </div>
                </div>

                <table class="items">
                    <thead>
                        <tr>
                            <th>Item & Digital License</th>
                            <th style="text-align:center;">Qty</th>
                            <th style="text-align:right;">Price</th>
                            <th style="text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $order->get_items() as $item ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $item->get_name() ); ?></strong>
                                    <?php if ( ! empty( $license_key ) ) : ?>
                                        <div style="font-family:monospace; font-size:11px; color:#0284c7; margin-top:4px;">
                                            Key: <?php echo esc_html( $license_key ); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;"><?php echo esc_html( $item->get_quantity() ); ?></td>
                                <td style="text-align:right;"><?php echo wc_price( $item->get_subtotal() / max( 1, $item->get_quantity() ), [ 'currency' => $order->get_currency() ] ); ?></td>
                                <td style="text-align:right; font-weight:600;"><?php echo wc_price( $item->get_total(), [ 'currency' => $order->get_currency() ] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="totals">
                    <div class="totals-row">
                        <span>Subtotal:</span>
                        <span><?php echo wc_price( $order->get_subtotal(), [ 'currency' => $order->get_currency() ] ); ?></span>
                    </div>
                    <?php if ( (float) $order->get_total_tax() > 0 ) : ?>
                        <div class="totals-row">
                            <span>Tax / VAT:</span>
                            <span><?php echo wc_price( $order->get_total_tax(), [ 'currency' => $order->get_currency() ] ); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="totals-row grand">
                        <span>Total Paid:</span>
                        <span><?php echo wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ); ?></span>
                    </div>
                    <div style="font-size:12px; color:#64748b; margin-top:8px; text-align:right;">
                        Payment Gateway: <?php echo esc_html( $order->get_payment_method_title() ?: 'Stripe' ); ?>
                    </div>
                </div>

                <div class="footer">
                    <p>Thank you for choosing WPForgeHub software. For priority support, log in to your account at https://wpforgehub.com/my-account/support-tickets/.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
}
