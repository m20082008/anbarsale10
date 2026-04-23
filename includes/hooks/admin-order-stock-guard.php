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
 * بررسی امنیت و اعتبار درخواست برای ثبت لاگ تغییرات سفارش در ادمین.
 */
function wc_suf_can_log_admin_order_changes( $require_ajax_nonce = false ) {
    if ( ! is_admin() || ! current_user_can( 'edit_shop_orders' ) ) {
        return false;
    }

    if ( $require_ajax_nonce ) {
        if ( ! wp_doing_ajax() ) {
            return false;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( $action !== 'woocommerce_save_order_items' ) {
            return false;
        }

        $nonce = isset( $_REQUEST['security'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ) : '';
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'order-item' ) ) {
            return false;
        }
    }

    // در درخواست‌های غیر AJAX اگر nonce استاندارد فرم وجود داشت، اعتبارسنجی می‌کنیم.
    if ( isset( $_REQUEST['_wpnonce'] ) ) {
        $wpnonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
        if ( $wpnonce !== '' && ! wp_verify_nonce( $wpnonce, 'update-order_' . (int) ( $_REQUEST['post_ID'] ?? 0 ) ) ) {
            return false;
        }
    }

    return true;
}

/**
 * گرفتن اسنپ‌شات از مجموع تعداد هر محصول در آیتم‌های سفارش.
 *
 * @return array<int, array<string,mixed>>
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
 * ذخیره اسنپ‌شات قبل از تغییر برای ویرایش آیتم‌های سفارش (ادمین/AJAX).
 */
function wc_suf_capture_admin_order_items_before_update( $order_id, $items ) {
    if ( ! wc_suf_can_log_admin_order_changes( true ) ) {
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
 * علامت‌گذاری اینکه در این ذخیره، افزودن آیتم سفارش رخ داده است.
 */
function wc_suf_mark_admin_order_item_added( $item_id = 0, $item = null, $order_id = 0 ) {
    $order_id = (int) $order_id;
    if ( $order_id <= 0 && isset( $_REQUEST['order_id'] ) ) {
        $order_id = (int) $_REQUEST['order_id'];
    }

    if ( $order_id > 0 ) {
        $GLOBALS['wc_suf_admin_order_items_change_flags'][ $order_id ]['has_added'] = true;
    }
}
add_action( 'woocommerce_before_order_item_add', 'wc_suf_mark_admin_order_item_added', 10, 3 );

/**
 * ثبت آیتم قبل از حذف، تا حذف کامل محصول از سفارش از دست نرود.
 */
function wc_suf_capture_admin_order_item_before_delete( $item_id ) {
    $item_id = (int) $item_id;
    if ( $item_id <= 0 ) {
        return;
    }

    $item = WC_Order_Factory::get_order_item( $item_id );
    if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
        return;
    }

    $order_id = (int) $item->get_order_id();
    if ( $order_id <= 0 ) {
        return;
    }

    $product = $item->get_product();
    if ( ! $product ) {
        return;
    }

    $product_id = (int) $product->get_id();
    if ( $product_id <= 0 ) {
        return;
    }

    if ( ! isset( $GLOBALS['wc_suf_admin_order_deleted_items'][ $order_id ][ $product_id ] ) ) {
        $GLOBALS['wc_suf_admin_order_deleted_items'][ $order_id ][ $product_id ] = [
            'product_id'   => $product_id,
            'product_name' => wc_suf_full_product_label( $product ),
            'qty'          => 0.0,
        ];
    }

    $GLOBALS['wc_suf_admin_order_deleted_items'][ $order_id ][ $product_id ]['qty'] += max( 0, (float) $item->get_quantity() );
    $GLOBALS['wc_suf_admin_order_items_change_flags'][ $order_id ]['has_removed'] = true;
}
add_action( 'woocommerce_before_delete_order_item', 'wc_suf_capture_admin_order_item_before_delete', 10, 1 );
add_action( 'woocommerce_before_order_item_delete', 'wc_suf_capture_admin_order_item_before_delete', 10, 1 );

/**
 * درج لاگ تغییرات محصولات سفارش در جدول audit.
 */
function wc_suf_insert_admin_order_item_change_log( $order, $product_id, $product_name, $old_qty, $new_qty, $change_type ) {
    global $wpdb;

    $order_id = (int) $order->get_id();
    $order_number = (string) $order->get_order_number();
    $created_at_mysql = current_time( 'mysql' );

    $product = wc_get_product( (int) $product_id );
    $stock_product = $product ? wc_suf_get_stock_product( $product ) : null;
    $current_stock = ( $stock_product && $stock_product->managing_stock() ) ? $stock_product->get_stock_quantity() : null;
    $current_stock = $current_stock === null ? null : (float) wc_stock_amount( $current_stock );

    $user = wp_get_current_user();
    $user_id = get_current_user_id();
    $user_login = ( $user && $user->exists() ) ? (string) $user->user_login : 'system';

    $wpdb->insert(
        $wpdb->prefix . 'stock_audit',
        [
            'batch_code'   => 'admin_order_update_' . $order_number,
            'csv_file_url' => null,
            'word_file_url'=> null,
            'op_type'      => 'admin_order_edit',
            'purpose'      => wp_json_encode(
                [
                    'order_id'            => $order_id,
                    'order_number'        => $order_number,
                    'change_type'         => $change_type,
                    'previous_quantity'   => (float) $old_qty,
                    'new_quantity'        => (float) $new_qty,
                    'current_stock_after' => $current_stock,
                    'timestamp'           => $created_at_mysql,
                ]
            ),
            'print_label'  => 0,
            'product_id'   => (int) $product_id,
            'product_name' => (string) $product_name,
            'old_qty'      => (float) $old_qty,
            'added_qty'    => (float) $new_qty - (float) $old_qty,
            'new_qty'      => (float) $new_qty,
            'user_id'      => $user_id > 0 ? $user_id : null,
            'user_login'   => $user_login,
            'user_code'    => $order_number,
            'ip'           => '',
            'created_at'   => $created_at_mysql,
        ]
    );
}

/**
 * محاسبه و ثبت اختلاف قبل/بعد سفارش و ذخیره اسنپ‌شات نهایی.
 */
function wc_suf_log_admin_order_items_diff( $order_id, $require_ajax_nonce = false ) {
    if ( ! wc_suf_can_log_admin_order_changes( $require_ajax_nonce ) ) {
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

    $before = isset( $GLOBALS['wc_suf_admin_order_items_before_update'][ $order_id ] )
        ? (array) $GLOBALS['wc_suf_admin_order_items_before_update'][ $order_id ]
        : (array) $order->get_meta( '_wc_suf_admin_items_snapshot', true );

    $after = wc_suf_get_admin_order_items_qty_snapshot( $order );

    // اگر قبل از حذف آیتم‌ها اطلاعاتی گرفته‌ایم، در before ادغام می‌کنیم.
    if ( ! empty( $GLOBALS['wc_suf_admin_order_deleted_items'][ $order_id ] ) ) {
        foreach ( (array) $GLOBALS['wc_suf_admin_order_deleted_items'][ $order_id ] as $deleted_pid => $deleted_row ) {
            if ( ! isset( $before[ $deleted_pid ] ) ) {
                $before[ $deleted_pid ] = $deleted_row;
            } else {
                $before[ $deleted_pid ]['qty'] += (float) $deleted_row['qty'];
            }
        }
    }

    $product_ids = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
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

        $product_name = isset( $after[ $product_id ]['product_name'] )
            ? (string) $after[ $product_id ]['product_name']
            : ( isset( $before[ $product_id ]['product_name'] ) ? (string) $before[ $product_id ]['product_name'] : '' );

        if ( $product_name === '' ) {
            $product = wc_get_product( (int) $product_id );
            if ( $product ) {
                $product_name = wc_suf_full_product_label( $product );
            }
        }

        wc_suf_insert_admin_order_item_change_log( $order, $product_id, $product_name, $old_qty, $new_qty, $change_type );
    }

    $order->update_meta_data( '_wc_suf_admin_items_snapshot', $after );
    $order->save_meta_data();

    unset( $GLOBALS['wc_suf_admin_order_items_before_update'][ $order_id ] );
    unset( $GLOBALS['wc_suf_admin_order_deleted_items'][ $order_id ] );
    unset( $GLOBALS['wc_suf_admin_order_items_change_flags'][ $order_id ] );
}

/**
 * مسیر اصلی ذخیره آیتم‌های سفارش در ادمین (AJAX).
 */
function wc_suf_log_precise_admin_order_item_changes_after_save( $order_id, $items ) {
    wc_suf_log_admin_order_items_diff( $order_id, true );
}
add_action( 'woocommerce_saved_order_items', 'wc_suf_log_precise_admin_order_item_changes_after_save', 99, 2 );

/**
 * fallback برای به‌روزرسانی سفارش در ادمین (غیر AJAX).
 */
function wc_suf_log_precise_admin_order_item_changes_on_order_update( $order_id ) {
    wc_suf_log_admin_order_items_diff( $order_id, false );
}
add_action( 'woocommerce_update_order', 'wc_suf_log_precise_admin_order_item_changes_on_order_update', 30, 1 );
