<?php
/**
 * Plugin Name: Telegram Sales Bot Pro Clean
 * Description: ارسال پیام کامل فروش ووکامرس، YITH POS و Pending Items به تلگرام با داشبورد و پیام تمیز.
 * Version: 1.1
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

// ================== تنظیمات داشبورد ==================
add_action('admin_menu', function() {
    add_options_page('Telegram Bot Settings', 'Telegram Bot', 'manage_options', 'tsb_pro_settings', 'tsb_pro_render_settings');
});

add_action('admin_init', function() {
    register_setting('tsb_pro_group', 'tsb_pro_bot_token');
    register_setting('tsb_pro_group', 'tsb_pro_channel_sales');
    register_setting('tsb_pro_group', 'tsb_pro_channel_sale_role');
    register_setting('tsb_pro_group', 'tsb_pro_channel_operations');
});

function tsb_pro_render_settings() {
    ?>
    <div class="wrap">
        <h1>تنظیمات ربات تلگرام</h1>
        <form method="post" action="options.php">
            <?php settings_fields('tsb_pro_group'); ?>
            <?php do_settings_sections('tsb_pro_group'); ?>
            <table class="form-table">
                <tr>
                    <th>توکن ربات</th>
                    <td><input type="text" name="tsb_pro_bot_token" value="<?php echo esc_attr(get_option('tsb_pro_bot_token')); ?>" size="50"></td>
                </tr>
                <tr>
                    <th>آیدی کانال فروش‌ها</th>
                    <td><input type="text" name="tsb_pro_channel_sales" value="<?php echo esc_attr(get_option('tsb_pro_channel_sales')); ?>" size="50"></td>
                </tr>
                <tr>
                    <th>آیدی کانال فروشندگان نقش sale</th>
                    <td><input type="text" name="tsb_pro_channel_sale_role" value="<?php echo esc_attr(get_option('tsb_pro_channel_sale_role')); ?>" size="50"></td>
                </tr>
                <tr>
                    <th>آیدی کانال عملیات / انبار</th>
                    <td><input type="text" name="tsb_pro_channel_operations" value="<?php echo esc_attr(get_option('tsb_pro_channel_operations')); ?>" size="50"></td>
                </tr>
            </table>
            <?php submit_button('ذخیره تغییرات'); ?>
        </form>
    </div>
    <?php
}

// ================== توابع کمکی ==================
if(!function_exists('tsb_pro_safe_val')){
    function tsb_pro_safe_val($val,$fallback=''){
        return (isset($val) && $val!=='') ? $val : $fallback;
    }
}

// تبدیل مبلغ به متن بدون HTML و &nbsp;
if(!function_exists('tsb_pro_money_text')){
    function tsb_pro_money_text($amount, $currency){
        $html = wc_price((float)$amount,['currency'=>$currency]);
        $s = strip_tags($html);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace(array("\xC2\xA0",'&nbsp;'), ' ', $s);
        $s = preg_replace('/\s+/u',' ',$s);
        return trim($s);
    }
}

// ================== ارسال تلگرام ==================
if(!function_exists('tsb_pro_send_telegram')){
    function tsb_pro_send_telegram($chat_id,$text,$order_id=0){
        $token = get_option('tsb_pro_bot_token');
        if(!$token || !$chat_id){
            error_log("Telegram Bot Pro ERROR: توکن یا آیدی کانال خالی است.");
            return false;
        }

        $chat_id = trim($chat_id);
        $chat_id = rtrim($chat_id,'-');

        $url = "https://api.telegram.org/bot$token/sendMessage";
        $payload = ['chat_id'=>$chat_id,'text'=>$text];

        $args = [
            'method'=>'POST',
            'headers'=>['Content-Type'=>'application/json; charset=utf-8'],
            'body'=>wp_json_encode($payload),
            'timeout'=>15
        ];

        $response = wp_remote_post($url,$args);

        if(is_wp_error($response)){
            error_log('Telegram Bot Pro ERROR: '.$response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log("Telegram Bot Pro RESPONSE (order $order_id): $code - $body");

        return ($code >=200 && $code <300);
    }
}

// ================== Pending helpers ==================
if(!function_exists('tsb_pro_get_pending')){
    function tsb_pro_get_pending(WC_Order $order){
        $raw_items = $order->get_meta('_wc_qof_pending_items',true);
        $raw_qty = $order->get_meta('_wc_qof_pending_req_qty',true);
        $raw_price = $order->get_meta('_wc_qof_pending_price_map',true);

        if(is_string($raw_items) && $raw_items!=='' && isset($raw_items[0]) && $raw_items[0]==='['){
            $decoded = json_decode($raw_items,true);
            if(is_array($decoded)) $raw_items = $decoded;
        }
        if(is_numeric($raw_items)) $raw_items = [$raw_items];
        if(!is_array($raw_items)) return ['list_text'=>'','sum'=>0.0];

        $lines=''; $sum=0.0;
        foreach($raw_items as $pid){
            $pid = absint($pid); if(!$pid) continue;
            $p = wc_get_product($pid);
            $name = $p?$p->get_name():'#'.$pid;
            $qty = isset($raw_qty[$pid])?max(0,(int)$raw_qty[$pid]):0;
            $unit_price = isset($raw_price[$pid]['unit'])?(float)$raw_price[$pid]['unit']:($p?$p->get_price():0.0);
            $line_total = isset($raw_price[$pid]['line'])?(float)$raw_price[$pid]['line']:($unit_price*$qty);
            $sum += $line_total;
            $lines .= "- $name | کد: $pid (تعداد درخواستی: $qty)\n";
        }
        return ['list_text'=>$lines,'sum'=>$sum];
    }
}

// ================== ساخت پیام ==================
if(!function_exists('tsb_pro_format_order')){
    function tsb_pro_format_order(WC_Order $order){
        // منبع فروش
        $is_yith = false;
        $meta_keys = ['_yith_pos_order','_yith_pos_gateway','_yith_pos_store','_yith_pos_register','_yith_pos_cashier'];
        foreach($meta_keys as $key){ if($order->get_meta($key)) $is_yith=true; }
        $source_text = $is_yith ? 'فروش از POS' : 'فروش از وبسایت';

        $order_id = $order->get_id();
        $created = $order->get_date_created();
        $created_txt = ($created instanceof WC_DateTime)?wc_format_datetime($created,'Y-m-d H:i:s'):'-';
        $customer = trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
        if(!$customer) $customer='نامشخص';
        $phone = tsb_pro_safe_val($order->get_billing_phone(), tsb_pro_safe_val($order->get_shipping_phone(),'مشخص نشده'));

        // فقط آدرس واقعی بدون نام مشتری و <br>
        $address = $order->get_billing_address_1() ?: $order->get_shipping_address_1() ?: 'مشخص نشده';
        if($order->get_billing_city()) $address .= " - ".$order->get_billing_city();
        if($order->get_billing_state()) $address .= " - ".$order->get_billing_state();

        // محصولات
        $items_txt='';
        foreach($order->get_items() as $item){
            $name = $item->get_name();
            $qty = (int)$item->get_quantity();
            $pid = $item->get_variation_id() ?: $item->get_product_id();
            $items_txt .= "- $name | کد: $pid (تعداد: $qty)\n";
        }

        // pending items
        $pending = tsb_pro_get_pending($order);
        $pending_txt = $pending['list_text'];
        $pending_sum = $pending['sum'];

        $total_txt = tsb_pro_money_text($order->get_total(),$order->get_currency());
        $grand_total_txt = tsb_pro_money_text($order->get_total()+$pending_sum,$order->get_currency());

        $msg = "💰 [$source_text]\n🧾 شماره سفارش: $order_id\n⏰ تاریخ: $created_txt\n👤 مشتری: $customer\n📞 موبایل: $phone\n📍 آدرس: $address\n\n🛒 اقلام سفارش:\n$items_txt";

        if($pending_txt){
            $msg .= "\n⏳ اقلام در انتظار:\n$pending_txt";
            $msg .= "\n✅ جمع کل سفارش + pending: $grand_total_txt";
        } else {
            $msg .= "\n✅ مبلغ کل: $total_txt";
        }

        return $msg;
    }
}

// ================== هوک ووکامرس ==================
add_action('woocommerce_order_status_changed','tsb_pro_order_status_changed',10,4);

function tsb_pro_order_status_changed($order_id,$old_status,$new_status,$order){
    if(!($order instanceof WC_Order)) $order = wc_get_order($order_id);
    if(!$order) return;

    $msg = tsb_pro_format_order($order);

    // کانال اصلی فروش
    tsb_pro_send_telegram(get_option('tsb_pro_channel_sales'),$msg,$order_id);

    // کاربران نقش sale
    $user_id = $order->get_user_id();
    if($user_id){
        $user = get_userdata($user_id);
        if($user && in_array('sale',$user->roles)){
            tsb_pro_send_telegram(get_option('tsb_pro_channel_sale_role'),$msg,$order_id);
        }
    }

    // کانال عملیات / انبار
    tsb_pro_send_telegram(get_option('tsb_pro_channel_operations'),$msg,$order_id);
}