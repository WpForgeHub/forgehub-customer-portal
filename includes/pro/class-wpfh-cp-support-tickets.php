<?php
/**
 * WPForgeHub Customer Portal — Pro Support Desk Controller
 *
 * Implements full database-backed support ticketing, priority tiers,
 * threaded conversation history, secure attachments, and status management.
 *
 * @package WPForgeHub\CustomerPortal\Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPFH_CP_Support_Tickets {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
        add_action( 'init', [ __CLASS__, 'handle_ticket_actions' ] );

        // Replace Free static form with Pro interactive dashboard
        remove_action( 'woocommerce_account_support-tickets_endpoint', [ 'WPFH_CP_Portal', 'render_support_tickets_endpoint' ] );
        add_action( 'woocommerce_account_support-tickets_endpoint', [ __CLASS__, 'render_portal_tickets' ] );

        // Admin columns for tickets
        add_filter( 'manage_wpfh_ticket_posts_columns', [ __CLASS__, 'admin_columns' ] );
        add_action( 'manage_wpfh_ticket_posts_custom_column', [ __CLASS__, 'admin_column_content' ], 10, 2 );
    }

    public static function register_post_type() {
        register_post_type( 'wpfh_ticket', [
            'labels' => [
                'name'               => __( 'Support Tickets', 'wpfh-customer-portal' ),
                'singular_name'      => __( 'Support Ticket', 'wpfh-customer-portal' ),
                'menu_name'          => __( 'Support Tickets', 'wpfh-customer-portal' ),
                'add_new'            => __( 'New Ticket', 'wpfh-customer-portal' ),
                'add_new_item'       => __( 'Create Support Ticket', 'wpfh-customer-portal' ),
                'edit_item'          => __( 'Edit Support Ticket', 'wpfh-customer-portal' ),
                'view_item'          => __( 'View Support Ticket', 'wpfh-customer-portal' ),
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'woocommerce',
            'capability_type'     => 'post',
            'hierarchical'        => false,
            'supports'            => [ 'title', 'editor', 'comments', 'author' ],
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
        ] );
    }

    public static function admin_columns( $cols ) {
        return [
            'cb'          => '<input type="checkbox" />',
            'title'       => __( 'Subject', 'wpfh-customer-portal' ),
            'customer'    => __( 'Customer', 'wpfh-customer-portal' ),
            'status'      => __( 'Status', 'wpfh-customer-portal' ),
            'priority'    => __( 'Priority', 'wpfh-customer-portal' ),
            'product'     => __( 'Product', 'wpfh-customer-portal' ),
            'date'        => __( 'Created', 'wpfh-customer-portal' ),
        ];
    }

    public static function admin_column_content( $col, $post_id ) {
        switch ( $col ) {
            case 'customer':
                $customer_id = get_post_meta( $post_id, '_wpfh_customer_id', true );
                $user = get_userdata( $customer_id );
                echo $user ? esc_html( $user->display_name . ' (' . $user->user_email . ')' ) : '<em>Guest</em>';
                break;
            case 'status':
                $status = get_post_meta( $post_id, '_wpfh_ticket_status', true ) ?: 'open';
                $colors = [ 'open' => '#3b82f6', 'in_progress' => '#f59e0b', 'resolved' => '#10b981', 'closed' => '#64748b' ];
                $color = $colors[ $status ] ?? '#64748b';
                printf( '<span style="background:%s; color:#fff; padding:3px 8px; border-radius:4px; font-weight:600; font-size:11px; text-transform:uppercase;">%s</span>', esc_attr( $color ), esc_html( str_replace( '_', ' ', $status ) ) );
                break;
            case 'priority':
                $priority = get_post_meta( $post_id, '_wpfh_ticket_priority', true ) ?: 'normal';
                $p_colors = [ 'urgent' => '#ef4444', 'high' => '#f97316', 'normal' => '#64748b' ];
                $p_color = $p_colors[ $priority ] ?? '#64748b';
                printf( '<span style="color:%s; font-weight:700; text-transform:uppercase; font-size:12px;">%s</span>', esc_attr( $p_color ), esc_html( $priority ) );
                break;
            case 'product':
                $product = get_post_meta( $post_id, '_wpfh_ticket_product', true );
                echo esc_html( $product ?: 'General' );
                break;
        }
    }

    public static function handle_ticket_actions() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();

        // 1. Create Ticket
        if ( isset( $_POST['wpfh_action'] ) && $_POST['wpfh_action'] === 'create_ticket' ) {
            check_admin_referer( 'wpfh_cp_create_ticket_action', 'wpfh_cp_nonce' );

            $subject  = sanitize_text_field( $_POST['ticket_subject'] ?? '' );
            $category = sanitize_text_field( $_POST['ticket_category'] ?? 'Support' );
            $priority = sanitize_key( $_POST['ticket_priority'] ?? 'normal' );
            $message  = wp_kses_post( $_POST['ticket_message'] ?? '' );

            if ( empty( $subject ) || empty( $message ) ) {
                wc_add_notice( __( 'Please provide both a subject and details for your ticket.', 'wpfh-customer-portal' ), 'error' );
                return;
            }

            $ticket_id = wp_insert_post( [
                'post_type'    => 'wpfh_ticket',
                'post_status'  => 'publish',
                'post_title'   => $subject,
                'post_content' => $message,
                'post_author'  => $user_id,
            ] );

            if ( ! is_wp_error( $ticket_id ) ) {
                update_post_meta( $ticket_id, '_wpfh_customer_id', $user_id );
                update_post_meta( $ticket_id, '_wpfh_ticket_status', 'open' );
                update_post_meta( $ticket_id, '_wpfh_ticket_priority', in_array( $priority, [ 'normal', 'high', 'urgent' ], true ) ? $priority : 'normal' );
                update_post_meta( $ticket_id, '_wpfh_ticket_product', $category );

                // Handle file attachment if provided
                if ( ! empty( $_FILES['ticket_attachment']['name'] ) ) {
                    self::handle_attachment_upload( $ticket_id, $_FILES['ticket_attachment'] );
                }

                wc_add_notice( sprintf( __( 'Support ticket #%d created successfully! Our team will respond shortly.', 'wpfh-customer-portal' ), $ticket_id ), 'success' );
                wp_safe_redirect( wc_get_endpoint_url( 'support-tickets', '', wc_get_page_permalink( 'myaccount' ) ) );
                exit;
            }
        }

        // 2. Reply to Ticket
        if ( isset( $_POST['wpfh_action'] ) && $_POST['wpfh_action'] === 'reply_ticket' ) {
            check_admin_referer( 'wpfh_cp_reply_ticket_action', 'wpfh_cp_nonce' );

            $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
            $reply_msg = wp_kses_post( $_POST['reply_message'] ?? '' );

            $ticket = get_post( $ticket_id );
            if ( ! $ticket || ( (int) $ticket->post_author !== $user_id && ! current_user_can( 'manage_woocommerce' ) ) ) {
                wc_add_notice( __( 'Unauthorized action.', 'wpfh-customer-portal' ), 'error' );
                return;
            }

            if ( ! empty( $reply_msg ) ) {
                $comment_id = wp_insert_comment( [
                    'comment_post_ID'      => $ticket_id,
                    'comment_author'       => wp_get_current_user()->display_name,
                    'comment_author_email' => wp_get_current_user()->user_email,
                    'comment_content'      => $reply_msg,
                    'comment_type'         => 'wpfh_ticket_reply',
                    'user_id'              => $user_id,
                    'comment_approved'     => 1,
                ] );

                // Update ticket status to open if customer replies
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                    update_post_meta( $ticket_id, '_wpfh_ticket_status', 'open' );
                }

                if ( ! empty( $_FILES['reply_attachment']['name'] ) ) {
                    self::handle_attachment_upload( $ticket_id, $_FILES['reply_attachment'] );
                }

                wc_add_notice( __( 'Your reply has been posted.', 'wpfh-customer-portal' ), 'success' );
                wp_safe_redirect( add_query_arg( [ 'ticket_id' => $ticket_id ], wc_get_endpoint_url( 'support-tickets', '', wc_get_page_permalink( 'myaccount' ) ) ) );
                exit;
            }
        }

        // 3. Close Ticket
        if ( isset( $_POST['wpfh_action'] ) && $_POST['wpfh_action'] === 'close_ticket' ) {
            check_admin_referer( 'wpfh_cp_close_ticket_action', 'wpfh_cp_nonce' );

            $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
            $ticket = get_post( $ticket_id );
            if ( $ticket && ( (int) $ticket->post_author === $user_id || current_user_can( 'manage_woocommerce' ) ) ) {
                update_post_meta( $ticket_id, '_wpfh_ticket_status', 'resolved' );
                wc_add_notice( sprintf( __( 'Ticket #%d has been marked as resolved.', 'wpfh-customer-portal' ), $ticket_id ), 'success' );
                wp_safe_redirect( add_query_arg( [ 'ticket_id' => $ticket_id ], wc_get_endpoint_url( 'support-tickets', '', wc_get_page_permalink( 'myaccount' ) ) ) );
                exit;
            }
        }
    }

    private static function handle_attachment_upload( $post_id, $file ) {
        if ( empty( $file['name'] ) || ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $allowed_mimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'pdf'          => 'application/pdf',
            'zip'          => 'application/zip',
            'txt'          => 'text/plain',
        ];

        $uploaded = wp_handle_upload( $file, [
            'test_form' => false,
            'mimes'     => $allowed_mimes,
        ] );

        if ( ! empty( $uploaded['url'] ) ) {
            $existing = get_post_meta( $post_id, '_wpfh_ticket_attachments', true ) ?: [];
            $existing[] = [
                'url'  => esc_url_raw( $uploaded['url'] ),
                'file' => sanitize_text_field( $file['name'] ),
                'time' => current_time( 'mysql' ),
            ];
            update_post_meta( $post_id, '_wpfh_ticket_attachments', $existing );
        }
    }

    public static function render_portal_tickets() {
        $user_id   = get_current_user_id();
        $ticket_id = isset( $_GET['ticket_id'] ) ? absint( $_GET['ticket_id'] ) : 0;
        $view      = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        if ( $ticket_id > 0 ) {
            self::render_single_ticket_thread( $ticket_id, $user_id );
            return;
        }

        if ( $view === 'new' ) {
            self::render_new_ticket_form();
            return;
        }

        self::render_ticket_list( $user_id );
    }

    private static function render_ticket_list( $user_id ) {
        $tickets = get_posts( [
            'post_type'      => 'wpfh_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'author'         => $user_id,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $new_ticket_url = add_query_arg( [ 'action' => 'new' ], wc_get_endpoint_url( 'support-tickets', '', wc_get_page_permalink( 'myaccount' ) ) );
        ?>
        <div class="wpfh-cp-tickets-dashboard">
            <div class="wpfh-cp-tickets-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <div>
                    <h3 style="margin:0 0 5px 0;">💬 <?php esc_html_e( 'Priority Support Desk (Pro)', 'wpfh-customer-portal' ); ?></h3>
                    <p style="margin:0; color:#94a3b8; font-size:14px;"><?php esc_html_e( 'Direct SLA-backed communication channel with our senior engineering team.', 'wpfh-customer-portal' ); ?></p>
                </div>
                <a href="<?php echo esc_url( $new_ticket_url ); ?>" class="button button-primary" style="padding:10px 18px; border-radius:8px; font-weight:600;">
                    ➕ <?php esc_html_e( 'Open New Ticket', 'wpfh-customer-portal' ); ?>
                </a>
            </div>

            <?php if ( empty( $tickets ) ) : ?>
                <div class="woocommerce-info" style="background:#0f172a; border-left-color:#38bdf8; color:#cbd5e1; padding:20px; border-radius:8px;">
                    <p style="margin:0;"><?php esc_html_e( 'You have no support tickets yet. Have a technical question or need assistance? Click "Open New Ticket" above.', 'wpfh-customer-portal' ); ?></p>
                </div>
            <?php else : ?>
                <table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders" style="width:100%; border-collapse:collapse; background:#0f172a; border-radius:10px; overflow:hidden;">
                    <thead>
                        <tr style="border-bottom:1px solid #1e293b; background:#1e293b;">
                            <th style="padding:12px 16px; color:#94a3b8; text-align:left;"><?php esc_html_e( 'Ticket', 'wpfh-customer-portal' ); ?></th>
                            <th style="padding:12px 16px; color:#94a3b8; text-align:left;"><?php esc_html_e( 'Category', 'wpfh-customer-portal' ); ?></th>
                            <th style="padding:12px 16px; color:#94a3b8; text-align:center;"><?php esc_html_e( 'Priority', 'wpfh-customer-portal' ); ?></th>
                            <th style="padding:12px 16px; color:#94a3b8; text-align:center;"><?php esc_html_e( 'Status', 'wpfh-customer-portal' ); ?></th>
                            <th style="padding:12px 16px; color:#94a3b8; text-align:right;"><?php esc_html_e( 'Actions', 'wpfh-customer-portal' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $tickets as $t ) :
                            $status   = get_post_meta( $t->ID, '_wpfh_ticket_status', true ) ?: 'open';
                            $priority = get_post_meta( $t->ID, '_wpfh_ticket_priority', true ) ?: 'normal';
                            $product  = get_post_meta( $t->ID, '_wpfh_ticket_product', true ) ?: 'General';
                            $view_url = add_query_arg( [ 'ticket_id' => $t->ID ], wc_get_endpoint_url( 'support-tickets', '', wc_get_page_permalink( 'myaccount' ) ) );
                            ?>
                            <tr style="border-bottom:1px solid #1e293b;">
                                <td style="padding:14px 16px;">
                                    <strong style="color:#f8fafc; font-size:15px;">#<?php echo esc_html( $t->ID ); ?> — <?php echo esc_html( $t->post_title ); ?></strong>
                                    <div style="font-size:12px; color:#64748b; margin-top:3px;"><?php echo esc_html( get_the_date( 'M j, Y \a\t g:i A', $t ) ); ?></div>
                                </td>
                                <td style="padding:14px 16px; color:#cbd5e1;"><?php echo esc_html( $product ); ?></td>
                                <td style="padding:14px 16px; text-align:center;">
                                    <span style="font-weight:700; text-transform:uppercase; font-size:11px; padding:3px 8px; border-radius:4px; <?php echo $priority === 'urgent' ? 'background:#ef4444; color:#fff;' : ( $priority === 'high' ? 'background:#f97316; color:#fff;' : 'background:#334155; color:#94a3b8;' ); ?>">
                                        <?php echo esc_html( $priority ); ?>
                                    </span>
                                </td>
                                <td style="padding:14px 16px; text-align:center;">
                                    <span style="font-weight:700; text-transform:uppercase; font-size:11px; padding:3px 8px; border-radius:4px; <?php echo $status === 'resolved' ? 'background:#10b981; color:#fff;' : ( $status === 'in_progress' ? 'background:#f59e0b; color:#fff;' : 'background:#3b82f6; color:#fff;' ); ?>">
                                        <?php echo esc_html( str_replace( '_', ' ', $status ) ); ?>
                                    </span>
                                </td>
                                <td style="padding:14px 16px; text-align:right;">
                                    <a href="<?php echo esc_url( $view_url ); ?>" class="button" style="padding:6px 14px; font-size:13px; border-radius:6px; background:#1e293b; color:#f8fafc; border:1px solid #334155;">
                                        <?php esc_html_e( 'View Thread', 'wpfh-customer-portal' ); ?> →
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_new_ticket_form() {
        $back_url = wc_get_endpoint_url( 'support-tickets', '', wc_get_page_permalink( 'myaccount' ) );
        ?>
        <div class="wpfh-cp-support-card" style="background:#0f172a; padding:28px; border-radius:12px; border:1px solid #1e293b;">
            <div style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; color:#f8fafc;">🚀 <?php esc_html_e( 'Open New Priority Support Ticket', 'wpfh-customer-portal' ); ?></h3>
                <a href="<?php echo esc_url( $back_url ); ?>" style="color:#94a3b8; text-decoration:none; font-size:14px;">← <?php esc_html_e( 'Back to Tickets', 'wpfh-customer-portal' ); ?></a>
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="wpfh_action" value="create_ticket" />
                <?php wp_nonce_field( 'wpfh_cp_create_ticket_action', 'wpfh_cp_nonce' ); ?>

                <div class="wpfh-cp-form-group" style="margin-bottom:16px;">
                    <label style="display:block; font-weight:600; color:#cbd5e1; margin-bottom:6px;"><?php esc_html_e( 'Subject / Issue Summary', 'wpfh-customer-portal' ); ?></label>
                    <input type="text" name="ticket_subject" class="regular-text" style="width:100%; max-width:100%; box-sizing:border-box; background:#1e293b; border:1px solid #334155; color:#f8fafc; padding:10px; border-radius:6px;" required placeholder="<?php esc_attr_e( 'e.g. Question regarding Smart Redirect regex rules', 'wpfh-customer-portal' ); ?>" />
                </div>

                <div style="display:flex; gap:16px; margin-bottom:16px;">
                    <div style="flex:1;">
                        <label style="display:block; font-weight:600; color:#cbd5e1; margin-bottom:6px;"><?php esc_html_e( 'Product / Category', 'wpfh-customer-portal' ); ?></label>
                        <select name="ticket_category" style="width:100%; background:#1e293b; border:1px solid #334155; color:#f8fafc; padding:10px; border-radius:6px;">
                            <option value="Customer Portal Pro">WPForgeHub Customer Portal</option>
                            <option value="Frictionless Checkout Pro">WPForgeHub Frictionless Checkout</option>
                            <option value="Freemius Bridge Pro">WPForgeHub Freemius Bridge</option>
                            <option value="Image Memory Guard Pro">WPForgeHub Image Memory Guard</option>
                            <option value="Smart Redirect Pro">WPForgeHub Smart Redirect</option>
                            <option value="Billing & Licensing">Billing & Licensing</option>
                            <option value="Custom Development">Custom Integration Inquiry</option>
                        </select>
                    </div>
                    <div style="flex:1;">
                        <label style="display:block; font-weight:600; color:#cbd5e1; margin-bottom:6px;"><?php esc_html_e( 'Priority Level', 'wpfh-customer-portal' ); ?></label>
                        <select name="ticket_priority" style="width:100%; background:#1e293b; border:1px solid #334155; color:#f8fafc; padding:10px; border-radius:6px;">
                            <option value="normal"><?php esc_html_e( 'Normal (Within 24 Hours)', 'wpfh-customer-portal' ); ?></option>
                            <option value="high"><?php esc_html_e( 'High (Within 6 Hours)', 'wpfh-customer-portal' ); ?></option>
                            <option value="urgent"><?php esc_html_e( 'Urgent Production Outage (< 2 Hours)', 'wpfh-customer-portal' ); ?></option>
                        </select>
                    </div>
                </div>

                <div class="wpfh-cp-form-group" style="margin-bottom:16px;">
                    <label style="display:block; font-weight:600; color:#cbd5e1; margin-bottom:6px;"><?php esc_html_e( 'Detailed Problem Description', 'wpfh-customer-portal' ); ?></label>
                    <textarea name="ticket_message" rows="6" style="width:100%; box-sizing:border-box; background:#1e293b; border:1px solid #334155; color:#f8fafc; padding:12px; border-radius:6px;" required placeholder="<?php esc_attr_e( 'Please provide steps to reproduce, WordPress version, error messages, and URL if applicable...', 'wpfh-customer-portal' ); ?>"></textarea>
                </div>

                <div class="wpfh-cp-form-group" style="margin-bottom:20px;">
                    <label style="display:block; font-weight:600; color:#cbd5e1; margin-bottom:6px;"><?php esc_html_e( 'Attachment (Screenshot or Logs: PNG, JPG, PDF, ZIP)', 'wpfh-customer-portal' ); ?></label>
                    <input type="file" name="ticket_attachment" accept=".png,.jpg,.jpeg,.pdf,.zip,.txt" style="color:#94a3b8;" />
                </div>

                <button type="submit" class="button button-primary" style="padding:12px 24px; font-size:15px; border-radius:8px; font-weight:600;">
                    🚀 <?php esc_html_e( 'Submit Priority Ticket', 'wpfh-customer-portal' ); ?>
                </button>
            </form>
        </div>
        <?php
    }

    private static function render_single_ticket_thread( $ticket_id, $user_id ) {
        $ticket = get_post( $ticket_id );
        if ( ! $ticket || ( (int) $ticket->post_author !== $user_id && ! current_user_can( 'manage_woocommerce' ) ) ) {
            echo '<div class="woocommerce-error">' . esc_html__( 'Ticket not found or access denied.', 'wpfh-customer-portal' ) . '</div>';
            return;
        }

        $status      = get_post_meta( $ticket->ID, '_wpfh_ticket_status', true ) ?: 'open';
        $priority    = get_post_meta( $ticket->ID, '_wpfh_ticket_priority', true ) ?: 'normal';
        $product     = get_post_meta( $ticket->ID, '_wpfh_ticket_product', true ) ?: 'General';
        $attachments = get_post_meta( $ticket->ID, '_wpfh_ticket_attachments', true ) ?: [];
        $back_url    = wc_get_endpoint_url( 'support-tickets', '', wc_get_page_permalink( 'myaccount' ) );

        $replies = get_comments( [
            'post_id' => $ticket->ID,
            'orderby' => 'comment_date_gmt',
            'order'   => 'ASC',
        ] );
        ?>
        <div class="wpfh-cp-ticket-thread" style="background:#0f172a; padding:24px; border-radius:12px; border:1px solid #1e293b;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #1e293b; padding-bottom:16px; margin-bottom:20px;">
                <div>
                    <a href="<?php echo esc_url( $back_url ); ?>" style="color:#94a3b8; text-decoration:none; font-size:13px; display:inline-block; margin-bottom:8px;">← <?php esc_html_e( 'Back to All Tickets', 'wpfh-customer-portal' ); ?></a>
                    <h2 style="margin:0; color:#f8fafc; font-size:22px;">#<?php echo esc_html( $ticket->ID ); ?> — <?php echo esc_html( $ticket->post_title ); ?></h2>
                    <div style="margin-top:6px; color:#64748b; font-size:13px;">
                        <span><?php esc_html_e( 'Category:', 'wpfh-customer-portal' ); ?> <strong><?php echo esc_html( $product ); ?></strong></span> | 
                        <span><?php esc_html_e( 'Priority:', 'wpfh-customer-portal' ); ?> <strong style="text-transform:uppercase;"><?php echo esc_html( $priority ); ?></strong></span> | 
                        <span><?php esc_html_e( 'Status:', 'wpfh-customer-portal' ); ?> <strong style="text-transform:uppercase; color:#38bdf8;"><?php echo esc_html( $status ); ?></strong></span>
                    </div>
                </div>

                <?php if ( $status !== 'resolved' && $status !== 'closed' ) : ?>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="wpfh_action" value="close_ticket" />
                        <input type="hidden" name="ticket_id" value="<?php echo esc_attr( $ticket->ID ); ?>" />
                        <?php wp_nonce_field( 'wpfh_cp_close_ticket_action', 'wpfh_cp_nonce' ); ?>
                        <button type="submit" class="button" style="background:#1e293b; color:#10b981; border:1px solid #10b981; border-radius:6px; font-weight:600; padding:6px 12px;">
                            ✓ <?php esc_html_e( 'Mark as Resolved', 'wpfh-customer-portal' ); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Original Message -->
            <div style="background:#1e293b; border-radius:8px; padding:18px; margin-bottom:20px; border-left:4px solid #3b82f6;">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; font-size:13px; color:#94a3b8;">
                    <strong><?php echo esc_html( get_userdata( $ticket->post_author )->display_name ); ?> (Customer)</strong>
                    <span><?php echo esc_html( get_the_date( 'M j, Y \a\t g:i A', $ticket ) ); ?></span>
                </div>
                <div style="color:#e2e8f0; line-height:1.6;"><?php echo wp_kses_post( wpautop( $ticket->post_content ) ); ?></div>
                <?php if ( ! empty( $attachments ) ) : ?>
                    <div style="margin-top:14px; padding-top:10px; border-top:1px solid #334155;">
                        <span style="font-size:12px; color:#94a3b8; font-weight:600;">📎 <?php esc_html_e( 'Attachments:', 'wpfh-customer-portal' ); ?></span>
                        <ul style="margin:6px 0 0 16px; padding:0;">
                            <?php foreach ( $attachments as $att ) : ?>
                                <li><a href="<?php echo esc_url( $att['url'] ); ?>" target="_blank" rel="noopener noreferrer" style="color:#38bdf8; font-size:13px;"><?php echo esc_html( $att['file'] ); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Thread Replies -->
            <?php if ( ! empty( $replies ) ) : ?>
                <div style="margin-bottom:24px;">
                    <?php foreach ( $replies as $rep ) :
                        $is_staff = user_can( $rep->user_id, 'manage_woocommerce' );
                        ?>
                        <div style="background:<?php echo $is_staff ? '#0e2338' : '#1e293b'; ?>; border-radius:8px; padding:18px; margin-bottom:14px; border-left:4px solid <?php echo $is_staff ? '#10b981' : '#64748b'; ?>;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:13px; color:#94a3b8;">
                                <strong style="color:<?php echo $is_staff ? '#34d399' : '#cbd5e1'; ?>;">
                                    <?php echo esc_html( $rep->comment_author ); ?> <?php echo $is_staff ? '<span style="background:#065f46; color:#a7f3d0; padding:2px 6px; border-radius:4px; font-size:11px; margin-left:6px;">Staff Support</span>' : ''; ?>
                                </strong>
                                <span><?php echo esc_html( date_i18n( 'M j, Y \a\t g:i A', strtotime( $rep->comment_date ) ) ); ?></span>
                            </div>
                            <div style="color:#e2e8f0; line-height:1.6;"><?php echo wp_kses_post( wpautop( $rep->comment_content ) ); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Reply Box -->
            <div style="background:#131c2e; padding:20px; border-radius:8px; border:1px solid #1e293b;">
                <h4 style="margin:0 0 12px 0; color:#f8fafc;">✍️ <?php esc_html_e( 'Add Reply to Ticket', 'wpfh-customer-portal' ); ?></h4>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="wpfh_action" value="reply_ticket" />
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr( $ticket->ID ); ?>" />
                    <?php wp_nonce_field( 'wpfh_cp_reply_ticket_action', 'wpfh_cp_nonce' ); ?>

                    <div style="margin-bottom:12px;">
                        <textarea name="reply_message" rows="4" style="width:100%; box-sizing:border-box; background:#1e293b; border:1px solid #334155; color:#f8fafc; padding:10px; border-radius:6px;" placeholder="<?php esc_attr_e( 'Type your reply or follow-up question here...', 'wpfh-customer-portal' ); ?>" required></textarea>
                    </div>

                    <div style="margin-bottom:16px;">
                        <input type="file" name="reply_attachment" accept=".png,.jpg,.jpeg,.pdf,.zip,.txt" style="color:#94a3b8; font-size:13px;" />
                    </div>

                    <button type="submit" class="button button-primary" style="padding:10px 20px; border-radius:6px; font-weight:600;">
                        <?php esc_html_e( 'Send Reply', 'wpfh-customer-portal' ); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }
}
