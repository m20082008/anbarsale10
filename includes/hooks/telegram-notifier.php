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


function wc_suf_telegram_product_label( $product_id, $fallback_name = '' ) {
    $product_id = absint( $product_id );
    $product = $product_id ? wc_get_product( $product_id ) : null;

    $name = $fallback_name !== '' ? $fallback_name : ( $product ? $product->get_name() : ( $product_id ? ( '#' . $product_id ) : 'آیتم در انتظار' ) );
    $sku = $product ? trim( (string) $product->get_sku() ) : '';

    return $sku !== '' ? sprintf( '%s (%s)', $name, $sku ) : $name;
}

function wc_suf_telegram_build_order_unit_price_map( WC_Order $order ) {
    $unit_price_map = [];

    foreach ( $order->get_items() as $item ) {
        $product_id = absint( $item->get_product_id() );
        $qty = (int) $item->get_quantity();
        if ( ! $product_id || $qty <= 0 ) {
            continue;
        }

        $line_total = (float) $item->get_total();
        $line_tax = (float) $item->get_total_tax();
        $unit_price_map[ $product_id ] = ( $line_total + $line_tax ) / $qty;
    }

    return $unit_price_map;
}

function wc_suf_telegram_get_pending_items( WC_Order $order ) {
    $order_unit_prices = wc_suf_telegram_build_order_unit_price_map( $order );
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
    if ( is_string( $raw_qty_map ) && strpos( $raw_qty_map, '{' ) === 0 ) {
        $decoded_qty = json_decode( $raw_qty_map, true );
        if ( is_array( $decoded_qty ) ) {
            $raw_qty_map = $decoded_qty;
        }
    }
    if ( is_string( $raw_price_map ) && strpos( $raw_price_map, '{' ) === 0 ) {
        $decoded_price = json_decode( $raw_price_map, true );
        if ( is_array( $decoded_price ) ) {
            $raw_price_map = $decoded_price;
        }
    }
    if ( ! is_array( $raw_qty_map ) ) {
        $raw_qty_map = [];
    }
    if ( ! is_array( $raw_price_map ) ) {
        $raw_price_map = [];
    }
    if ( ! is_array( $raw_items ) ) {
        $custom_pending = $order->get_meta( 'اقلام در انتظار', true );
        if ( is_string( $custom_pending ) && $custom_pending !== '' ) {
            $decoded_custom = json_decode( $custom_pending, true );
            if ( is_array( $decoded_custom ) ) {
                $custom_pending = $decoded_custom;
            }
        }

        if ( is_array( $custom_pending ) ) {
            $lines = [];
            foreach ( $custom_pending as $key => $value ) {
                if ( is_array( $value ) ) {
                    $name = isset( $value['name'] ) ? trim( (string) $value['name'] ) : '';
                    $qty = isset( $value['qty'] ) ? (int) $value['qty'] : ( isset( $value['quantity'] ) ? (int) $value['quantity'] : 0 );
                    if ( $name === '' ) {
                        $name = is_string( $key ) ? $key : 'آیتم در انتظار';
                    }
                    $lines[] = sprintf( '- %s | تعداد: %d', $name, max( 0, $qty ) );
                    continue;
                }

                if ( is_string( $key ) ) {
                    $lines[] = sprintf( '- %s | تعداد: %d', $key, max( 0, (int) $value ) );
                } elseif ( is_string( $value ) && trim( $value ) !== '' ) {
                    $lines[] = '- ' . trim( $value );
                }
            }

            if ( ! empty( $lines ) ) {
                return [ 'text' => implode( "\n", $lines ), 'total' => 0.0 ];
            }
        } elseif ( is_string( $custom_pending ) && trim( $custom_pending ) !== '' ) {
            return [ 'text' => trim( $custom_pending ), 'total' => 0.0 ];
        }

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
        $name = wc_suf_telegram_product_label( $product_id );

        $line_total = isset( $raw_price_map[ $product_id ]['line'] ) ? (float) $raw_price_map[ $product_id ]['line'] : 0.0;
        if ( $line_total <= 0 && isset( $raw_price_map[ $product_id ]['unit'] ) ) {
            $line_total = (float) $raw_price_map[ $product_id ]['unit'] * $qty;
        }
        if ( $line_total <= 0 && isset( $order_unit_prices[ $product_id ] ) ) {
            $line_total = (float) $order_unit_prices[ $product_id ] * $qty;
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
    $sales_method = (string) $order->get_meta( 'sale_method', true );
    if ( $sales_method === '' ) {
        $sales_method = (string) $order->get_meta( '_sales_method', true );
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

    $address_parts = [
        $order->get_billing_address_1(),
        $order->get_billing_address_2(),
        $order->get_billing_city(),
        $order->get_billing_state(),
    ];
    $address_parts = array_filter( array_map( 'trim', $address_parts ) );
    $address = implode( ' - ', $address_parts );
    if ( $address === '' ) {
        $shipping_parts = [
            $order->get_shipping_address_1(),
            $order->get_shipping_address_2(),
            $order->get_shipping_city(),
            $order->get_shipping_state(),
        ];
        $shipping_parts = array_filter( array_map( 'trim', $shipping_parts ) );
        $address = implode( ' - ', $shipping_parts );
    }
    if ( $address === '' ) {
        $address = 'نامشخص';
    }

    $allocated_lines = [];
    $allocated_total = 0.0;
    foreach ( $order->get_items() as $item ) {
        $qty = (int) $item->get_quantity();
        $allocated_total += (float) $item->get_total();
        $product_id = absint( $item->get_product_id() );
        $allocated_lines[] = sprintf( '- %s | تعداد: %d', wc_suf_telegram_product_label( $product_id, $item->get_name() ), $qty );
    }

    $pending = wc_suf_telegram_get_pending_items( $order );
    $currency = $order->get_currency();
    $pending_total = (float) $pending['total'];
    $grand_total = $allocated_total + $pending_total;

    $msg = [];
    $msg[] = '✅ سفارش جدید!';
    $msg[] = '🧾 نحوه فروش: ' . $sales_method;
    $msg[] = '🔢 شماره سفارش: ' . $order->get_order_number();
    $msg[] = '🕒 تاریخ ایجاد سفارش: ' . $created_text;
    $msg[] = '👤 نام مشتری: ' . $customer_name;
    $msg[] = '📞 شماره موبایل: ' . $mobile;
    $msg[] = '📍 آدرس: ' . $address;
    $msg[] = "📦 اقلام سفارش:\n✅ تخصیص‌شده:\n" . ( $allocated_lines ? implode( "\n", $allocated_lines ) : 'ندارد' ) . "\n\n⏳ در انتظار:\n" . $pending['text'];
    $msg[] = '💵 مبلغ کل اقلام سفارش داده شده (تخصیص از انبار): ' . wc_suf_telegram_money( $allocated_total, $currency );
    $msg[] = '⌛ مبلغ کل سفارش های در انتظار: ' . wc_suf_telegram_money( $pending_total, $currency );
    $msg[] = '🧮 جمع مبلغ کل: ' . wc_suf_telegram_money( $grand_total, $currency );

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
