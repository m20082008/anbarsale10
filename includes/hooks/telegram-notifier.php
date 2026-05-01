<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wc_suf_get_telegram_bot_token() {
    return trim( (string) get_option( 'wc_suf_telegram_bot_token', '' ) );
}

function wc_suf_get_telegram_chat_id() {
    return trim( (string) get_option( 'wc_suf_telegram_chat_id', '' ) );
}

function wc_suf_send_telegram_message( $text, $order_id = 0 ) {
    $token = wc_suf_get_telegram_bot_token();
    $chat_id = wc_suf_get_telegram_chat_id();

    if ( $token === '' || $chat_id === '' ) {
        return false;
    }

    $url = 'https://api.telegram.org/bot' . rawurlencode( $token ) . '/sendMessage';
    $response = wp_remote_post(
        $url,
        [
            'timeout' => 20,
            'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
            'body'    => wp_json_encode(
                [
                    'chat_id' => $chat_id,
                    'text'    => $text,
                ]
            ),
        ]
    );

    if ( is_wp_error( $response ) ) {
        error_log( 'WC SUF Telegram error: ' . $response->get_error_message() );
        return false;
    }

    $status_code = (int) wp_remote_retrieve_response_code( $response );
    $body = (string) wp_remote_retrieve_body( $response );
    error_log( "WC SUF Telegram response for order {$order_id}: {$status_code} | {$body}" );

    return ( $status_code >= 200 && $status_code < 300 );
}

function wc_suf_telegram_money( $amount, $currency ) {
    $html = wc_price( (float) $amount, [ 'currency' => $currency ] );
    $text = strip_tags( (string) $html );
    return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

function wc_suf_telegram_detect_order_source( WC_Order $order ) {
    $is_yith_pos = false;
    foreach ( [ '_yith_pos_order', '_yith_pos_gateway', '_yith_pos_store', '_yith_pos_register' ] as $meta_key ) {
        if ( $order->get_meta( $meta_key, true ) ) {
            $is_yith_pos = true;
            break;
        }
    }

    if ( $is_yith_pos ) {
        $branch = (string) $order->get_meta( '_yith_pos_store_name', true );
        if ( $branch === '' ) {
            $branch = (string) $order->get_meta( '_yith_pos_store', true );
        }
        return $branch !== '' ? 'YITH POS - شعبه: ' . $branch : 'YITH POS';
    }

    $is_new_app = (bool) $order->get_meta( '_from_new_app', true ) || (bool) $order->get_meta( '_order_from_app', true );
    return $is_new_app ? 'نرم افزار جدید' : 'وبسایت';
}

function wc_suf_telegram_get_pending_items( WC_Order $order ) {
    $raw_items = $order->get_meta( '_wc_qof_pending_items', true );
    $raw_qty_map = $order->get_meta( '_wc_qof_pending_req_qty', true );
    $raw_price_map = $order->get_meta( '_wc_qof_pending_price_map', true );

    if ( is_string( $raw_items ) && strpos( $raw_items, '[' ) === 0 ) {
        $decoded = json_decode( $raw_items, true );
        if ( is_array( $decoded ) ) {
            $raw_items = $decoded;
        }
    }
    if ( is_numeric( $raw_items ) ) {
        $raw_items = [ (int) $raw_items ];
    }
    if ( ! is_array( $raw_items ) ) {
        return [ 'text' => 'ندارد', 'total' => 0.0 ];
    }

    $lines = [];
    $total = 0.0;
    foreach ( $raw_items as $product_id ) {
        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            continue;
        }
        $qty = isset( $raw_qty_map[ $product_id ] ) ? (int) $raw_qty_map[ $product_id ] : 0;
        $product = wc_get_product( $product_id );
        $name = $product ? $product->get_name() : ( '#' . $product_id );

        $line_total = isset( $raw_price_map[ $product_id ]['line'] ) ? (float) $raw_price_map[ $product_id ]['line'] : 0.0;
        if ( $line_total <= 0 && isset( $raw_price_map[ $product_id ]['unit'] ) ) {
            $line_total = (float) $raw_price_map[ $product_id ]['unit'] * $qty;
        }

        $total += $line_total;
        $lines[] = sprintf( '- %s | تعداد: %d', $name, $qty );
    }

    if ( empty( $lines ) ) {
        return [ 'text' => 'ندارد', 'total' => 0.0 ];
    }

    return [ 'text' => implode( "\n", $lines ), 'total' => $total ];
}

function wc_suf_telegram_build_order_message( WC_Order $order ) {
    $source = wc_suf_telegram_detect_order_source( $order );
    $sales_method = (string) $order->get_meta( '_sales_method', true );
    if ( $sales_method === '' ) {
        $sales_method = (string) $order->get_payment_method_title();
    }
    if ( $sales_method === '' ) {
        $sales_method = 'نامشخص';
    }

    $created_at = $order->get_date_created();
    $created_text = $created_at instanceof WC_DateTime ? $created_at->date_i18n( 'Y-m-d H:i:s' ) : '-';

    $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    if ( $customer_name === '' ) {
        $customer_name = 'نامشخص';
    }

    $mobile = trim( (string) $order->get_billing_phone() );
    if ( $mobile === '' ) {
        $mobile = 'نامشخص';
    }

    $address = trim( (string) ( $order->get_formatted_billing_address() ?: $order->get_formatted_shipping_address() ) );
    $address = wp_strip_all_tags( str_replace( [ '<br/>', '<br>' ], ' - ', $address ) );
    if ( $address === '' ) {
        $address = 'نامشخص';
    }

    $allocated_lines = [];
    $allocated_total = 0.0;
    foreach ( $order->get_items() as $item ) {
        $qty = (int) $item->get_quantity();
        $allocated_total += (float) $item->get_total();
        $allocated_lines[] = sprintf( '- %s | تعداد: %d', $item->get_name(), $qty );
    }

    $pending = wc_suf_telegram_get_pending_items( $order );
    $currency = $order->get_currency();
    $pending_total = (float) $pending['total'];
    $grand_total = $allocated_total + $pending_total;

    $msg = [];
    $msg[] = '📦 گزارش سفارش';
    $msg[] = '1) منبع سفارش: ' . $source;
    $msg[] = '2) نحوه فروش: ' . $sales_method;
    $msg[] = '3) شماره سفارش: ' . $order->get_order_number();
    $msg[] = '4) تاریخ ایجاد سفارش: ' . $created_text;
    $msg[] = '5) نام مشتری: ' . $customer_name;
    $msg[] = '6) شماره موبایل: ' . $mobile;
    $msg[] = '7) آدرس: ' . $address;
    $msg[] = "8) اقلام سفارش:\nتخصیص‌شده:\n" . ( $allocated_lines ? implode( "\n", $allocated_lines ) : 'ندارد' ) . "\n\nدر انتظار:\n" . $pending['text'];
    $msg[] = '9) مبلغ کل اقلام سفارش داده شده (تخصیص از انبار): ' . wc_suf_telegram_money( $allocated_total, $currency );
    $msg[] = '10) مبلغ کل سفارش های در انتظار: ' . wc_suf_telegram_money( $pending_total, $currency );
    $msg[] = '11) جمع مبلغ کل: ' . wc_suf_telegram_money( $grand_total, $currency );

    return implode( "\n", $msg );
}

add_action( 'woocommerce_order_status_changed', function ( $order_id, $old_status, $new_status, $order ) {
    if ( ! ( $order instanceof WC_Order ) ) {
        $order = wc_get_order( $order_id );
    }
    if ( ! $order ) {
        return;
    }

    $message = wc_suf_telegram_build_order_message( $order );
    wc_suf_send_telegram_message( $message, (int) $order_id );
}, 10, 4 );
