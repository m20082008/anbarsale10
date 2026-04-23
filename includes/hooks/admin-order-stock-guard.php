<?php

/**
 * جلوگیری از منفی شدن موجودی هنگام ویرایش آیتم‌های سفارش در ادمین.
 */
function wc_suf_prevent_negative_stock_in_admin_order_edit( $prevent, $item, $item_quantity ) {
    if ( $prevent ) {
        return $prevent;
    }

    if ( ! is_admin() || ! wp_doing_ajax() ) {
        return $prevent;
    }

    if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
        return $prevent;
    }

    $order = $item->get_order();
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        return $prevent;
    }

    $product = $item->get_product();
    if ( ! $product || ! $product->managing_stock() || $product->backorders_allowed() ) {
        return $prevent;
    }

    $target_qty = wc_stock_amount( null !== $item_quantity ? $item_quantity : $item->get_quantity() );
    $reduced_qty = wc_stock_amount( wc_suf_get_order_item_reduced_stock_qty( $item ) );
    if ( $target_qty <= $reduced_qty ) {
        return $prevent;
    }

    $extra_reduce_qty = $target_qty - $reduced_qty;
    $stock_qty = $product->get_stock_quantity();
    $available_qty = $stock_qty === null ? 0 : wc_stock_amount( $stock_qty );

    if ( $extra_reduce_qty <= $available_qty ) {
        return $prevent;
    }

    if ( class_exists( 'WC_Admin_Meta_Boxes' ) && method_exists( 'WC_Admin_Meta_Boxes', 'add_error' ) ) {
        WC_Admin_Meta_Boxes::add_error(
            sprintf(
                'امکان ثبت تعداد %1$s برای "%2$s" وجود ندارد. موجودی قابل کسر در حال حاضر %3$s است.',
                wc_format_localized_decimal( $target_qty ),
                $product->get_name(),
                wc_format_localized_decimal( $available_qty + $reduced_qty )
            )
        );
    }

    return true;
}
add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', 'wc_suf_prevent_negative_stock_in_admin_order_edit', 10, 3 );

/**
 * بررسی اینکه درخواست جاری، ذخیرهٔ آیتم‌های سفارش از ادمین ووکامرس است.
 */
function wc_suf_is_admin_order_items_save_request_for_logging() {
    if ( ! function_exists( 'wc_suf_is_manual_admin_order_edit_request' ) || ! wc_suf_is_manual_admin_order_edit_request() ) {
        return false;
    }

    $nonce = isset( $_REQUEST['security'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ) : '';
    if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'order-item' ) ) {
        return false;
    }

    return true;
}

/**
 * گرفتن اسنپ‌شات تعداد هر محصول در آیتم‌های سفارش.
 *
 * @param WC_Order $order شیء سفارش.
 * @return array<int, array<string, mixed>>
 */
function wc_suf_get_admin_order_items_qty_snapshot( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        return [];
    }

    $snapshot = [];

    foreach ( $order->get_items( 'line_item' ) as $item ) {
        if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
            continue;
        }

        $product = $item->get_product();
        if ( ! $product ) {
            continue;
        }

        $product_id = (int) $product->get_id();
        if ( $product_id <= 0 ) {
            continue;
        }

        if ( ! isset( $snapshot[ $product_id ] ) ) {
            $snapshot[ $product_id ] = [
                'product_id'   => $product_id,
                'product_name' => wc_suf_full_product_label( $product ),
                'qty'          => 0.0,
            ];
        }

        $snapshot[ $product_id ]['qty'] += max( 0, (float) $item->get_quantity() );
    }

    return $snapshot;
}

/**
 * نگهداری وضعیت قبل از ذخیرهٔ سفارش جهت محاسبهٔ تغییرات دقیق تعداد.
 */
function wc_suf_capture_admin_order_items_before_update( $order_id, $items ) {
    if ( ! wc_suf_is_admin_order_items_save_request_for_logging() ) {
        return;
    }

    $order_id = (int) $order_id;
    if ( $order_id <= 0 ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $GLOBALS['wc_suf_admin_order_items_before_update'][ $order_id ] = wc_suf_get_admin_order_items_qty_snapshot( $order );
}
add_action( 'woocommerce_before_save_order_items', 'wc_suf_capture_admin_order_items_before_update', 15, 2 );

/**
 * ثبت لاگ تغییرات آیتم‌های سفارش پس از ذخیره از پنل مدیریت.
 */
function wc_suf_log_precise_admin_order_item_changes( $order_id, $items ) {
    if ( ! wc_suf_is_admin_order_items_save_request_for_logging() ) {
        return;
    }

    $order_id = (int) $order_id;
    if ( $order_id <= 0 ) {
        return;
    }

    $before = isset( $GLOBALS['wc_suf_admin_order_items_before_update'][ $order_id ] )
        ? (array) $GLOBALS['wc_suf_admin_order_items_before_update'][ $order_id ]
        : [];

    unset( $GLOBALS['wc_suf_admin_order_items_before_update'][ $order_id ] );

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $after = wc_suf_get_admin_order_items_qty_snapshot( $order );
    $product_ids = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
    if ( empty( $product_ids ) ) {
        return;
    }

    global $wpdb;
    $audit_table = $wpdb->prefix . 'stock_audit';

    $order_number = (string) $order->get_order_number();
    $created_at_mysql = current_time( 'mysql' );
    $batch_code = 'admin_order_update_' . $order_number;

    $user = wp_get_current_user();
    $user_id = get_current_user_id();
    $user_login = ( $user && $user->exists() ) ? (string) $user->user_login : 'system';

    foreach ( $product_ids as $product_id ) {
        $old_qty = isset( $before[ $product_id ]['qty'] ) ? (float) $before[ $product_id ]['qty'] : 0.0;
        $new_qty = isset( $after[ $product_id ]['qty'] ) ? (float) $after[ $product_id ]['qty'] : 0.0;

        if ( abs( $new_qty - $old_qty ) < 0.0001 ) {
            continue;
        }

        $change_type = 'decreased';
        if ( $old_qty <= 0 && $new_qty > 0 ) {
            $change_type = 'added';
        } elseif ( $old_qty > 0 && $new_qty <= 0 ) {
            $change_type = 'removed';
        } elseif ( $new_qty > $old_qty ) {
            $change_type = 'increased';
        }

        $product = wc_get_product( (int) $product_id );
        $product_name = isset( $after[ $product_id ]['product_name'] )
            ? (string) $after[ $product_id ]['product_name']
            : ( isset( $before[ $product_id ]['product_name'] ) ? (string) $before[ $product_id ]['product_name'] : '' );

        if ( $product && $product_name === '' ) {
            $product_name = wc_suf_full_product_label( $product );
        }

        $stock_product = $product ? wc_suf_get_stock_product( $product ) : null;
        $current_stock = ( $stock_product && $stock_product->managing_stock() ) ? $stock_product->get_stock_quantity() : null;
        $current_stock = $current_stock === null ? null : (float) wc_stock_amount( $current_stock );

        $purpose_payload = [
            'order_id'            => $order_id,
            'order_number'        => $order_number,
            'change_type'         => $change_type,
            'previous_quantity'   => $old_qty,
            'new_quantity'        => $new_qty,
            'current_stock_after' => $current_stock,
            'timestamp'           => $created_at_mysql,
        ];

        $wpdb->insert(
            $audit_table,
            [
                'batch_code'   => $batch_code,
                'csv_file_url' => null,
                'word_file_url'=> null,
                'op_type'      => 'admin_order_edit',
                'purpose'      => wp_json_encode( $purpose_payload ),
                'print_label'  => 0,
                'product_id'   => (int) $product_id,
                'product_name' => $product_name,
                'old_qty'      => $old_qty,
                'added_qty'    => $new_qty - $old_qty,
                'new_qty'      => $new_qty,
                'user_id'      => $user_id > 0 ? $user_id : null,
                'user_login'   => $user_login,
                'user_code'    => $order_number,
                'ip'           => '',
                'created_at'   => $created_at_mysql,
            ]
        );
    }
}
add_action( 'woocommerce_saved_order_items', 'wc_suf_log_precise_admin_order_item_changes', 99, 2 );
