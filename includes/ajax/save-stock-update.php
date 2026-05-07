<?php
/*--------------------------------------
| AJAX: ثبت نهایی (YITH POS)
---------------------------------------*/
add_action('wp_ajax_save_stock_update','wc_suf_save_stock_update_handler');
add_action('wp_ajax_wc_suf_sync_sale_hold_order','wc_suf_sync_sale_hold_order_handler');
add_action('wp_ajax_wc_suf_complete_pending_sale','wc_suf_complete_pending_sale_handler');
add_action('wp_ajax_wc_suf_pending_products_report','wc_suf_pending_products_report_handler');
add_action('wp_ajax_wc_suf_get_sale_order_for_edit','wc_suf_get_sale_order_for_edit_handler');

function wc_suf_current_user_can_edit_any_sale_order() {
    return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

function wc_suf_get_sale_method_labels() {
    return [
        'main_onsite'       => '۱- فروش حضوری انبار اصلی',
        'tehranpars_onsite' => '۲- فروش حضوری شعبه تهرانپارس',
        'post'              => '۳- پست',
        'snap'              => '۴- اسنپ',
        'tipax'             => '۵- تیپاکس',
    ];
}

function wc_suf_validate_sale_method_for_current_user( $raw_method ) {
    $sale_method = sanitize_text_field( (string) $raw_method );
    $valid_methods = array_keys( wc_suf_get_sale_method_labels() );
    if ( ! in_array( $sale_method, $valid_methods, true ) ) {
        return '';
    }
    if ( 'main_onsite' === $sale_method ) {
        $can_use_main_onsite_sale_method = current_user_can( 'manage_options' ) || wc_suf_current_user_has_role( 'formeditor' );
        if ( ! $can_use_main_onsite_sale_method ) {
            return '';
        }
    }
    return $sale_method;
}

function wc_suf_update_pending_order_visible_meta( $order, $breakdown_rows ) {
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        return;
    }
    $rows = is_array( $breakdown_rows ) ? $breakdown_rows : [];
    $lines = [];
    $total_pending = 0;

    foreach ( $rows as $row ) {
        $pending_qty = max( 0, (int) ( $row['pending_qty'] ?? 0 ) );
        if ( $pending_qty <= 0 ) {
            continue;
        }
        $pid = absint( $row['product_id'] ?? 0 );
        $name = trim( (string) ( $row['product_name'] ?? '' ) );
        if ( $name === '' && $pid > 0 ) {
            $product = wc_get_product( $pid );
            if ( $product ) {
                $name = (string) $product->get_name();
            }
        }
        $product_code = '';
        if ( $pid > 0 ) {
            $product = isset( $product ) && $product ? $product : wc_get_product( $pid );
            if ( $product ) {
                $product_code = trim( (string) $product->get_sku() );
                if ( $product_code === '' ) {
                    $product_code = (string) $product->get_id();
                }
            }
        }
        if ( $name === '' ) {
            $name = 'محصول #' . $pid;
        }
        if ( $product_code !== '' ) {
            $name .= ' (' . $product_code . ')';
        }
        $total_pending += $pending_qty;
        $lines[] = sprintf( '%s | تعداد در انتظار: %d', $name, $pending_qty );
    }

    $order->update_meta_data( 'تعداد کل در انتظار', $total_pending );
    $order->update_meta_data( 'اقلام در انتظار', empty($lines) ? 'ندارد' : implode( "\n", $lines ) );
}

function wc_suf_build_pending_price_map_for_order( $order, $breakdown_rows ) {
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        return [];
    }
    $rows = is_array( $breakdown_rows ) ? $breakdown_rows : [];
    $unit_price_map = [];
    foreach ( $order->get_items( 'line_item' ) as $item ) {
        if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
            continue;
        }
        $pid = (int) $item->get_variation_id();
        if ( $pid <= 0 ) {
            $pid = (int) $item->get_product_id();
        }
        if ( $pid <= 0 ) {
            continue;
        }
        $qty = max( 1, (int) $item->get_quantity() );
        $line_subtotal = (float) $item->get_subtotal();
        if ( $line_subtotal <= 0 ) {
            $line_subtotal = (float) $item->get_total();
        }
        $unit_price_map[ $pid ] = $line_subtotal / $qty;
    }

    $price_map = [];
    foreach ( $rows as $row ) {
        $pid = absint( $row['product_id'] ?? 0 );
        $pending_qty = max( 0, (int) ( $row['pending_qty'] ?? 0 ) );
        if ( $pid <= 0 || $pending_qty <= 0 ) {
            continue;
        }
        $unit = isset( $unit_price_map[ $pid ] ) ? (float) $unit_price_map[ $pid ] : 0.0;
        if ( $unit <= 0 ) {
            $product = wc_get_product( $pid );
            if ( $product ) {
                $unit = (float) $product->get_price();
            }
        }
        $price_map[ $pid ] = [
            'unit' => $unit,
            'line' => ( $unit * $pending_qty ),
        ];
    }
    return $price_map;
}

function wc_suf_log_pending_sale_allocation( $order, $product, $allocated_qty, $old_qty, $new_qty, $requested_qty, $pending_after_qty ) {
    if ( ! $order || ! is_a( $order, 'WC_Order' ) || ! $product || ! is_a( $product, 'WC_Product' ) ) {
        return;
    }

    $allocated_qty = (float) $allocated_qty;
    if ( $allocated_qty <= 0 ) {
        return;
    }

    global $wpdb;
    $move_table = $wpdb->prefix . 'stock_production_moves';
    $audit_table = $wpdb->prefix . 'stock_audit';

    $order_number = (string) $order->get_order_number();
    $batch_code = 'pending_complete_' . $order_number;
    $created_at_mysql = current_time( 'mysql' );
    $current_user = wp_get_current_user();
    $user_id = get_current_user_id();
    $user_display = '';
    if ( $current_user && $current_user->exists() ) {
        $user_display = trim( (string) $current_user->display_name );
        if ( $user_display === '' ) {
            $user_display = trim( (string) $current_user->user_login );
        }
    }
    if ( $user_display === '' ) {
        $user_display = trim( (string) $order->get_meta( '_wc_suf_seller_name', true ) );
    }
    if ( $user_display === '' ) {
        $user_display = 'system';
    }

    $purpose = sprintf(
        'تکمیل اقلام در انتظار سفارش #%s | تخصیص: %s | موجودی لحظه‌ای: %s → %s | درخواست کل: %d | مانده در انتظار: %d',
        $order_number,
        wc_format_decimal( $allocated_qty, 4 ),
        wc_format_decimal( (float) $old_qty, 4 ),
        wc_format_decimal( (float) $new_qty, 4 ),
        (int) $requested_qty,
        (int) $pending_after_qty
    );

    $wpdb->insert(
        $move_table,
        [
            'batch_code' => $batch_code,
            'operation' => 'sale_edit',
            'destination' => 'main',
            'product_id' => (int) $product->get_id(),
            'product_name' => (string) $product->get_name(),
            'sku' => (string) $product->get_sku(),
            'product_type' => (string) $product->get_type(),
            'parent_id' => (int) $product->get_parent_id(),
            'attributes_text' => wc_suf_get_product_attributes_text( $product ),
            'old_qty' => (float) $old_qty,
            'change_qty' => -1 * $allocated_qty,
            'new_qty' => (float) $new_qty,
            'destination_old_qty' => (float) $old_qty,
            'destination_new_qty' => (float) $new_qty,
            'user_id' => $user_id > 0 ? $user_id : null,
            'user_login' => $user_display,
            'user_code' => $order_number,
            'created_at' => $created_at_mysql,
        ]
    );

    $wpdb->insert(
        $audit_table,
        [
            'batch_code'   => $batch_code,
            'csv_file_url' => null,
            'word_file_url'=> null,
            'op_type'      => 'sale_edit',
            'purpose'      => $purpose,
            'print_label'  => 0,
            'product_id'   => (int) $product->get_id(),
            'product_name' => (string) $product->get_name(),
            'old_qty'      => (float) $old_qty,
            'added_qty'    => -1 * $allocated_qty,
            'new_qty'      => (float) $new_qty,
            'user_id'      => $user_id > 0 ? $user_id : null,
            'user_login'   => $user_display,
            'user_code'    => $order_number,
            'ip'           => '',
            'created_at'   => $created_at_mysql,
        ]
    );
}

function wc_suf_validate_sale_order_edit_access( $order, $current_user_id, &$error_message = '' ) {
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        $error_message = 'سفارش یافت نشد.';
        return false;
    }

    $can_edit_any_order = wc_suf_current_user_can_edit_any_sale_order();
    if ( ! $can_edit_any_order ) {
        $seller_id = absint( $order->get_meta('_wc_suf_seller_id', true ) );
        if ( $seller_id <= 0 || $seller_id !== (int) $current_user_id ) {
            $error_message = 'شما مالک این سفارش نیستید.';
            return false;
        }

        $created_via = (string) $order->get_created_via();
        if ( ! in_array( $created_via, [ 'wc_suf_manual_sale', 'wc_suf_manual_sale_hold' ], true ) ) {
            $error_message = 'فقط سفارش‌های فروش دستی قابل ویرایش هستند.';
            return false;
        }
    }

    $status = (string) $order->get_status();
    if ( in_array( $status, [ 'cancelled', 'trash' ], true ) ) {
        $error_message = 'سفارش لغوشده قابل ویرایش نیست.';
        return false;
    }
    if ( ! $can_edit_any_order && in_array( $status, [ 'completed' ], true ) ) {
        $error_message = 'سفارش تکمیل‌شده قابل ویرایش نیست.';
        return false;
    }

    return true;
}

function wc_suf_get_sale_order_for_edit_handler(){
    check_ajax_referer('wc_suf_get_sale_order_for_edit');

    if( ! wc_suf_current_user_is_pos_manager() ){
        wp_send_json_error(['message'=>'دسترسی غیرمجاز.']);
    }
    if ( ! function_exists( 'wc_get_order' ) ) {
        wp_send_json_error(['message'=>'ووکامرس فعال نیست.']);
    }

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if ( $order_id <= 0 ) {
        wp_send_json_error(['message'=>'شماره سفارش نامعتبر است.']);
    }

    $order = wc_get_order( $order_id );
    $error_message = '';
    if ( ! wc_suf_validate_sale_order_edit_access( $order, get_current_user_id(), $error_message ) ) {
        wp_send_json_error(['message'=> $error_message ?: 'این سفارش قابل ویرایش نیست.']);
    }

    $sale_method = (string) $order->get_meta('_wc_suf_sale_method', true );
    $sale_method_label = wc_suf_get_sale_method_labels()[ $sale_method ] ?? '';
    $sale_op = (string) $order->get_meta('_wc_suf_sale_operation', true );
    if ( ! in_array( $sale_op, [ 'sale', 'sale_teh' ], true ) ) {
        $sale_channel = (string) $order->get_meta('_wc_suf_sale_channel', true );
        $sale_op = ( $sale_channel === 'tehranpars' ) ? 'sale_teh' : 'sale';
    }

    $pending_map = [];
    $pending_meta = (string) $order->get_meta('_wc_suf_pending_breakdown', true );
    $pending_rows = json_decode( $pending_meta, true );
    if ( is_array($pending_rows) ) {
        foreach ( $pending_rows as $row ) {
            $pid = absint( $row['product_id'] ?? 0 );
            if ( $pid <= 0 ) continue;
            $pending_map[ $pid ] = max( 0, (int) ( $row['pending_qty'] ?? 0 ) );
        }
    }

    $items = [];
    $seen_pids = [];
    foreach ( $order->get_items('line_item') as $item ) {
        if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) continue;
        $pid = (int) $item->get_variation_id();
        if ( $pid <= 0 ) $pid = (int) $item->get_product_id();
        if ( $pid <= 0 ) continue;
        $seen_pids[ $pid ] = true;
        $qty = max( 0, (int) $item->get_quantity() );
        if ( $qty <= 0 ) continue;
        $pending_qty = max( 0, (int) ( $pending_map[ $pid ] ?? 0 ) );
        $items[] = [
            'id'            => $pid,
            'name'          => (string) $item->get_name(),
            'qty'           => $qty + $pending_qty,
            'allocated_qty' => $qty,
            'pending_qty'   => $pending_qty,
        ];
    }
    foreach ( $pending_map as $pid => $pending_qty ) {
        $pid = absint( $pid );
        $pending_qty = max( 0, (int) $pending_qty );
        if ( $pid <= 0 || $pending_qty <= 0 || isset( $seen_pids[ $pid ] ) ) {
            continue;
        }
        $product = wc_get_product( $pid );
        $items[] = [
            'id'            => $pid,
            'name'          => $product ? (string) $product->get_name() : ( 'محصول #' . $pid ),
            'qty'           => $pending_qty,
            'allocated_qty' => 0,
            'pending_qty'   => $pending_qty,
        ];
    }

    wp_send_json_success([
        'order_id'               => (int) $order->get_id(),
        'order_number'           => (string) $order->get_order_number(),
        'op_type'                => $sale_op,
        'sale_customer_name'     => (string) $order->get_meta('_wc_suf_sale_customer_name', true ),
        'sale_customer_mobile'   => (string) $order->get_meta('_wc_suf_sale_customer_mobile', true ),
        'sale_customer_address'  => (string) $order->get_meta('_wc_suf_sale_customer_address', true ),
        'sale_method'            => $sale_method,
        'sale_method_label'      => $sale_method_label,
        'items'                  => $items,
    ]);
}

function wc_suf_is_sale_order_editable_in_finalize( $order ) {
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        return false;
    }
    $status = (string) $order->get_status();
    if ( wc_suf_current_user_can_edit_any_sale_order() ) {
        return ! in_array( $status, [ 'cancelled', 'refunded', 'failed', 'trash' ], true );
    }
    return ! in_array( $status, [ 'completed', 'cancelled', 'refunded', 'failed', 'trash' ], true );
}

function wc_suf_log_sale_edit_change( $order, $product, $change_qty, $old_qty, $new_qty, $requested_qty, $pending_qty ) {
    if ( ! $order || ! is_a( $order, 'WC_Order' ) || ! $product || ! is_a( $product, 'WC_Product' ) || 0.0 === (float) $change_qty ) {
        return;
    }

    global $wpdb;
    $move_table = $wpdb->prefix . 'stock_production_moves';
    $audit_table = $wpdb->prefix . 'stock_audit';
    $order_number = (string) $order->get_order_number();
    $batch_code = 'sale_edit_' . $order_number;
    $created_at = current_time('mysql');
    $current_user = wp_get_current_user();
    $user_id = get_current_user_id();
    $user_display = '';
    if ( $current_user && $current_user->exists() ) {
        $user_display = trim( (string) ( $current_user->display_name ?: $current_user->user_login ) );
    }
    if ( $user_display === '' ) {
        $user_display = trim( (string) $order->get_meta('_wc_suf_seller_name', true ) );
    }
    if ( $user_display === '' ) {
        $user_display = 'system';
    }

    $purpose = sprintf(
        'ویرایش سفارش #%s | درخواست: %d | در انتظار: %d | تغییر تخصیص: %s',
        $order_number,
        (int) $requested_qty,
        (int) $pending_qty,
        wc_format_decimal( (float) $change_qty, 4 )
    );

    $wpdb->insert(
        $move_table,
        [
            'batch_code' => $batch_code,
            'operation' => 'sale_edit',
            'destination' => 'main',
            'product_id' => (int) $product->get_id(),
            'product_name' => wc_suf_full_product_label( $product ),
            'sku' => (string) $product->get_sku(),
            'product_type' => (string) $product->get_type(),
            'parent_id' => (int) $product->get_parent_id(),
            'attributes_text' => wc_suf_get_product_attributes_text( $product ),
            'old_qty' => (float) $old_qty,
            'change_qty' => (float) $change_qty,
            'new_qty' => (float) $new_qty,
            'destination_old_qty' => (float) $old_qty,
            'destination_new_qty' => (float) $new_qty,
            'user_id' => $user_id > 0 ? $user_id : null,
            'user_login' => $user_display,
            'user_code' => $order_number,
            'created_at' => $created_at,
        ]
    );

    $wpdb->insert(
        $audit_table,
        [
            'batch_code'   => $batch_code,
            'csv_file_url' => null,
            'word_file_url'=> null,
            'op_type'      => 'sale_edit',
            'purpose'      => $purpose,
            'print_label'  => 0,
            'product_id'   => (int) $product->get_id(),
            'product_name' => wc_suf_full_product_label( $product ),
            'old_qty'      => (float) $old_qty,
            'added_qty'    => (float) $change_qty,
            'new_qty'      => (float) $new_qty,
            'user_id'      => $user_id > 0 ? $user_id : null,
            'user_login'   => $user_display,
            'user_code'    => $order_number,
            'ip'           => '',
            'created_at'   => $created_at,
        ]
    );
}

function wc_suf_reconcile_sale_order_items( $order, $requested_items, &$warnings = [] ) {
    $warnings = [];
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        return new WP_Error( 'invalid_order', 'دسترسی به سفارش ممکن نیست.' );
    }

    $desired = [];
    foreach ( (array) $requested_items as $it ) {
        $pid = isset($it['id']) ? absint($it['id']) : 0;
        $qty = isset($it['qty']) ? max( 0, (int) $it['qty'] ) : 0;
        if ( $pid <= 0 ) continue;
        $desired[$pid] = $qty;
    }

    $existing_items = [];
    foreach ( $order->get_items('line_item') as $item_id => $item ) {
        if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) continue;
        $pid = (int) $item->get_variation_id();
        if ( $pid <= 0 ) $pid = (int) $item->get_product_id();
        if ( $pid <= 0 ) continue;
        $existing_items[ $pid ] = ['item_id' => $item_id, 'item' => $item];
    }

    $all_ids = array_unique( array_merge( array_keys( $existing_items ), array_keys( $desired ) ) );
    $breakdown = [];

    foreach ( $all_ids as $pid ) {
        $target_qty = max( 0, (int) ( $desired[$pid] ?? 0 ) );
        $entry = $existing_items[$pid] ?? null;
        $allocated_old = $entry ? max( 0, (int) $entry['item']->get_quantity() ) : 0;
        $allocated_new = $allocated_old;
        $pending_qty = 0;
        $product = wc_get_product( $pid );
        $product_name = $product ? wc_suf_full_product_label( $product ) : ( 'محصول #' . $pid );

        if ( $target_qty > $allocated_old ) {
            $need = $target_qty - $allocated_old;
            $have = (int) ( $product ? $product->get_stock_quantity() : 0 );
            $alloc_delta = min( $need, max( 0, $have ) );
            if ( $alloc_delta > 0 && $product ) {
                $old_stock = $have;
                wc_update_product_stock( $product, $alloc_delta, 'decrease' );
                $new_stock = max( 0, $old_stock - $alloc_delta );
                wc_suf_log_sale_edit_change( $order, $product, -1 * $alloc_delta, $old_stock, $new_stock, $target_qty, max(0, $need - $alloc_delta) );
            }
            $allocated_new = $allocated_old + $alloc_delta;
            if ( $alloc_delta < $need ) {
                $warnings[] = sprintf('موجودی محصول #%d محدود بود و بخشی از مقدار به‌صورت در انتظار باقی ماند.', $pid);
            }
        } elseif ( $target_qty < $allocated_old ) {
            $release = $allocated_old - $target_qty;
            if ( $release > 0 && $product ) {
                $old_stock = (int) ( $product->get_stock_quantity() ?? 0 );
                wc_update_product_stock( $product, $release, 'increase' );
                $new_stock = $old_stock + $release;
                wc_suf_log_sale_edit_change( $order, $product, $release, $old_stock, $new_stock, $target_qty, 0 );
            }
            $allocated_new = $target_qty;
        }

        if ( $allocated_new <= 0 ) {
            if ( $entry ) {
                $order->remove_item( $entry['item_id'] );
            }
        } else {
            if ( $entry ) {
                $entry['item']->set_quantity( $allocated_new );
                $order->add_item( $entry['item'] );
            } elseif ( $product ) {
                $order->add_product( $product, $allocated_new );
            }
        }

        $pending_qty = max( 0, $target_qty - $allocated_new );
        if ( $target_qty > 0 || $allocated_new > 0 || $pending_qty > 0 ) {
            $breakdown[] = [
                'product_id'    => $pid,
                'product_name'  => $product_name,
                'requested_qty' => $target_qty,
                'allocated_qty' => $allocated_new,
                'pending_qty'   => $pending_qty,
            ];
        }
    }

    return $breakdown;
}

function wc_suf_sync_sale_hold_order_handler(){
    check_ajax_referer('wc_suf_sync_sale_hold_order');

    if( ! wc_suf_current_user_is_pos_manager() ){
        wp_send_json_error(['message'=>'دسترسی غیرمجاز.']);
    }
    if ( ! function_exists( 'wc_create_order' ) ) {
        wp_send_json_error(['message'=>'ووکامرس فعال نیست.']);
    }

    $raw_items = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
    $items = json_decode($raw_items, true);
    if ( ! is_array($items) ) {
        $items = [];
    }

    $op_type_in = isset($_POST['op_type']) ? sanitize_text_field( wp_unslash($_POST['op_type']) ) : '';
    $op_type = in_array($op_type_in, ['sale','sale_teh'], true) ? $op_type_in : '';
    if ( ! $op_type ) {
        wp_send_json_error(['message'=>'نوع عملیات فروش معتبر نیست.']);
    }

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $customer_name = isset($_POST['sale_customer_name']) ? sanitize_text_field( wp_unslash($_POST['sale_customer_name']) ) : '';
    $customer_mobile = isset($_POST['sale_customer_mobile']) ? sanitize_text_field( wp_unslash($_POST['sale_customer_mobile']) ) : '';
    $customer_mobile = preg_replace('/\D+/', '', wc_suf_normalize_digits( $customer_mobile ) );
    $customer_address = isset($_POST['sale_customer_address']) ? sanitize_textarea_field( wp_unslash($_POST['sale_customer_address']) ) : '';
    $sale_method_raw = isset($_POST['sale_method']) ? wp_unslash($_POST['sale_method']) : '';
    $sale_method = wc_suf_validate_sale_method_for_current_user( $sale_method_raw );
    if ( '' === $sale_method ) {
        wp_send_json_error(['message'=>'نحوه فروش معتبر نیست.']);
    }

    $desired = [];
    foreach ( $items as $it ) {
        $pid = isset($it['id']) ? absint($it['id']) : 0;
        $qty = isset($it['qty']) ? (int) $it['qty'] : 0;
        if ( $pid <= 0 || $qty <= 0 ) continue;
        $desired[ $pid ] = ( $desired[ $pid ] ?? 0 ) + $qty;
    }

    $user = wp_get_current_user();
    $uid  = (int) ($user->ID ?? 0);
    $ulog = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
    if ( $ulog === '' ) {
        $ulog = (string) ( $user->display_name ?: $user->user_login );
    }

    $is_new_hold_order = false;
    if ( $order_id > 0 ) {
        $order = wc_get_order( $order_id );
    } else {
        $order = wc_create_order();
        $order->set_created_via( 'wc_suf_manual_sale_hold' );
        $order->set_status( 'initialorder', 'ایجاد اولیه سفارش هولد از فرم فروش.' );
        $is_new_hold_order = true;
    }
    if ( ! $order ) {
        wp_send_json_error(['message'=>'دسترسی به سفارش هولد ممکن نیست.']);
    }
    if ( $order_id > 0 ) {
        $order_error = '';
        if ( ! wc_suf_validate_sale_order_edit_access( $order, $uid, $order_error ) ) {
            wp_send_json_error(['message'=> $order_error ?: 'این سفارش قابل همگام‌سازی با فرم فروش نیست.']);
        }
    }

    $existing_items = [];
    foreach ( $order->get_items('line_item') as $item_id => $item ) {
        if ( ! is_a($item, 'WC_Order_Item_Product') ) continue;
        $pid = (int) $item->get_variation_id();
        if ( $pid <= 0 ) $pid = (int) $item->get_product_id();
        if ( $pid <= 0 ) continue;
        $existing_items[ $pid ] = ['item_id' => $item_id, 'item' => $item];
    }

    $sync_warnings = [];
    foreach ( $existing_items as $pid => $entry ) {
        $old_qty = max(0, (int) $entry['item']->get_quantity());
        $target_qty = max(0, (int) ($desired[ $pid ] ?? 0));
        $new_qty = $target_qty;
        $delta = $new_qty - $old_qty;
        if ( $delta > 0 ) {
            $product = wc_get_product( $pid );
            $have = (int) ( $product ? $product->get_stock_quantity() : 0 );
            $alloc_delta = min( $delta, max( 0, $have ) );
            $new_qty = $old_qty + $alloc_delta;
            if ( $alloc_delta > 0 ) {
                wc_update_product_stock( $product, $alloc_delta, 'decrease' );
            }
            if ( $alloc_delta < $delta ) {
                $sync_warnings[] = sprintf('موجودی محصول #%d محدود بود و بخشی از مقدار به‌صورت در انتظار باقی ماند.', $pid);
            }
        } elseif ( $delta < 0 ) {
            $product = wc_get_product( $pid );
            wc_update_product_stock( $product, abs($delta), 'increase' );
        }

        if ( $new_qty <= 0 ) {
            $order->remove_item( $entry['item_id'] );
        } else {
            $entry['item']->set_quantity( $new_qty );
            $order->add_item( $entry['item'] );
        }
        unset($desired[ $pid ]);
    }

    foreach ( $desired as $pid => $qty ) {
        $product = wc_get_product( $pid );
        if ( ! $product || $qty <= 0 ) continue;
        $have = (int) ( $product->get_stock_quantity() ?? 0 );
        $alloc_qty = min( $qty, max(0, $have) );
        if ( $alloc_qty > 0 ) {
            $order->add_product( $product, $alloc_qty );
            wc_update_product_stock( $product, $alloc_qty, 'decrease' );
        }
        if ( $alloc_qty < $qty ) {
            $sync_warnings[] = sprintf('موجودی محصول #%d محدود بود و بخشی از مقدار به‌صورت در انتظار باقی ماند.', $pid);
        }
    }

    $order->set_address([
        'first_name' => $customer_name,
        'last_name'  => '.',
        'phone'      => $customer_mobile,
        'address_1'  => $customer_address,
    ], 'billing');
    $order->update_meta_data( 'فروشنده', $ulog ?: $user->user_login );
    $order->update_meta_data( '_wc_suf_seller_name', $ulog ?: $user->user_login );
    $order->update_meta_data( '_wc_suf_seller_id', $uid ?: 0 );
    $order->update_meta_data( '_wc_suf_sale_channel', ( $op_type === 'sale_teh' ? 'tehranpars' : 'main' ) );
    $order->update_meta_data( '_wc_suf_sale_operation', $op_type );
    $order->update_meta_data( '_wc_suf_sale_hold_active', 'yes' );
    if ( $is_new_hold_order ) {
        $order->update_meta_data( '_wc_suf_sale_hold_started_at', time() );
    }
    $order->update_meta_data( '_wc_suf_sale_customer_name', $customer_name );
    $order->update_meta_data( '_wc_suf_sale_customer_mobile', $customer_mobile );
    $order->update_meta_data( '_wc_suf_sale_customer_address', $customer_address );
    $order->update_meta_data( '_wc_suf_sale_method', $sale_method );
    $order->update_meta_data( '_wc_suf_sale_method_label', wc_suf_get_sale_method_labels()[ $sale_method ] );
    $order->update_meta_data( 'sale_method', $sale_method );
    $order->update_meta_data( 'sale_method_label', wc_suf_get_sale_method_labels()[ $sale_method ] );
    $order->update_meta_data( 'نحوه فروش', wc_suf_get_sale_method_labels()[ $sale_method ] );
    $order->calculate_totals();
    $order->save();

    if ( $is_new_hold_order && 'yes' !== $order->get_meta( '_wc_suf_sale_hold_logged', true ) ) {
        wc_suf_log_sale_hold_event( $order, 'sale_hold', 'هولد اولیه سفارش فروش', -1 );
        $order->update_meta_data( '_wc_suf_sale_hold_logged', 'yes' );
        $order->save_meta_data();
    }

    wc_suf_schedule_sale_hold_expiry( $order->get_id() );

    wp_send_json_success([
        'order_id' => (int) $order->get_id(),
        'order_number' => (string) $order->get_order_number(),
        'warnings' => $sync_warnings,
    ]);
}

function wc_suf_save_stock_update_handler(){
    check_ajax_referer('save_stock_update');

    if( ! wc_suf_current_user_is_pos_manager() ){
        wp_send_json_error(['message'=>'دسترسی غیرمجاز.']);
    }

    $raw   = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
    $items = json_decode($raw, true);
    if ( ! is_array($items) || empty($items) ) {
        wp_send_json_error(['message'=>'داده‌ای ارسال نشده است.']);
    }

    $user_code   = isset($_POST['user_code']) ? sanitize_text_field( wp_unslash($_POST['user_code']) ) : '';
    $op_type_in  = isset($_POST['op_type']) ? sanitize_text_field( wp_unslash($_POST['op_type']) ) : '';
    $op_type     = in_array($op_type_in, ['in','out','transfer','return','onlyLabel','sale','sale_teh'], true) ? $op_type_in : '';
    $allowed_ops = wc_suf_get_allowed_ops_for_current_user();
    $is_marjoo_only_user = wc_suf_is_marjoo_only_user();

    if( ! $op_type ){
        wp_send_json_error(['message'=>'نوع عملیات مشخص نیست (ورود/خروج/انتقال/مرجوعی/فروش/فروش تهرانپارس/صرفاً چاپ لیبل).']);
    }
    if ( ! in_array( $op_type, $allowed_ops, true ) ) {
        wp_send_json_error(['message'=>'شما به نوع عملیات انتخابی دسترسی ندارید.']);
    }

    $out_destination = isset($_POST['out_destination']) ? sanitize_text_field( wp_unslash($_POST['out_destination']) ) : '';
    $transfer_source = isset($_POST['transfer_source']) ? sanitize_text_field( wp_unslash($_POST['transfer_source']) ) : '';
    $transfer_destination = isset($_POST['transfer_destination']) ? sanitize_text_field( wp_unslash($_POST['transfer_destination']) ) : '';
    $return_destination = isset($_POST['return_destination']) ? sanitize_text_field( wp_unslash($_POST['return_destination']) ) : '';
    $return_reason = isset($_POST['return_reason']) ? sanitize_text_field( wp_unslash($_POST['return_reason']) ) : '';
    $sale_customer_name = isset($_POST['sale_customer_name']) ? sanitize_text_field( wp_unslash($_POST['sale_customer_name']) ) : '';
    $sale_customer_mobile = isset($_POST['sale_customer_mobile']) ? sanitize_text_field( wp_unslash($_POST['sale_customer_mobile']) ) : '';
    $sale_customer_mobile = wc_suf_normalize_digits( $sale_customer_mobile );
    $sale_customer_address = isset($_POST['sale_customer_address']) ? sanitize_textarea_field( wp_unslash($_POST['sale_customer_address']) ) : '';
    $sale_method_raw = isset($_POST['sale_method']) ? wp_unslash($_POST['sale_method']) : '';
    $sale_method = '';
    $sale_hold_order_id = isset($_POST['sale_hold_order_id']) ? absint($_POST['sale_hold_order_id']) : 0;
    $sale_submit_mode_in = isset($_POST['sale_submit_mode']) ? sanitize_text_field( wp_unslash($_POST['sale_submit_mode']) ) : 'final';
    $sale_submit_mode = in_array( $sale_submit_mode_in, ['final','pending_review'], true ) ? $sale_submit_mode_in : 'final';
    $transfer_store_id = null;
    if ( $op_type === 'out' ) {
        if ( ! in_array( $out_destination, ['main','teh'], true ) ) {
            wp_send_json_error(['message'=>'مقصد خروج مشخص نیست.']);
        }
        if ( $out_destination === 'teh' ) {
            $transfer_store_id = (int) WC_SUF_TEHRANPARS_STORE_ID;
        }
    }
    if ( $op_type === 'transfer' ) {
        if ( ! in_array( $transfer_source, ['main','teh'], true ) ) {
            wp_send_json_error(['message'=>'انبار مبدا انتقال مشخص نیست.']);
        }
        if ( ! in_array( $transfer_destination, ['main','teh'], true ) ) {
            wp_send_json_error(['message'=>'انبار مقصد انتقال مشخص نیست.']);
        }
        if ( $transfer_source === $transfer_destination ) {
            wp_send_json_error(['message'=>'انبار مبدا و مقصد انتقال نمی‌توانند یکسان باشند.']);
        }
    }
    if ( $op_type === 'return' ) {
        if ( ! in_array( $return_destination, ['main','teh'], true ) ) {
            wp_send_json_error(['message'=>'انبار مرجوعی مشخص نیست.']);
        }
        if ( $is_marjoo_only_user && $return_destination !== 'teh' ) {
            wp_send_json_error(['message'=>'کاربر مرجوع فقط مجاز به مرجوعی به انبار تهران پارس است.']);
        }
        $valid_return_reasons = [
            'انصراف از خرید مشتری',
            'تعویض طرح یا رنگ',
            'خرابی کالا (استوک)',
        ];
        if ( ! in_array( $return_reason, $valid_return_reasons, true ) ) {
            wp_send_json_error(['message'=>'علت مرجوعی معتبر نیست.']);
        }
        if ( $return_destination === 'teh' ) {
            $transfer_store_id = (int) WC_SUF_TEHRANPARS_STORE_ID;
        }
    }
    if ( $op_type === 'sale' || $op_type === 'sale_teh' ) {
        $sale_method = wc_suf_validate_sale_method_for_current_user( $sale_method_raw );
        if ( '' === $sale_method ) {
            wp_send_json_error(['message'=>'نحوه فروش معتبر نیست.']);
        }
        if ( $sale_submit_mode === 'pending_review' && in_array( $sale_method, ['main_onsite', 'tehranpars_onsite'], true ) ) {
            wp_send_json_error(['message'=>'برای نحوه فروش حضوری، ثبت در انتظار مجاز نیست.']);
        }
        if ( mb_strlen( trim( $sale_customer_name ) ) < 3 ) {
            wp_send_json_error(['message'=>'نام و نام خانوادگی مشتری معتبر نیست.']);
        }
        $sale_customer_mobile = preg_replace('/\D+/', '', wc_suf_normalize_digits( $sale_customer_mobile ) );
        if ( ! preg_match('/^0\d{10}$/', $sale_customer_mobile) ) {
            wp_send_json_error(['message'=>'شماره موبایل باید با 0 شروع شود و دقیقاً 11 رقم باشد.']);
        }
        if ( mb_strlen( trim( $sale_customer_address ) ) < 8 ) {
            wp_send_json_error(['message'=>'آدرس مشتری معتبر نیست.']);
        }
        $out_destination = 'main';
    }

    $user      = wp_get_current_user();
    $uid       = (int) ($user->ID ?? 0);
    $ulog      = '';
    if ( $uid ) {
        $ulog = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
        if ( $ulog === '' ) {
            $ulog = (string) ( $user->display_name ?: $user->user_login );
        }
    }
    $ip        = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( wp_unslash($_SERVER['REMOTE_ADDR']) ) : '';

    global $wpdb;
    $table   = $wpdb->prefix.'stock_audit';
    $move_table = $wpdb->prefix.'stock_production_moves';

    $tx_started = false;
    if ( in_array( $op_type, ['in','out','transfer','return','onlyLabel','sale','sale_teh'], true ) ) {
        $tx_started = ( false !== $wpdb->query('START TRANSACTION') );
        if ( ! $tx_started ) {
            wp_send_json_error(['message'=>'شروع تراکنش دیتابیس ناموفق بود. عملیات برای جلوگیری از ثبت ناقص متوقف شد.']);
        }
    }

    $sale_edit_existing_qty_map = [];
    if ( ( $op_type === 'sale' || $op_type === 'sale_teh' ) && $sale_hold_order_id > 0 ) {
        $sale_edit_order = wc_get_order( $sale_hold_order_id );
        if ( ! $sale_edit_order ) {
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message' => 'سفارش انتخاب‌شده برای ویرایش یافت نشد.']);
        }

        $sale_edit_order_error = '';
        if ( ! wc_suf_validate_sale_order_edit_access( $sale_edit_order, $uid, $sale_edit_order_error ) ) {
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message' => $sale_edit_order_error ?: 'این سفارش قابل ویرایش نیست.']);
        }
        if ( ! wc_suf_is_sale_order_editable_in_finalize( $sale_edit_order ) ) {
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message' => 'این سفارش نهایی شده و قابل ویرایش نیست.']);
        }

        foreach ( $sale_edit_order->get_items('line_item') as $existing_item ) {
            if ( ! is_a( $existing_item, 'WC_Order_Item_Product' ) ) {
                continue;
            }
            $existing_pid = (int) $existing_item->get_variation_id();
            if ( $existing_pid <= 0 ) {
                $existing_pid = (int) $existing_item->get_product_id();
            }
            if ( $existing_pid <= 0 ) {
                continue;
            }
            $sale_edit_existing_qty_map[ $existing_pid ] = max( 0, (int) $existing_item->get_quantity() );
        }
    }

    if ($op_type === 'out' || $op_type === 'transfer' || $op_type === 'sale' || $op_type === 'sale_teh') {
        $insufficient = [];
        $locked_old_qty = [];
        $locked_prod_qty = [];
        foreach($items as $it){
            $pid = isset($it['id'])  ? absint($it['id']) : 0;
            $req = isset($it['qty']) ? (int) $it['qty']  : 0;
            if( ! $pid || $req <= 0 ) continue;

            $product = wc_get_product($pid);
            if( ! $product ) continue;
            if ( $op_type === 'out' ) {
                $old = $tx_started ? wc_suf_get_production_stock_qty_for_update( $product ) : wc_suf_get_production_stock_qty( $pid );
            } elseif ( $op_type === 'sale' || $op_type === 'sale_teh' ) {
                $old = (int) ( wc_suf_get_stock_product( $product )->get_stock_quantity() ?? 0 );
                if ( $tx_started ) {
                    $locked_prod_qty[$pid] = wc_suf_get_production_stock_qty_for_update_strict( $product );
                    if ( is_wp_error( $locked_prod_qty[$pid] ) ) {
                        $wpdb->query('ROLLBACK');
                        wp_send_json_error(['message'=>$locked_prod_qty[$pid]->get_error_message()]);
                    }
                } else {
                    $locked_prod_qty[$pid] = wc_suf_get_production_stock_qty( $pid );
                }
            } else {
                if ( $transfer_source === 'main' ) {
                    $old = (int) ( wc_suf_get_stock_product( $product )->get_stock_quantity() ?? 0 );
                } else {
                    $teh_old = wc_suf_yith_get_store_stock_qty( $product, (int) WC_SUF_TEHRANPARS_STORE_ID );
                    $old = ( false === $teh_old ) ? 0 : (int) $teh_old;
                }
            }
            $pname = wc_suf_full_product_label( $product );
            $locked_old_qty[$pid] = $old;

            $effective_available = $old;
            if ( $op_type === 'sale' || $op_type === 'sale_teh' ) {
                $effective_available += (int) ( $sale_edit_existing_qty_map[ $pid ] ?? 0 );
            }

            if( $req > $effective_available ){
                if ( in_array( $op_type, ['sale','sale_teh'], true ) && $sale_submit_mode === 'pending_review' ) {
                    continue;
                }
                $insufficient[] = [
                    'id'   => $pid,
                    'name' => $pname,
                    'req'  => $req,
                    'have' => $effective_available,
                ];
            }
        }

        if ( ! empty($insufficient) ) {
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            $lines = array_map(function($r){
                return sprintf('محصول %s (ID: %d): درخواست %d، موجودی فعلی %d', $r['name'], $r['id'], $r['req'], $r['have']);
            }, $insufficient);

            $operation_label = ( $op_type === 'transfer' ) ? 'انتقال' : ( ( $op_type === 'sale' || $op_type === 'sale_teh' ) ? 'فروش' : 'خروج' );
            $msg = "ثبت ناموفق؛ به‌دلیل کمبود موجودی موارد زیر امکان {$operation_label} ندارند:\n- " . implode("\n- ", $lines) . "\n\nلطفاً مقادیر را اصلاح کنید و دوباره تلاش کنید.";
            wp_send_json_error(['message' => $msg]);
        }
    }

    if ( count($items) > 1000 ) {
        if ( $tx_started ) {
            $wpdb->query('ROLLBACK');
        }
        wp_send_json_error(['message'=>'حداکثر 1000 محصول در هر ثبت قابل پردازش است. لطفاً ثبت را در چند مرحله انجام دهید.']);
    }

    $is_sale_operation = in_array( $op_type, ['sale','sale_teh'], true );
    $batch_code = $is_sale_operation
        ? ''
        : wc_suf_next_batch_code( $op_type === 'return' ? 'return' : ( $op_type === 'out' ? 'out' : ( $op_type === 'transfer' ? 'transfer' : $op_type ) ) );

    $inserted = 0;
    $processed_items = 0;
    $csv_rows = [];
    $sale_order = null;
    $sale_pending_breakdown = [];
    $sale_reconcile_warnings = [];

    foreach($items as $it){
        $pid = isset($it['id'])  ? absint($it['id']) : 0;
        $req = isset($it['qty']) ? (int) $it['qty']  : 0;
        if( ! $pid || $req <= 0 ) continue;

        $product = wc_get_product($pid);
        if( ! $product ) continue;

        $processed_items++;
        $stock_product = wc_suf_get_stock_product( $product );

        if( ! $stock_product->managing_stock() ){
            $stock_product->set_manage_stock(true);
            if( $stock_product->get_stock_quantity() === null ){
                $stock_product->set_stock_quantity(0);
            }
            $stock_product->save();
        }

        $old_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
        $pname   = $stock_product->get_name();
        $destination_old_qty = null;
        $destination_new_qty = null;

        if( $op_type === 'out' ){
            $prod_old = isset($locked_old_qty[$pid]) ? (int) $locked_old_qty[$pid] : ( $tx_started ? wc_suf_get_production_stock_qty_for_update( $product ) : wc_suf_get_production_stock_qty( $pid ) );
            $prod_new = max( 0, $prod_old - $req );
            $prod_update_result = wc_suf_set_production_stock_qty( $product, $prod_new );
            if ( is_wp_error( $prod_update_result ) ) {
                if ( $tx_started ) {
                    $wpdb->query('ROLLBACK');
                }
                wp_send_json_error(['message'=>$prod_update_result->get_error_message()]);
            }
            $old_qty      = $prod_old;
            $new_qty      = $prod_new;
            $logged_added = $req;

            if ( $out_destination === 'main' ) {
                $destination_old_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
                $main_stock_result = wc_update_product_stock($stock_product, $req, 'increase');
                if ( false === $main_stock_result ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی انبار اصلی ووکامرس ناموفق بود.']);
                }
                $stock_product->save();
                $destination_new_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
            } elseif ( $out_destination === 'teh' ) {
                $destination_old_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_old_qty ) {
                    $destination_old_qty = 0;
                }
                $store_result  = wc_suf_yith_change_store_stock( $stock_product, $req, $transfer_store_id, 'increase' );
                if ( is_wp_error( $store_result ) ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی استور YITH ناموفق: '.$store_result->get_error_message()]);
                }
                $destination_new_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_new_qty ) {
                    $destination_new_qty = (int) $destination_old_qty + $req;
                }
            }

        } elseif( $op_type === 'transfer' ){
            $transfer_store_id = (int) WC_SUF_TEHRANPARS_STORE_ID;
            if ( $transfer_source === 'main' ) {
                $source_old = isset($locked_old_qty[$pid]) ? (int) $locked_old_qty[$pid] : (int) ( $stock_product->get_stock_quantity() ?? 0 );
                $main_stock_result = wc_update_product_stock($stock_product, $req, 'decrease');
                if ( false === $main_stock_result ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'کاهش موجودی انبار اصلی ووکامرس برای انتقال ناموفق بود.']);
                }
                $stock_product->save();
                $source_new = (int) ( $stock_product->get_stock_quantity() ?? 0 );

                $destination_old_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_old_qty ) {
                    $destination_old_qty = 0;
                }
                $store_result = wc_suf_yith_change_store_stock( $stock_product, $req, $transfer_store_id, 'increase' );
                if ( is_wp_error( $store_result ) ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی انبار تهران‌پارس برای انتقال ناموفق: '.$store_result->get_error_message()]);
                }
                $destination_new_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_new_qty ) {
                    $destination_new_qty = (int) $destination_old_qty + $req;
                }
            } else {
                $source_old_raw = isset($locked_old_qty[$pid]) ? (int) $locked_old_qty[$pid] : wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                $source_old = ( false === $source_old_raw ) ? 0 : (int) $source_old_raw;
                $store_result = wc_suf_yith_change_store_stock( $stock_product, $req, $transfer_store_id, 'decrease' );
                if ( is_wp_error( $store_result ) ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'کاهش موجودی انبار تهران‌پارس برای انتقال ناموفق: '.$store_result->get_error_message()]);
                }
                $source_new_raw = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                $source_new = ( false === $source_new_raw ) ? max( 0, $source_old - $req ) : (int) $source_new_raw;

                $destination_old_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
                $main_stock_result = wc_update_product_stock($stock_product, $req, 'increase');
                if ( false === $main_stock_result ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی انبار اصلی ووکامرس برای انتقال ناموفق بود.']);
                }
                $stock_product->save();
                $destination_new_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
            }

            $old_qty = (float) $source_old;
            $new_qty = (float) $source_new;
            $logged_added = $req;

        } elseif( $op_type === 'in' ){
            $prod_old = $tx_started ? wc_suf_get_production_stock_qty_for_update( $product ) : wc_suf_get_production_stock_qty( $pid );
            $prod_new = max( 0, $prod_old + $req );
            $prod_update_result = wc_suf_set_production_stock_qty( $product, $prod_new );
            if ( is_wp_error( $prod_update_result ) ) {
                if ( $tx_started ) {
                    $wpdb->query('ROLLBACK');
                }
                wp_send_json_error(['message'=>$prod_update_result->get_error_message()]);
            }
            $old_qty      = $prod_old;
            $new_qty      = $prod_new;
            $logged_added = $req;
        } elseif ( $op_type === 'sale' || $op_type === 'sale_teh' ) {
            $old_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
            $allocated_qty = min( $req, max( 0, $old_qty ) );
            $pending_qty = max( 0, $req - $allocated_qty );
            $sale_pending_breakdown[] = [
                'product_id' => $pid,
                'product_name' => $pname,
                'requested_qty' => $req,
                'allocated_qty' => $allocated_qty,
                'pending_qty' => $pending_qty,
            ];
            $new_qty = max( 0, (int) $old_qty - $allocated_qty );
            $logged_added = $allocated_qty;

        } elseif( $op_type === 'return' ){
            $logged_added = $req;
            if ( $return_destination === 'main' ) {
                $destination_old_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
                $main_stock_result = wc_update_product_stock($stock_product, $req, 'increase');
                if ( false === $main_stock_result ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی انبار اصلی ووکامرس برای مرجوعی ناموفق بود.']);
                }
                $stock_product->save();
                $destination_new_qty = (int) ( $stock_product->get_stock_quantity() ?? 0 );
            } elseif ( $return_destination === 'teh' ) {
                $destination_old_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_old_qty ) {
                    $destination_old_qty = 0;
                }
                $store_result  = wc_suf_yith_change_store_stock( $stock_product, $req, $transfer_store_id, 'increase' );
                if ( is_wp_error( $store_result ) ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'افزایش موجودی انبار تهران‌پارس برای مرجوعی ناموفق: '.$store_result->get_error_message()]);
                }
                $destination_new_qty = wc_suf_yith_get_store_stock_qty( $stock_product, $transfer_store_id );
                if ( false === $destination_new_qty ) {
                    $destination_new_qty = (int) $destination_old_qty + $req;
                }
            }
            $old_qty = (float) $destination_old_qty;
            $new_qty = (float) $destination_new_qty;

        } else {
            $new_qty      = $old_qty;
            $logged_added = $req;
        }

        if ( ! $is_sale_operation ) {
            $data = [
                'batch_code'   => $batch_code,
                'op_type'      => wc_suf_audit_op_type_for_storage( $op_type, $out_destination, $return_destination, $transfer_source, $transfer_destination ),
                'purpose'      => ($op_type === 'out')
                                ? ( $out_destination === 'teh' ? 'انتقال به انبار تهرانپارس' : 'خروج به انبار اصلی' )
                                : ( ( $op_type === 'transfer' )
                                    ? ( 'انتقال بین انبارها: ' . wc_suf_destination_label( $transfer_source ) . ' → ' . wc_suf_destination_label( $transfer_destination ) )
                                    : ( ( $op_type === 'return' ) ? ('مرجوعی - علت: '.$return_reason) : ( ( $op_type === 'sale' || $op_type === 'sale_teh' ) ? 'فروش و ثبت سفارش ووکامرس' : null ) ) ),
                'print_label'  => ($op_type === 'onlyLabel') ? 1 : 0,
                'product_id'   => $pid,
                'product_name' => $pname,
                'old_qty'      => $old_qty,
                'added_qty'    => $logged_added,
                'new_qty'      => $new_qty,
                'user_id'      => $uid ?: null,
                'user_login'   => $ulog ?: null,
                'user_code'    => $user_code ?: null,
                'ip'           => $ip ?: null,
                'created_at'   => current_time('mysql'),
            ];
            $formats = ['%s','%s','%s','%d','%d','%s','%f','%f','%f','%d','%s','%s','%s','%s'];

            $ok = $wpdb->insert( $table, $data, $formats );
            if( false === $ok ){
                if ( $tx_started ) {
                    $wpdb->query('ROLLBACK');
                }
                error_log('[WC Stock Update] DB Insert FAILED: '.$wpdb->last_error.' | Data: '.wp_json_encode($data));
                wp_send_json_error(['message'=>'ثبت در پایگاه‌داده ناموفق بود.']);
            } else {
                $inserted++;
            }
        }

        if ( ! $is_sale_operation && in_array( $op_type, ['in','out','transfer','return','onlyLabel','sale','sale_teh'], true ) ) {
            $base_move_data = [
                'batch_code'      => $batch_code,
                'operation'       => $op_type,
                'product_id'      => $pid,
                'product_name'    => wc_suf_full_product_label( $product ),
                'sku'             => $product->get_sku() ?: null,
                'product_type'    => $product->get_type(),
                'parent_id'       => $product->is_type('variation') ? $product->get_parent_id() : null,
                'attributes_text' => wc_suf_get_product_attributes_text( $product ),
                'user_id'         => $uid ?: null,
                'user_login'      => $ulog ?: null,
                'user_code'       => $user_code ?: null,
                'created_at'      => current_time('mysql'),
            ];

            $move_rows = [];
            if ( $op_type === 'transfer' ) {
                $move_rows[] = array_merge(
                    $base_move_data,
                    [
                        'destination'         => $transfer_source,
                        'old_qty'             => (float) $old_qty,
                        'change_qty'          => (float) ( -1 * $req ),
                        'new_qty'             => (float) $new_qty,
                        'destination_old_qty' => null,
                        'destination_new_qty' => null,
                    ]
                );
                $move_rows[] = array_merge(
                    $base_move_data,
                    [
                        'destination'         => $transfer_destination,
                        'old_qty'             => ( $destination_old_qty === null ? 0.0 : (float) $destination_old_qty ),
                        'change_qty'          => (float) $req,
                        'new_qty'             => ( $destination_new_qty === null ? (float) $req : (float) $destination_new_qty ),
                        'destination_old_qty' => null,
                        'destination_new_qty' => null,
                    ]
                );
            } else {
                $move_rows[] = array_merge(
                    $base_move_data,
                    [
                        'destination'         => ( $op_type === 'out' ) ? $out_destination : ( $op_type === 'return' ? $return_destination : ( ( $op_type === 'sale' || $op_type === 'sale_teh' ) ? 'main' : ( $op_type === 'onlyLabel' ? 'label_only' : 'production' ) ) ),
                        'old_qty'             => (float) $old_qty,
                        'change_qty'          => (float) $logged_added,
                        'new_qty'             => (float) $new_qty,
                        'destination_old_qty' => ( $destination_old_qty === null ? null : (float) $destination_old_qty ),
                        'destination_new_qty' => ( $destination_new_qty === null ? null : (float) $destination_new_qty ),
                    ]
                );
            }

            foreach ( $move_rows as $move_data ) {
                $wpdb->insert( $move_table, $move_data );
                if ( ! empty($wpdb->last_error) ) {
                    if ( $tx_started ) {
                        $wpdb->query('ROLLBACK');
                    }
                    wp_send_json_error(['message'=>'ثبت لاگ حرکات انبار ناموفق بود.']);
                }
            }
        }

        $full_name = wc_suf_full_product_label($product);
        $price = wc_get_price_to_display( $product );
        $csv_rows[] = [
            'id'    => (string) $pid,
            'name'  => (string) $full_name,
            'price' => (string) $price,
            'qty'   => (string) $req,
            'sku'   => (string) ($product->get_sku() ?: ''),
        ];
    }

    if ( $processed_items === 0 ) {
        if ( $tx_started ) {
            $wpdb->query('ROLLBACK');
        }
        $msg = 'هیچ موردی ثبت نشد.' . ( $wpdb->last_error ? (' DB error: '.$wpdb->last_error) : '' );
        wp_send_json_error(['message'=>$msg]);
    }

    if ( $op_type === 'sale' || $op_type === 'sale_teh' ) {
        try {
            if ( $sale_hold_order_id > 0 ) {
                $sale_order = wc_get_order( $sale_hold_order_id );
                if ( ! $sale_order ) {
                    throw new Exception('سفارش هولد یافت نشد.');
                }
                $sale_order_error = '';
                if ( ! wc_suf_validate_sale_order_edit_access( $sale_order, $uid, $sale_order_error ) ) {
                    throw new Exception( $sale_order_error ?: 'این سفارش قابل ویرایش نیست.' );
                }
                if ( ! wc_suf_is_sale_order_editable_in_finalize( $sale_order ) ) {
                    throw new Exception('این سفارش نهایی شده و قابل ویرایش نیست.');
                }
            } else {
                $sale_order = wc_create_order();
            }

            $sale_order->set_created_via( 'wc_suf_manual_sale' );
            $sale_order->set_address([
                'first_name' => $sale_customer_name,
                'last_name'  => '.',
                'phone'      => $sale_customer_mobile,
                'address_1'  => $sale_customer_address,
            ], 'billing');
            $sale_order->update_meta_data( 'فروشنده', $ulog ?: $user->user_login );
            $sale_order->update_meta_data( '_wc_suf_seller_name', $ulog ?: $user->user_login );
            $sale_order->update_meta_data( '_wc_suf_seller_id', $uid ?: 0 );
            $sale_order->update_meta_data( '_wc_suf_sale_channel', ( $op_type === 'sale_teh' ? 'tehranpars' : 'main' ) );
            $sale_order->update_meta_data( '_wc_suf_sale_operation', $op_type );
            $sale_order->update_meta_data( '_wc_suf_sale_hold_active', 'no' );
            $sale_order->update_meta_data( '_wc_suf_sale_customer_name', $sale_customer_name );
            $sale_order->update_meta_data( '_wc_suf_sale_customer_mobile', $sale_customer_mobile );
            $sale_order->update_meta_data( '_wc_suf_sale_customer_address', $sale_customer_address );
            $sale_order->update_meta_data( '_wc_suf_sale_method', $sale_method );
            $sale_order->update_meta_data( '_wc_suf_sale_method_label', wc_suf_get_sale_method_labels()[ $sale_method ] );
            $sale_order->update_meta_data( 'sale_method', $sale_method );
            $sale_order->update_meta_data( 'sale_method_label', wc_suf_get_sale_method_labels()[ $sale_method ] );
            $sale_order->update_meta_data( 'نحوه فروش', wc_suf_get_sale_method_labels()[ $sale_method ] );
            $sale_order->update_meta_data( '_wc_suf_sale_submit_mode', $sale_submit_mode );

            $sale_pending_breakdown = wc_suf_reconcile_sale_order_items( $sale_order, $items, $sale_reconcile_warnings );
            if ( is_wp_error( $sale_pending_breakdown ) ) {
                throw new Exception( $sale_pending_breakdown->get_error_message() );
            }

            $sale_order->update_meta_data( '_wc_suf_pending_breakdown', wp_json_encode( $sale_pending_breakdown, JSON_UNESCAPED_UNICODE ) );
            $pending_qty_total = 0;
            $pending_qty_map = [];
            foreach ( $sale_pending_breakdown as $pending_row ) {
                $pending_qty = max( 0, (int) ( $pending_row['pending_qty'] ?? 0 ) );
                if ( $pending_qty <= 0 ) {
                    continue;
                }
                $pending_pid = absint( $pending_row['product_id'] ?? 0 );
                if ( $pending_pid <= 0 ) {
                    continue;
                }
                $pending_qty_total += $pending_qty;
                $pending_qty_map[ $pending_pid ] = $pending_qty;
            }
            $sale_order->update_meta_data( '_wc_suf_pending_qty_total', $pending_qty_total );
            $sale_order->update_meta_data( '_wc_suf_pending_qty_map', wp_json_encode( $pending_qty_map, JSON_UNESCAPED_UNICODE ) );
            $sale_order->update_meta_data( '_wc_qof_pending_items', wp_json_encode( array_keys( $pending_qty_map ), JSON_UNESCAPED_UNICODE ) );
            $sale_order->update_meta_data( '_wc_qof_pending_req_qty', wp_json_encode( $pending_qty_map, JSON_UNESCAPED_UNICODE ) );
            $sale_pending_price_map = wc_suf_build_pending_price_map_for_order( $sale_order, $sale_pending_breakdown );
            $sale_order->update_meta_data( '_wc_qof_pending_price_map', wp_json_encode( $sale_pending_price_map, JSON_UNESCAPED_UNICODE ) );
            wc_suf_update_pending_order_visible_meta( $sale_order, $sale_pending_breakdown );
            $sale_order->calculate_totals();
            if ( $sale_submit_mode === 'pending_review' ) {
                $sale_order->set_status( 'pendingreview', 'ثبت سفارش در وضعیت در انتظار از فرم فروش.' );
            } elseif ( $sale_method === 'main_onsite' ) {
                $sale_order->set_status( 'completed', 'ثبت سفارش حضوری انبار اصلی از فرم عملیات فروش و تکمیل مستقیم سفارش.' );
            } else {
                $sale_order->set_status( 'processing', 'ثبت سفارش از فرم عملیات فروش انبار تولید.' );
            }
            $sale_order->save();
            wc_suf_clear_sale_hold_expiry( $sale_order->get_id() );
        } catch ( Exception $e ) {
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message' => 'ساخت سفارش ووکامرس ناموفق بود: ' . $e->getMessage()]);
        }
    }

    $csv_file_url = '';
    $word_file_url = '';
    if ( ! empty($csv_rows) ) {
        $csv_result = null;
        $word_context = [
            'op_type'      => $is_sale_operation
                ? $op_type
                : ( $op_type === 'out'
                    ? ( $out_destination === 'teh' ? 'out_teh' : 'out_main' )
                    : ( $op_type === 'transfer'
                        ? ( $transfer_source === 'main' ? 'transfer_main_teh' : 'transfer_teh_main' )
                        : ( $op_type === 'return'
                            ? ( $return_destination === 'teh' ? 'return_teh' : 'return_main' )
                            : $op_type
                        )
                    )
                ),
            'purpose'      => $is_sale_operation
                ? 'رسید فروش'
                : ( $op_type === 'out'
                    ? ( $out_destination === 'teh' ? 'انتقال به انبار تهرانپارس' : 'خروج به انبار اصلی' )
                    : ( $op_type === 'transfer'
                        ? ( 'انتقال بین انبارها: ' . wc_suf_destination_label( $transfer_source ) . ' → ' . wc_suf_destination_label( $transfer_destination ) )
                        : ( $op_type === 'return' ? ('مرجوعی - علت: '.$return_reason) : null )
                    )
                ),
            'user_display' => $ulog ?: ( $uid ? ('user#'.$uid) : 'مهمان' ),
            'user_code'    => $user_code,
            'created_at'   => current_time('mysql'),
        ];
        $receipt_batch_code = ( $is_sale_operation && $sale_order && $sale_order->get_id() )
            ? (string) $sale_order->get_order_number()
            : $batch_code;

        if ( ! $is_sale_operation ) {
        $csv_result = wc_suf_generate_batch_label_html( $batch_code, $csv_rows );
        if ( is_wp_error( $csv_result ) ) {
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message'=>'ساخت صفحه چاپ لیبل ناموفق بود: '.$csv_result->get_error_message()]);
        }

        $csv_file_url = (string) ( $csv_result['url'] ?? '' );
        $csv_updated = $wpdb->update(
            $table,
            [ 'csv_file_url' => $csv_file_url ],
            [ 'batch_code' => $batch_code ],
            [ '%s' ],
            [ '%s' ]
        );
        if ( false === $csv_updated ) {
            if ( ! empty( $csv_result['path'] ) && file_exists( $csv_result['path'] ) ) {
                @unlink( $csv_result['path'] );
            }
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message'=>'ثبت لینک صفحه چاپ لیبل در دیتابیس ناموفق بود.']);
        }
        }
        $word_result = wc_suf_generate_batch_word_receipt( $receipt_batch_code, $word_context, $csv_rows );
        if ( is_wp_error( $word_result ) ) {
            if ( ! empty( $csv_result['path'] ) && file_exists( $csv_result['path'] ) ) {
                @unlink( $csv_result['path'] );
            }
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message'=>'ساخت فایل رسید HTML ناموفق بود: '.$word_result->get_error_message()]);
        }

        $word_file_url = (string) ( $word_result['url'] ?? '' );
        $word_updated = $wpdb->update(
            $table,
            [ 'word_file_url' => $word_file_url ],
            [ 'batch_code' => $batch_code ],
            [ '%s' ],
            [ '%s' ]
        );
        if ( false === $word_updated ) {
            if ( ! empty( $word_result['path'] ) && file_exists( $word_result['path'] ) ) {
                @unlink( $word_result['path'] );
            }
            if ( $tx_started ) {
                $wpdb->query('ROLLBACK');
            }
            wp_send_json_error(['message'=>'ثبت لینک رسید HTML در دیتابیس ناموفق بود.']);
        }
    }


    if ( $tx_started ) {
        $wpdb->query('COMMIT');
    }

    $op_label = wc_suf_op_label(
        $op_type === 'out'
        ? ( $out_destination === 'teh' ? 'out_teh' : 'out_main' )
        : ( $op_type === 'return'
            ? ( $return_destination === 'teh' ? 'return_teh' : 'return_main' )
            : $op_type )
    );
    $product_ids = [];
    foreach ( $csv_rows as $row ) {
        if ( isset( $row['id'] ) ) {
            $pid = absint( $row['id'] );
            if ( $pid > 0 ) {
                $product_ids[] = $pid;
            }
        }
    }
    $product_ids = array_values( array_unique( $product_ids ) );

    $message = "ثبت {$op_label} انجام شد.";
    if ( $is_sale_operation && $sale_submit_mode === 'pending_review' ) {
        $message = 'سفارش با وضعیت «در انتظار» ثبت شد.';
    }
    if ( ! $is_sale_operation ) {
        $message .= " کد ثبت: {$batch_code}";
    }
    if ( $sale_order && $sale_order->get_id() ) {
        $message .= ' | سفارش ووکامرس: #'.$sale_order->get_id();
    }
    $response_batch_code = ( $is_sale_operation && $sale_order && $sale_order->get_id() )
        ? (string) $sale_order->get_order_number()
        : $batch_code;

    wp_send_json_success([
        'message' => $message,
        'batch_code' => $response_batch_code,
        'csv_url' => $csv_file_url,
        'word_url' => $word_file_url,
        'order_id' => ( $sale_order && $sale_order->get_id() ) ? (int) $sale_order->get_id() : 0,
        'product_ids' => $product_ids,
        'warnings' => $sale_reconcile_warnings,
    ]);
}

function wc_suf_complete_pending_sale_handler(){
    check_ajax_referer('wc_suf_complete_pending_sale');

    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['message' => 'ابتدا وارد شوید.']);
    }

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if ( $order_id <= 0 ) {
        wp_send_json_error(['message' => 'شناسه سفارش نامعتبر است.']);
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_error(['message' => 'سفارش یافت نشد.']);
    }

    $current_user_id = get_current_user_id();
    $seller_id = (int) $order->get_meta('_wc_suf_seller_id', true);
    if ( $seller_id !== $current_user_id && ! current_user_can('manage_woocommerce') ) {
        wp_send_json_error(['message' => 'شما دسترسی تکمیل این سفارش را ندارید.']);
    }

    if ( ! $order->has_status( 'pendingreview' ) ) {
        wp_send_json_error(['message' => 'فقط سفارش‌های در انتظار قابل تکمیل یا بستن هستند.']);
    }

    $pending_raw = (string) $order->get_meta('_wc_suf_pending_breakdown', true);
    $pending_rows = json_decode( $pending_raw, true );
    if ( ! is_array($pending_rows) ) {
        $pending_rows = [];
    }

    if ( empty($pending_rows) ) {
        $order->update_meta_data( '_wc_suf_pending_breakdown', wp_json_encode( [], JSON_UNESCAPED_UNICODE ) );
        $order->update_meta_data( '_wc_suf_pending_qty_total', 0 );
        $order->update_meta_data( '_wc_suf_pending_qty_map', wp_json_encode( [], JSON_UNESCAPED_UNICODE ) );
        $order->update_meta_data( '_wc_qof_pending_items', wp_json_encode( [], JSON_UNESCAPED_UNICODE ) );
        $order->update_meta_data( '_wc_qof_pending_req_qty', wp_json_encode( [], JSON_UNESCAPED_UNICODE ) );
        $order->update_meta_data( '_wc_qof_pending_price_map', wp_json_encode( [], JSON_UNESCAPED_UNICODE ) );
        wc_suf_update_pending_order_visible_meta( $order, [] );
        $order->set_status( 'processing', 'بستن سفارش بدون اقلام در انتظار از لیست سفارش‌ها.' );
        $order->save();

        wp_send_json_success([
            'message' => 'سفارش بسته شد و به وضعیت «در حال انجام» رفت.',
            'allocated_now' => 0,
            'pending_qty_total' => 0,
            'product_ids' => [],
        ]);
    }

    $updated_breakdown = [];
    $allocated_now_total = 0;
    $processed_product_ids = [];

    foreach ( $pending_rows as $row ) {
        $pid = absint( $row['product_id'] ?? 0 );
        if ( $pid <= 0 ) {
            continue;
        }
        $requested_qty = max( 0, (int) ( $row['requested_qty'] ?? 0 ) );
        $already_allocated = max( 0, (int) ( $row['allocated_qty'] ?? 0 ) );
        $pending_qty = max( 0, (int) ( $row['pending_qty'] ?? 0 ) );

        if ( $pending_qty <= 0 ) {
            $updated_breakdown[] = [
                'product_id' => $pid,
                'product_name' => (string) ( $row['product_name'] ?? '' ),
                'requested_qty' => $requested_qty,
                'allocated_qty' => $already_allocated,
                'pending_qty' => 0,
            ];
            continue;
        }

        $product = wc_get_product( $pid );
        if ( ! $product ) {
            $updated_breakdown[] = $row;
            continue;
        }

        $stock_product = wc_suf_get_stock_product( $product );
        $available_now = max( 0, (int) ( $stock_product ? $stock_product->get_stock_quantity() : 0 ) );
        $alloc_now = min( $pending_qty, $available_now );
        $new_allocated = $already_allocated + $alloc_now;
        $new_pending = max( 0, $requested_qty - $new_allocated );

        if ( $alloc_now > 0 ) {
            $old_qty = $available_now;
            wc_update_product_stock( $product, $alloc_now, 'decrease' );
            $new_qty = max( 0, $old_qty - $alloc_now );
            $fresh_product = wc_get_product( $pid );
            if ( $fresh_product ) {
                $fresh_stock_product = wc_suf_get_stock_product( $fresh_product );
                if ( $fresh_stock_product ) {
                    $new_qty = max( 0, (float) ( $fresh_stock_product->get_stock_quantity() ?? $new_qty ) );
                }
            }

            wc_suf_log_pending_sale_allocation( $order, $product, $alloc_now, $old_qty, $new_qty, $requested_qty, $new_pending );

            $allocated_now_total += $alloc_now;
            $processed_product_ids[] = $pid;

            $line_item_id = 0;
            foreach ( $order->get_items('line_item') as $item_id => $item ) {
                if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) continue;
                $item_pid = (int) $item->get_variation_id();
                if ( $item_pid <= 0 ) $item_pid = (int) $item->get_product_id();
                if ( $item_pid === $pid ) {
                    $line_item_id = (int) $item_id;
                    break;
                }
            }
            if ( $line_item_id > 0 ) {
                $item = $order->get_item( $line_item_id );
                $current_qty = max(0, (int) $item->get_quantity());
                $item->set_quantity( $current_qty + $alloc_now );
                $order->add_item( $item );
            } else {
                $order->add_product( $product, $alloc_now );
            }
        }

        $updated_breakdown[] = [
            'product_id' => $pid,
            'product_name' => (string) ( $row['product_name'] ?? $product->get_name() ),
            'requested_qty' => $requested_qty,
            'allocated_qty' => $new_allocated,
            'pending_qty' => $new_pending,
        ];
    }

    $pending_qty_total = 0;
    $pending_qty_map = [];
    foreach ( $updated_breakdown as $row ) {
        $pid = absint( $row['product_id'] ?? 0 );
        $pqty = max(0, (int) ( $row['pending_qty'] ?? 0 ));
        if ( $pid > 0 && $pqty > 0 ) {
            $pending_qty_total += $pqty;
            $pending_qty_map[ $pid ] = $pqty;
        }
    }

    $order->update_meta_data( '_wc_suf_pending_breakdown', wp_json_encode( $updated_breakdown, JSON_UNESCAPED_UNICODE ) );
    $order->update_meta_data( '_wc_suf_pending_qty_total', $pending_qty_total );
    $order->update_meta_data( '_wc_suf_pending_qty_map', wp_json_encode( $pending_qty_map, JSON_UNESCAPED_UNICODE ) );
    $order->update_meta_data( '_wc_qof_pending_items', wp_json_encode( array_keys( $pending_qty_map ), JSON_UNESCAPED_UNICODE ) );
    $order->update_meta_data( '_wc_qof_pending_req_qty', wp_json_encode( $pending_qty_map, JSON_UNESCAPED_UNICODE ) );
    $pending_price_map = wc_suf_build_pending_price_map_for_order( $order, $updated_breakdown );
    $order->update_meta_data( '_wc_qof_pending_price_map', wp_json_encode( $pending_price_map, JSON_UNESCAPED_UNICODE ) );
    wc_suf_update_pending_order_visible_meta( $order, $updated_breakdown );
    $order->calculate_totals();

    if ( $pending_qty_total <= 0 ) {
        $order->set_status( 'processing', 'تکمیل خودکار اقلام در انتظار پس از تامین موجودی.' );
    } else {
        $order->set_status( 'pendingreview', 'بخشی از اقلام در انتظار هنوز موجودی کافی ندارند.' );
    }
    $order->save();

    wp_send_json_success([
        'message' => ( $pending_qty_total <= 0 )
            ? 'سفارش تکمیل شد و به وضعیت «در حال انجام» رفت.'
            : 'برخی اقلام تخصیص داده شد اما هنوز بخشی در انتظار است.',
        'allocated_now' => $allocated_now_total,
        'pending_qty_total' => $pending_qty_total,
        'product_ids' => array_values( array_unique( array_map( 'absint', $processed_product_ids ) ) ),
    ]);
}

function wc_suf_pending_products_report_handler() {
    check_ajax_referer( 'wc_suf_pending_products_report' );

    if ( ! is_user_logged_in() ) {
        wp_die( 'ابتدا وارد شوید.', 403 );
    }

    if ( ! function_exists( 'wc_get_orders' ) ) {
        wp_die( 'ووکامرس فعال نیست.', 500 );
    }

    $user_id = get_current_user_id();
    $orders = wc_get_orders([
        'type'       => 'shop_order',
        'limit'      => 500,
        'orderby'    => 'date',
        'order'      => 'DESC',
        'meta_key'   => '_wc_suf_seller_id',
        'meta_value' => $user_id,
    ]);

    $products = [];
    foreach ( $orders as $order ) {
        $pending_raw = (string) $order->get_meta( '_wc_suf_pending_breakdown', true );
        $pending_rows = json_decode( $pending_raw, true );
        if ( ! is_array( $pending_rows ) || empty( $pending_rows ) ) {
            continue;
        }

        foreach ( $pending_rows as $row ) {
            $pending_qty = max( 0, (int) ( $row['pending_qty'] ?? 0 ) );
            if ( $pending_qty <= 0 ) {
                continue;
            }

            $pid = absint( $row['product_id'] ?? 0 );
            if ( $pid <= 0 ) {
                continue;
            }

            $name = trim( (string) ( $row['product_name'] ?? '' ) );
            if ( $name === '' ) {
                $product = wc_get_product( $pid );
                $name = $product ? (string) $product->get_name() : ( 'محصول #' . $pid );
            }

            if ( ! isset( $products[ $pid ] ) ) {
                $products[ $pid ] = [
                    'name' => $name,
                    'pending_total' => 0,
                    'orders' => [],
                ];
            }

            $products[ $pid ]['pending_total'] += $pending_qty;
            $products[ $pid ]['orders'][] = '#' . $order->get_order_number() . ' (تعداد: ' . $pending_qty . ')';
        }
    }

    header( 'Content-Type: text/html; charset=utf-8' );
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>گزارش کل محصولات در انتظار</title>';
    echo '<style>body{font-family:tahoma,arial,sans-serif;background:#f8fafc;padding:20px;color:#111827}h1{margin-top:0}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px}.table{width:100%;border-collapse:collapse}.table th,.table td{border:1px solid #e5e7eb;padding:8px}.table th{background:#f3f4f6}.muted{color:#6b7280}</style>';
    echo '</head><body><div class="card">';
    echo '<h1>گزارش کل محصولات در انتظار</h1>';
    echo '<p class="muted">این گزارش بر اساس سفارش‌های ثبت‌شده توسط کاربر فعلی تولید شده است.</p>';

    if ( empty( $products ) ) {
        echo '<div class="muted">در حال حاضر محصول در انتظاری برای سفارش‌های شما وجود ندارد.</div>';
        echo '</div></body></html>';
        exit;
    }

    uasort( $products, function( $a, $b ) {
        return (int) $b['pending_total'] <=> (int) $a['pending_total'];
    } );

    echo '<table class="table"><thead><tr><th>شناسه محصول</th><th>نام محصول</th><th>تعداد کل در انتظار</th><th>شماره سفارش‌های شامل محصول</th></tr></thead><tbody>';
    foreach ( $products as $pid => $row ) {
        $order_refs = array_values( array_unique( $row['orders'] ) );
        echo '<tr>';
        echo '<td>' . esc_html( (string) $pid ) . '</td>';
        echo '<td>' . esc_html( (string) $row['name'] ) . '</td>';
        echo '<td style="font-weight:700;color:#b45309">' . esc_html( (string) $row['pending_total'] ) . '</td>';
        echo '<td>' . esc_html( implode( '، ', $order_refs ) ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div></body></html>';
    exit;
}

add_action('wp_ajax_wc_suf_refresh_stocks', 'wc_suf_refresh_stocks_handler');
function wc_suf_refresh_stocks_handler(){
    check_ajax_referer('wc_suf_refresh_stocks');

    if( ! wc_suf_current_user_is_pos_manager() ){
        wp_send_json_error(['message'=>'دسترسی غیرمجاز.']);
    }

    $raw_ids = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '[]';
    $ids = json_decode($raw_ids, true);
    if ( ! is_array($ids) || empty($ids) ) {
        wp_send_json_success(['stocks' => []]);
    }

    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
    if ( empty($ids) ) {
        wp_send_json_success(['stocks' => []]);
    }

    $stocks = [];
    foreach ( $ids as $pid ) {
        if ( $pid <= 0 ) continue;
        $product = wc_get_product( $pid );
        if ( ! $product ) continue;

        $prod_stock = wc_suf_get_production_stock_qty( $pid );
        $wc_stock   = (int) max(0, (int) ($product->get_stock_quantity() ?? 0));
        $teh_stock  = 0;
        $teh_ok     = 0;

        if ( function_exists('yith_pos_stock_management') ) {
            $teh_read = wc_suf_yith_get_store_stock( $product, (int) WC_SUF_TEHRANPARS_STORE_ID );
            if ( false !== $teh_read && null !== $teh_read ) {
                $teh_stock = (int) $teh_read;
                $teh_ok    = 1;
            }
        }

        $stocks[(string) $pid] = [
            'prod_stock'   => (int) $prod_stock,
            'wc_stock'     => (int) $wc_stock,
            'teh_stock'    => (int) $teh_stock,
            'teh_stock_ok' => (int) $teh_ok,
        ];
    }

    wp_send_json_success(['stocks' => $stocks]);
}
