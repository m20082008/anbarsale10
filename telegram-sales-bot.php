<?php
/**
 * Plugin Name: Telegram Sales Notifier
 * Description: ارسال جزئیات کامل سفارش ووکامرس به تلگرام.
 * Version: 1.0.0
 * Author: Team
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_options_page('Telegram Sales Notifier', 'Telegram Sales Notifier', 'manage_options', 'tsn_settings', 'tsn_render_settings');
});

add_action('admin_init', function () {
    register_setting('tsn_settings_group', 'tsn_bot_token');
    register_setting('tsn_settings_group', 'tsn_chat_id');
});

function tsn_render_settings()
{
    ?>
    <div class="wrap">
        <h1>تنظیمات اعلان تلگرام فروش</h1>
        <form method="post" action="options.php">
            <?php settings_fields('tsn_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">توکن ربات</th>
                    <td><input type="text" name="tsn_bot_token" value="<?php echo esc_attr(get_option('tsn_bot_token', '')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row">Chat ID</th>
                    <td><input type="text" name="tsn_chat_id" value="<?php echo esc_attr(get_option('tsn_chat_id', '')); ?>" class="regular-text"></td>
                </tr>
            </table>
            <?php submit_button('ذخیره تنظیمات'); ?>
        </form>
    </div>
    <?php
}

function tsn_send_telegram_message($text, $order_id = 0)
{
    $token = trim((string) get_option('tsn_bot_token', ''));
    $chatId = trim((string) get_option('tsn_chat_id', ''));

    if ($token === '' || $chatId === '') {
        error_log('Telegram Sales Notifier: bot token or chat id is empty.');
        return false;
    }

    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
    $response = wp_remote_post($url, [
        'timeout' => 20,
        'headers' => [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'body' => wp_json_encode([
            'chat_id' => $chatId,
            'text' => $text,
        ]),
    ]);

    if (is_wp_error($response)) {
        error_log('Telegram Sales Notifier error: ' . $response->get_error_message());
        return false;
    }

    $statusCode = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    error_log("Telegram Sales Notifier response for order {$order_id}: {$statusCode} | {$body}");

    return ($statusCode >= 200 && $statusCode < 300);
}

function tsn_money($amount, $currency)
{
    $text = strip_tags((string) wc_price((float) $amount, ['currency' => $currency]));
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function tsn_detect_source(WC_Order $order)
{
    $isYithPos = false;
    foreach (['_yith_pos_order', '_yith_pos_gateway', '_yith_pos_store', '_yith_pos_register'] as $metaKey) {
        if ($order->get_meta($metaKey, true)) {
            $isYithPos = true;
            break;
        }
    }

    if ($isYithPos) {
        $branch = (string) $order->get_meta('_yith_pos_store_name', true);
        if ($branch === '') {
            $branch = (string) $order->get_meta('_yith_pos_store', true);
        }
        return $branch !== '' ? 'YITH POS - شعبه: ' . $branch : 'YITH POS';
    }

    $fromNewApp = (bool) $order->get_meta('_from_new_app', true) || (bool) $order->get_meta('_order_from_app', true);
    if ($fromNewApp) {
        return 'نرم افزار جدید';
    }

    return 'وبسایت';
}

function tsn_get_pending_items(WC_Order $order)
{
    $rawItems = $order->get_meta('_wc_qof_pending_items', true);
    $rawQtyMap = $order->get_meta('_wc_qof_pending_req_qty', true);
    $rawPriceMap = $order->get_meta('_wc_qof_pending_price_map', true);

    if (is_string($rawItems) && strpos($rawItems, '[') === 0) {
        $decoded = json_decode($rawItems, true);
        if (is_array($decoded)) {
            $rawItems = $decoded;
        }
    }

    if (is_numeric($rawItems)) {
        $rawItems = [(int) $rawItems];
    }

    if (!is_array($rawItems)) {
        return ['text' => 'ندارد', 'total' => 0.0];
    }

    $lines = [];
    $totalPending = 0.0;

    foreach ($rawItems as $productId) {
        $productId = absint($productId);
        if (!$productId) {
            continue;
        }

        $qty = isset($rawQtyMap[$productId]) ? (int) $rawQtyMap[$productId] : 0;
        $product = wc_get_product($productId);
        $name = $product ? $product->get_name() : ('#' . $productId);

        $lineTotal = isset($rawPriceMap[$productId]['line']) ? (float) $rawPriceMap[$productId]['line'] : 0.0;
        if ($lineTotal <= 0 && isset($rawPriceMap[$productId]['unit'])) {
            $lineTotal = ((float) $rawPriceMap[$productId]['unit']) * $qty;
        }

        $totalPending += $lineTotal;
        $lines[] = sprintf('- %s | تعداد: %d', $name, $qty);
    }

    if (!$lines) {
        return ['text' => 'ندارد', 'total' => 0.0];
    }

    return ['text' => implode("\n", $lines), 'total' => $totalPending];
}

function tsn_build_message(WC_Order $order)
{
    $source = tsn_detect_source($order);
    $salesMethod = (string) $order->get_meta('_sales_method', true);
    if ($salesMethod === '') {
        $salesMethod = (string) $order->get_payment_method_title();
    }
    if ($salesMethod === '') {
        $salesMethod = 'نامشخص';
    }

    $orderNumber = $order->get_order_number();
    $createdAt = $order->get_date_created();
    $createdText = $createdAt instanceof WC_DateTime ? $createdAt->date_i18n('Y-m-d H:i:s') : '-';

    $customerName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    if ($customerName === '') {
        $customerName = 'نامشخص';
    }

    $mobile = $order->get_billing_phone();
    if ($mobile === '') {
        $mobile = 'نامشخص';
    }

    $address = trim($order->get_formatted_billing_address() ?: $order->get_formatted_shipping_address());
    $address = wp_strip_all_tags(str_replace('<br/>', ' - ', str_replace('<br>', ' - ', $address)));
    if ($address === '') {
        $address = 'نامشخص';
    }

    $allocatedLines = [];
    $allocatedTotal = 0.0;

    foreach ($order->get_items() as $item) {
        $qty = (int) $item->get_quantity();
        $lineTotal = (float) $item->get_total();
        $allocatedTotal += $lineTotal;
        $allocatedLines[] = sprintf('- %s | تعداد: %d', $item->get_name(), $qty);
    }

    $allocatedText = $allocatedLines ? implode("\n", $allocatedLines) : 'ندارد';

    $pending = tsn_get_pending_items($order);
    $pendingText = $pending['text'];
    $pendingTotal = (float) $pending['total'];

    $currency = $order->get_currency();
    $grandTotal = $allocatedTotal + $pendingTotal;

    $message = [];
    $message[] = '📦 گزارش سفارش';
    $message[] = '1) منبع سفارش: ' . $source;
    $message[] = '2) نحوه فروش: ' . $salesMethod;
    $message[] = '3) شماره سفارش: ' . $orderNumber;
    $message[] = '4) تاریخ ایجاد سفارش: ' . $createdText;
    $message[] = '5) نام مشتری: ' . $customerName;
    $message[] = '6) شماره موبایل: ' . $mobile;
    $message[] = '7) آدرس: ' . $address;
    $message[] = "8) اقلام سفارش:\nتخصیص‌شده:\n{$allocatedText}\n\nدر انتظار:\n{$pendingText}";
    $message[] = '9) مبلغ کل اقلام تخصیص‌شده: ' . tsn_money($allocatedTotal, $currency);
    $message[] = '10) مبلغ کل سفارش‌های در انتظار: ' . tsn_money($pendingTotal, $currency);
    $message[] = '11) جمع مبلغ کل: ' . tsn_money($grandTotal, $currency);

    return implode("\n", $message);
}

add_action('woocommerce_order_status_changed', function ($orderId, $oldStatus, $newStatus, $order) {
    if (!($order instanceof WC_Order)) {
        $order = wc_get_order($orderId);
    }

    if (!$order) {
        return;
    }

    $message = tsn_build_message($order);
    tsn_send_telegram_message($message, (int) $orderId);
}, 10, 4);
