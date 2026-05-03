<?php
/*--------------------------------------
| فرانت: Select2/SelectWoo + استایل‌ها
---------------------------------------*/
function wc_suf_enqueue_front_assets() {
    wp_enqueue_script('jquery');
    $use_core_selectwoo = ( wp_script_is('selectWoo', 'registered') || wp_script_is('selectWoo', 'enqueued') );
    if ( $use_core_selectwoo ) {
        wp_enqueue_script('selectWoo');
        if ( wp_style_is('select2', 'registered') ) wp_enqueue_style('select2');
    } else {
        wp_enqueue_style('wc-suf-select2', plugins_url('assets/select2.min.css', WC_SUF_PLUGIN_FILE), [], '4.1.0');
        wp_enqueue_script('wc-suf-select2', plugins_url('assets/select2.min.js', WC_SUF_PLUGIN_FILE), ['jquery'], '4.1.0', true);
    }
    $css = '
    #sel-id, #sel-name { font-size: 18px; line-height: 1.6; padding: 6px 8px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        font-size: 18px !important; line-height: 1.8 !important; padding-top: 2px !important; padding-bottom: 2px !important; font-weight: 600;
    }
    .select2-results__option { font-size: 16px; }
    .select2-search--dropdown .select2-search__field { font-size: 16px; padding: 8px; }
    .select2-container .select2-results > .select2-results__options { max-height: 85vh !important; }
    .select2-container .select2-dropdown { max-height: 90vh !important; overflow: auto !important; }
    .select2-container { z-index: 999999 !important; }
    .suf-muted { color:#6b7280; font-size:12px }
    .wc-suf-modal-overlay{ position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000000; display:none; }
    .wc-suf-modal{ position:fixed; inset:0; z-index:1000001; display:none; align-items:center; justify-content:center; padding:18px; }
    .wc-suf-modal .wc-suf-modal-card{ width:min(980px, 96vw); max-height:88vh; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,.25); display:flex; flex-direction:column; }
    .wc-suf-modal .wc-suf-modal-head{ padding:12px 14px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:10px; background:#f9fafb; }
    .wc-suf-modal .wc-suf-modal-title{ font-weight:800; }
    .wc-suf-modal .wc-suf-modal-close{ border:1px solid #ef4444; background:#ef4444; color:#fff; border-radius:10px; padding:6px 10px; cursor:pointer; font-weight:900; line-height:1; }
    .wc-suf-modal .wc-suf-modal-body{ padding:12px 14px; overflow:auto; }
    .wc-suf-modal .wc-suf-modal-foot{ padding:12px 14px; border-top:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:12px; background:#f9fafb; }
    .wc-suf-picker-row{ display:grid; grid-template-columns: 1fr 140px; gap:10px; align-items:center; padding:10px 8px; border-bottom:1px solid #f1f5f9; }
    .wc-suf-picker-row:last-child{ border-bottom:none; }
    .wc-suf-picker-name{ font-weight:600; }
    .wc-suf-picker-meta{ font-size:12px; color:#6b7280; margin-top:2px; }
    .wc-suf-picker-qty{ display:flex; align-items:center; justify-content:flex-end; gap:6px; }
    .wc-suf-picker-qty input{ width:76px; text-align:center; padding:6px; font-size:16px; border:1px solid #e5e7eb; border-radius:10px; }
    .wc-suf-picker-qty button{ padding:6px 10px; font-size:16px; cursor:pointer; border:1px solid #e5e7eb; background:#fff; border-radius:10px; }
    .wc-suf-filter-grid{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .wc-suf-filter-grid .wc-suf-filter{ display:flex; gap:6px; align-items:center; }
    .wc-suf-filter-grid label{ font-weight:700; font-size:13px; color:#111827; }
    .wc-suf-filter-grid select{ padding:9px 8px; border:1px solid #e5e7eb; border-radius:12px; font-size:13px; background:#fff; min-width:140px; max-width:180px; }
    #return-destination, #return-reason{ background:#fff !important; color:#111 !important; }
    #return-destination option, #return-reason option{ background:#fff !important; color:#111 !important; }
    ';
    wp_register_style('wc-suf-ui', false, [], '2.4.0');
    wp_enqueue_style('wc-suf-ui');
    wp_add_inline_style('wc-suf-ui', $css);
}

/*--------------------------------------
| Shortcode: [stock_update_form key="910"]
---------------------------------------*/
add_shortcode('stock_update_form', function($atts){
    wc_suf_enqueue_front_assets();
    if ( ! function_exists('wc_get_products') ) {
        return '<div dir="rtl" style="color:#b91c1c">WooCommerce فعال نیست.</div>';
    }
    if ( ! is_user_logged_in() ) {
        ob_start();
        echo '<div dir="rtl" style="max-width:420px; margin:20px auto; padding:16px; border:1px solid #e5e7eb; border-radius:12px; background:#fff">';
        echo '<h3 style="margin-top:0">ورود کاربر</h3>';
        wp_login_form([
            'echo'           => true,
            'remember'       => true,
            'redirect'       => esc_url( add_query_arg( [] ) ),
            'label_username' => 'نام کاربری',
            'label_password' => 'رمز عبور',
            'label_log_in'   => 'ورود',
        ]);
        echo '</div>';
        return ob_get_clean();
    }
    if( ! wc_suf_current_user_is_pos_manager() ){
        return '<div dir="rtl" style="color:#b91c1c">این فرم فقط برای کاربران با نقش formeditor، marjoo، sale یا tehsale در دسترس است.</div>';
    }
    $allowed_ops = wc_suf_get_allowed_ops_for_current_user();
    $is_marjoo_only = wc_suf_is_marjoo_only_user();
    $is_sale_user = wc_suf_current_user_has_role( 'sale' );
    $is_tehsale_user = wc_suf_current_user_has_role( 'tehsale' );
    $can_use_main_onsite_sale_method = current_user_can( 'manage_options' ) || wc_suf_current_user_has_role( 'formeditor' );
    $atts = shortcode_atts(['key' => ''], $atts, 'stock_update_form');
    $current_user = wp_get_current_user();
    $display_name = trim( (string) $current_user->first_name . ' ' . (string) $current_user->last_name );
    if ( $display_name === '' ) {
        $display_name = (string) ( $current_user->display_name ?: $current_user->user_login );
    }

    $cache_key = 'wc_suf_products_cache_v250';
    $products = get_transient( $cache_key );
    if ( ! is_array($products) ) {
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'type' => ['simple','variation'],
            'return' => 'objects',
        ]);
        set_transient( $cache_key, $products, MINUTE_IN_SECONDS * 5 );
    }

    $make_label = function( $p ){
        if ( $p->is_type('variation') ) {
            $parent = wc_get_product( $p->get_parent_id() );
            $base   = $parent ? $parent->get_name() : ('Variation #'.$p->get_id());
            $attrs  = wc_get_formatted_variation( $p, true, false, false );
            $attrs  = trim( wp_strip_all_tags( (string) $attrs ) );
            $label  = trim( $base . ( $attrs ? ' – ' . $attrs : '' ) );
            if ( $label === '' ) $label = $p->get_name() ?: ('#'.$p->get_id());
            return $label;
        }
        $name = $p->get_name();
        return $name !== '' ? $name : ('#'.$p->get_id());
    };

    $picker_attr_defs = wc_suf_get_picker_attribute_defs();

    global $wpdb;
    $prod_table = $wpdb->prefix.'stock_production_inventory';
    $prod_rows = $wpdb->get_results( "SELECT product_id, qty FROM `$prod_table`", ARRAY_A );
    $prod_map = [];
    if ( is_array($prod_rows) ) {
        foreach ( $prod_rows as $pr ) {
            $ppid = isset($pr['product_id']) ? absint($pr['product_id']) : 0;
            if ( ! $ppid ) continue;
            $prod_map[$ppid] = (int) ($pr['qty'] ?? 0);
        }
    }

    $bucketed = [];
    $preferred_order = [4,6,8,12];
    foreach ($products as $p){
        $pid = $p->get_id();
        $wc_stock   = (int) max(0, (int) ($p->get_stock_quantity() ?? 0));
        $prod_stock = isset($prod_map[$pid]) ? (int) $prod_map[$pid] : 0;
        $teh_stock  = 0;
        $teh_ok     = 0;

        if ( function_exists('yith_pos_stock_management') ) {
            $teh_read = wc_suf_yith_get_store_stock( $p, (int) WC_SUF_TEHRANPARS_STORE_ID );
            if ( false !== $teh_read ) {
                $teh_stock = (int) $teh_read;
                $teh_ok = 1;
            }
        }

        $label = $make_label($p);
        $row = [
            'id'           => $pid,
            'label'        => $label,
            'stock'        => $prod_stock,
            'prod_stock'   => $prod_stock,
            'wc_stock'     => $wc_stock,
            'teh_stock'    => $teh_stock,
            'teh_stock_ok' => $teh_ok,
            'search'       => wc_suf_build_search_blob( $p ),
            'attrs'        => wc_suf_collect_product_attributes_for_picker( $p ),
        ];
        $cap = wc_suf_capacity_from_product($p) ?: 0;
        $bucketed[$cap][] = $row;
    }
    foreach ($bucketed as $cap => &$list) { usort($list, fn($a,$b)=> strcasecmp($a['label'],$b['label'])); } unset($list);

    $name_select_html = '';
    $id_select_html   = '';
    $all              = [];

    $emit_group = function($cap, $rows) use (&$name_select_html,&$id_select_html,&$all){
        $group_label = ($cap ? $cap.' نفره' : 'سایر');
        $name_select_html .= '<optgroup label="'.esc_attr($group_label).'">';
        $id_select_html   .= '<optgroup label="'.esc_attr($group_label).'">';
        foreach ($rows as $row) {
            $opt_text = '['.esc_html($row['id']).' | موجودی: '.esc_html($row['stock']).'] '.esc_html($row['label']);
            $name_select_html .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'. $opt_text .'</option>';
            $id_select_html   .= '<option value="'.esc_attr($row['id']).'" data-stock="'.esc_attr($row['stock']).'">'. esc_html($row['id']).'</option>';
            $all[] = $row;
        }
        $name_select_html .= '</optgroup>';
        $id_select_html   .= '</optgroup>';
    };

    foreach ($preferred_order as $cap) if (!empty($bucketed[$cap])) $emit_group($cap, $bucketed[$cap]);
    $other_caps = array_diff(array_keys($bucketed), array_merge($preferred_order, [0]));
    sort($other_caps, SORT_NUMERIC);
    foreach ($other_caps as $cap) $emit_group($cap, $bucketed[$cap]);
    if (!empty($bucketed[0])) $emit_group(0, $bucketed[0]);

    ob_start(); ?>
    <style>
      .wc-suf-optype-buttons{display:flex; gap:10px; align-items:center; flex-wrap:wrap}
      .wc-suf-optype-btn{
        display:inline-flex; align-items:center; justify-content:center; min-height:42px;
        padding:8px 14px; border:1px solid #cbd5e1; border-radius:10px;
        background:#fff; color:#0f172a; font-weight:700; cursor:pointer; user-select:none;
        transition:all .15s ease;
      }
      .wc-suf-optype-btn input{position:absolute; opacity:0; pointer-events:none}
      .wc-suf-optype-btn:hover{border-color:#64748b; background:#f8fafc}
      .wc-suf-optype-btn.is-active{border-color:#2563eb; background:#2563eb; color:#fff; box-shadow:0 0 0 2px #bfdbfe}
      .wc-suf-optype-btn.is-disabled{opacity:.55; cursor:not-allowed}
      .wc-suf-sale-customer-wrap{display:none; flex-direction:column; gap:10px; width:100%}
      .wc-suf-sale-customer-row{display:grid; grid-template-columns:1fr 1fr; gap:10px; width:100%}
      .wc-suf-sale-customer-field{display:flex; align-items:center; gap:8px}
      .wc-suf-sale-customer-field label{min-width:120px}
      .wc-suf-sale-customer-field input,
      .wc-suf-sale-customer-field textarea{width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:10px}
      .wc-suf-sale-customer-field textarea{min-height:82px; resize:vertical; background:#fff}
      .wc-suf-sale-method-wrap{display:none; align-items:center; gap:8px; flex-wrap:wrap}
      .wc-suf-sale-method-wrap label{font-weight:700; min-width:120px}
      .wc-suf-sale-method-wrap select{padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:280px; background:#fff}
      @media (max-width: 768px){
        #optype-block{gap:10px !important; padding:10px !important}
        #optype-block > div:first-child{min-width:unset !important; width:100%}
        .wc-suf-optype-buttons{display:grid; grid-template-columns:1fr; width:100%}
        .wc-suf-optype-btn{width:100%; justify-content:flex-start; padding:12px}
        .wc-suf-sale-customer-wrap{gap:8px}
        .wc-suf-sale-customer-row{grid-template-columns:1fr}
        .wc-suf-sale-customer-field{flex-direction:column; align-items:stretch}
        .wc-suf-sale-customer-field label{min-width:unset; width:100%; font-size:13px}
      }
    </style>

    <div id="stock-form" dir="rtl" style="display:grid; gap:12px; align-items:center;">
        <div id="wc-suf-form-mode-title" style="padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; background:#f8fafc; font-weight:800; color:#0f172a">ثبت سفارش جدید</div>
        <div style="background:#ecfeff; border:1px solid #bae6fd; color:#0f172a; border-radius:10px; padding:10px 12px; font-weight:700">کاربر: <?php echo esc_html($display_name); ?></div>

        <div id="optype-block" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap; background:#f9fafb; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px">
          <div style="font-weight:700; min-width:220px">نوع عملیات موجودی / لیبل</div>
          <div class="wc-suf-optype-buttons">
          <?php if ( in_array( 'in', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="in">
            <input type="radio" name="op-type" value="in">
            <span>ورود به انبار تولید</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'out', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="out">
            <input type="radio" name="op-type" value="out">
            <span>خروج از انبار</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'transfer', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="transfer">
            <input type="radio" name="op-type" value="transfer">
            <span>انتقال بین انبارها</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'return', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="return">
            <input type="radio" name="op-type" value="return">
            <span>مرجوعی</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'sale', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="sale">
            <input type="radio" name="op-type" value="sale">
            <span>فروش</span>
          </label>
          <?php endif; ?>
          <?php if ( in_array( 'onlyLabel', $allowed_ops, true ) ) : ?>
          <label class="wc-suf-optype-btn" data-op="onlyLabel">
            <input type="radio" name="op-type" value="onlyLabel">
            <span>صرفاً چاپ لیبل</span>
          </label>
          <?php endif; ?>
          </div>
          <span class="suf-muted">(پس از انتخاب، قابل تغییر نیست مگر با رفرش)</span>
        </div>

            
        <div id="out-destination-wrap" style="display:none; gap:8px; align-items:center; flex-wrap:wrap">
          <label style="min-width:120px">مقصد خروج:</label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="out-destination" value="main">
            <span>خروج به انبار اصلی</span>
          </label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer">
            <input type="radio" name="out-destination" value="teh">
            <span>خروج به انبار تهران پارس</span>
          </label>
        </div>

        <div id="transfer-controls-wrap" style="display:none; gap:10px; align-items:center; flex-wrap:wrap">
          <label for="transfer-source" style="min-width:120px">انبار مبدا:</label>
          <select id="transfer-source" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:180px">
            <option value="">انتخاب انبار مبدا...</option>
            <option value="main">انبار اصلی</option>
            <option value="teh">انبار تهران پارس</option>
          </select>

          <label for="transfer-destination" style="min-width:120px">انبار مقصد:</label>
          <select id="transfer-destination" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:180px">
            <option value="">انتخاب انبار مقصد...</option>
          </select>
        </div>

        <div id="return-controls-wrap" style="display:none; gap:10px; align-items:center; flex-wrap:wrap">
          <label for="return-destination" style="min-width:120px">انبار مرجوعی:</label>
          <select id="return-destination" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:180px">
            <option value="">انتخاب انبار...</option>
            <?php if ( ! $is_marjoo_only ) : ?>
            <option value="main">انبار اصلی</option>
            <?php endif; ?>
            <option value="teh">انبار تهران پارس</option>
          </select>

          <label for="return-reason" style="min-width:120px">علت مرجوعی:</label>
          <select id="return-reason" style="padding:8px; border:1px solid #e5e7eb; border-radius:10px; min-width:280px">
            <option value="">انتخاب علت...</option>
            <option value="انصراف از خرید مشتری">۱- انصراف از خرید مشتری</option>
            <option value="تعویض طرح یا رنگ">۲- تعویض طرح یا رنگ</option>
            <option value="خرابی کالا (استوک)">۳- خرابی کالا (استوک)</option>
          </select>
        </div>
        
        <div id="sale-customer-wrap" class="wc-suf-sale-customer-wrap">
          <div id="sale-method-wrap" class="wc-suf-sale-method-wrap">
            <label for="sale-method">نحوه فروش:</label>
            <select id="sale-method">
              <option value="">انتخاب نحوه فروش...</option>
              <?php if ( $can_use_main_onsite_sale_method ) : ?>
              <option value="main_onsite">۱- فروش حضوری انبار اصلی</option>
              <?php endif; ?>
              <option value="tehranpars_onsite">۲- فروش حضوری شعبه تهرانپارس</option>
              <option value="post">۳- پست</option>
              <option value="snap">۴- اسنپ</option>
              <option value="tipax">۵- تیپاکس</option>
            </select>
          </div>
          <div class="wc-suf-sale-customer-row">
            <div class="wc-suf-sale-customer-field">
              <label for="sale-customer-mobile">شماره موبایل:</label>
              <input id="sale-customer-mobile" type="tel" placeholder="۰۹xxxxxxxxx">
            </div>
            <div class="wc-suf-sale-customer-field">
              <label for="sale-customer-name">نام و نام خانوادگی:</label>
              <input id="sale-customer-name" type="text" placeholder="مثال: علی رضایی">
            </div>
          </div>
          <div class="wc-suf-sale-customer-row">
            <div class="wc-suf-sale-customer-field" style="grid-column:1 / -1;">
              <label for="sale-customer-address">آدرس:</label>
              <textarea id="sale-customer-address" placeholder="آدرس کامل مشتری"></textarea>
            </div>
          </div>
        </div>

        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; opacity:.5" id="picker-open-block">
          <button type="button" id="btn-open-picker" style="padding:12px 18px; cursor:pointer; border:1px solid #10b981; border-radius:10px; background:#bbf7d0; color:#065f46; font-weight:700" disabled>➕ اضافه کردن محصولات</button>
          <span class="suf-muted">ابتدا نوع عملیات را انتخاب کنید، سپس محصولات را در پنجره انتخاب کنید.</span>
        </div>

        <table id="items-table" style="margin-top:10px; display:none; width:100%; border-collapse:collapse; border:1px solid #e5e7eb; font-size:14px">
          <thead>
            <tr style="background:#f3f4f6; border-bottom:1px solid #e5e7eb">
              <th style="padding:8px; text-align:right; width:110px">ID</th>
              <th style="padding:8px; text-align:right">محصول</th>
              <th style="padding:8px; text-align:center; width:140px">موجودی فعلی</th>
              <th style="padding:8px; text-align:center; width:280px">تعداد (+/−)</th>
              <th style="padding:8px; text-align:center; width:100px">حذف</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>

        <div id="items-total-wrap" style="display:none; font-weight:700; color:#1f2937">جمع کل تعداد: <span id="items-total-value">0</span></div>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
          <button type="button" id="btn-save" style="margin-top:10px; display:none; padding:12px 18px; cursor:pointer; border:1px solid #2563eb; background:#2563eb; color:#fff; border-radius:10px" disabled data-default-label="✅ ثبت نهایی">✅ ثبت نهایی</button>
          <button type="button" id="btn-save-pending" style="margin-top:10px; display:none; padding:12px 18px; cursor:pointer; border:1px solid #d97706; background:#f59e0b; color:#fff; border-radius:10px" disabled data-default-label="⏳ در انتظار">⏳ در انتظار</button>
        </div>

        <div id="save-result" style="display:none; margin-top:8px; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; background:#f9fafb"></div>
    </div>

    <div class="wc-suf-modal-overlay" id="wc-suf-modal-overlay" aria-hidden="true"></div>
    <div class="wc-suf-modal" id="wc-suf-modal" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="wc-suf-modal-card">
        <div class="wc-suf-modal-head">
          <div>
            <div class="wc-suf-modal-title">انتخاب محصولات (جستجو + فیلتر ویژگی‌ها)</div>
            <div class="suf-muted" id="wc-suf-modal-subtitle">ابتدا جستجو کنید، سپس در صورت نیاز از فیلتر ویژگی‌ها استفاده کنید. برای هر محصول تعداد را وارد کنید و «اضافه کن» را بزنید.</div>
          </div>
          <button type="button" class="wc-suf-modal-close" id="wc-suf-modal-close" aria-label="بستن">✕</button>
        </div>

        <div class="wc-suf-modal-body">
          <div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap; margin-bottom:10px">
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; flex:1; min-width:260px">
              <label for="wc-suf-picker-q" style="min-width:80px; font-weight:700">جستجو:</label>
              <input id="wc-suf-picker-q" type="text" placeholder="مثلاً توران مربع / زرشکی / کد کالا یا ID" style="flex:1; min-width:260px; padding:10px; border:1px solid #e5e7eb; border-radius:12px; font-size:16px">
              <button type="button" id="wc-suf-picker-clear" aria-label="پاک کردن جستجو" title="پاک کردن" style="width:44px; height:44px; display:inline-flex; align-items:center; justify-content:center; padding:0; border:1px solid #2563eb; background:#2563eb; color:#fff; border-radius:12px; cursor:pointer; font-size:18px; font-weight:800">✕</button>
            </div>

            <div style="width:100%; margin-top:8px">
              <div id="wc-suf-picker-filters" class="wc-suf-filter-grid"></div>
            </div>
          </div>

          <div class="suf-muted" style="margin-bottom:8px">
            نکته: جستجو نام + ورییشن (ویژگی‌ها) را پوشش می‌دهد. فیلترها همزمان با جستجو اعمال می‌شوند.
          </div>

          <div id="wc-suf-picker-results" style="border:1px solid #e5e7eb; border-radius:12px; overflow:hidden"></div>
        </div>

        <div class="wc-suf-modal-foot">
          <div class="suf-muted" id="wc-suf-picker-selected-info">هیچ موردی انتخاب نشده است.</div>
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap">
            <button type="button" id="wc-suf-picker-add" style="padding:12px 16px; cursor:pointer; border:1px solid #10b981; border-radius:12px; background:#10b981; color:#fff; font-weight:800">✅ اضافه کن</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    const ajaxurl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";

    jQuery(function($){
        const allProducts = <?php echo wp_json_encode($all); ?>;
        const pickerAttrDefs = <?php echo wp_json_encode($picker_attr_defs); ?>;
        const isMarjooOnly = <?php echo $is_marjoo_only ? 'true' : 'false'; ?>;
        const allowedOps = <?php echo wp_json_encode( $allowed_ops ); ?>;
        const isSaleUserRole = <?php echo $is_sale_user ? 'true' : 'false'; ?>;
        const isTehSaleUserRole = <?php echo $is_tehsale_user ? 'true' : 'false'; ?>;
        const canUseMainOnsiteSaleMethod = <?php echo $can_use_main_onsite_sale_method ? 'true' : 'false'; ?>;

        const defaultShortcodeKey = "<?php echo esc_js($atts['key']); ?>";
        const urlParams = new URLSearchParams(window.location.search);
        const urlKey = urlParams.get('key') || urlParams.get('code') || '';
        const urlMode = String(urlParams.get('mode') || '').toLowerCase();
        const urlOrderId = parseInt(urlParams.get('order_id') || '0', 10) || 0;
        const isEditModeRequested = (urlMode === 'edit' && urlOrderId > 0);
        const userCode = urlKey || defaultShortcodeKey;

        const items = [];
        let globalToastTimer = null;
        const globalToastDurationMs = 5000;

        function ensureGlobalFeedbackUi(){
            if($('#wc-suf-global-toast').length === 0){
                $('body').append(
                    '<div id="wc-suf-global-toast" style="display:none; position:fixed; left:50%; bottom:24px; transform:translateX(-50%); z-index:1000003; min-width:260px; max-width:min(92vw, 560px); padding:12px 14px; border-radius:12px; border:1px solid transparent; box-shadow:0 10px 24px rgba(15,23,42,.18); font-weight:700;">'
                    + '<div style="display:flex; align-items:center; gap:10px">'
                    + '<div id="wc-suf-global-toast-text" style="flex:1; text-align:right"></div>'
                    + '<button type="button" id="wc-suf-global-toast-close" aria-label="بستن پیام" style="border:1px solid currentColor; background:transparent; color:inherit; border-radius:8px; cursor:pointer; font-weight:800; padding:2px 8px; line-height:1.4">✕</button>'
                    + '</div></div>'
                );
            }
            if($('#wc-suf-confirm-modal').length === 0){
                $('body').append(
                    '<div id="wc-suf-confirm-modal" style="display:none; position:fixed; inset:0; z-index:1000004; background:rgba(15,23,42,.35); align-items:center; justify-content:center; padding:16px;">'
                    + '<div style="width:min(92vw,460px); background:#fff; border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 18px 48px rgba(15,23,42,.25); padding:16px;">'
                    + '<div id="wc-suf-confirm-modal-text" style="font-weight:700; color:#0f172a; line-height:1.9; margin-bottom:14px; white-space:pre-line;"></div>'
                    + '<div style="display:flex; justify-content:flex-start; direction:rtl; gap:10px;">'
                    + '<button type="button" id="wc-suf-confirm-ok" style="padding:8px 14px; border:1px solid #2563eb; border-radius:10px; background:#2563eb; color:#fff; font-weight:700; cursor:pointer">بله</button>'
                    + '<button type="button" id="wc-suf-confirm-cancel" style="padding:8px 14px; border:1px solid #94a3b8; border-radius:10px; background:#fff; color:#334155; font-weight:700; cursor:pointer">لغو</button>'
                    + '</div></div></div>'
                );
            }
        }
        ensureGlobalFeedbackUi();

        function showGlobalToast(message, isSuccess){
            const msg = String(message || '');
            const bg = isSuccess ? '#ecfdf5' : '#fef2f2';
            const color = isSuccess ? '#065f46' : '#b91c1c';
            const borderColor = isSuccess ? '#10b981' : '#fca5a5';
            if(globalToastTimer){ clearTimeout(globalToastTimer); globalToastTimer = null; }
            $('#wc-suf-global-toast-text').text(msg);
            $('#wc-suf-global-toast').stop(true, true).css({background:bg, color:color, borderColor:borderColor}).fadeIn(160);
            globalToastTimer = setTimeout(function(){
                $('#wc-suf-global-toast').fadeOut(260);
                globalToastTimer = null;
            }, globalToastDurationMs);
        }
        $(document).on('click', '#wc-suf-global-toast-close', function(){
            if(globalToastTimer){ clearTimeout(globalToastTimer); globalToastTimer = null; }
            $('#wc-suf-global-toast').stop(true, true).fadeOut(120);
        });

        function askForConfirmation(message, onOk, onCancel){
            $('#wc-suf-confirm-modal-text').text(String(message || ''));
            $('#wc-suf-confirm-modal').fadeIn(120).css('display', 'flex');
            $('#wc-suf-confirm-ok').off('click').on('click', function(){
                $('#wc-suf-confirm-modal').fadeOut(120);
                if(typeof onOk === 'function') onOk();
            });
            $('#wc-suf-confirm-cancel').off('click').on('click', function(){
                $('#wc-suf-confirm-modal').fadeOut(120);
                if(typeof onCancel === 'function') onCancel();
            });
        }
        let opType = null;
        let outDestination = null;
        let transferSource = null;
        let transferDestination = null;
        let returnDestination = null;
        let returnReason = '';
        let saleCustomerName = '';
        let saleCustomerMobile = '';
        let saleCustomerAddress = '';
        let saleMethod = '';
        let saleHoldOrderId = 0;
        let isEditModeActive = false;
        let editOrderNumber = '';

        const $overlay = $('#wc-suf-modal-overlay');
        const $modal   = $('#wc-suf-modal');
        const $q       = $('#wc-suf-picker-q');
        const $results = $('#wc-suf-picker-results');
        const $info    = $('#wc-suf-picker-selected-info');
        const $filters = $('#wc-suf-picker-filters');
        const $modalSubtitle = $('#wc-suf-modal-subtitle');
        const $modeTitle = $('#wc-suf-form-mode-title');
        const defaultSaveLabel = $('#btn-save').data('default-label') || '✅ ثبت نهایی';
        const defaultSavePendingLabel = $('#btn-save-pending').data('default-label') || '⏳ در انتظار';

        function failEditMode(message){
            const msg = String(message || 'ویرایش سفارش ممکن نیست.');
            isEditModeActive = false;
            $modeTitle.text('خطا در ورود به حالت ویرایش');
            $('#save-result')
                .html('<div style="font-weight:700; color:#b91c1c">'+escapeHtml(msg)+'</div>')
                .show();
            $('#stock-form').find('input, select, textarea, button').prop('disabled', true);
            $('#btn-save').hide();
            $('#btn-save-pending').hide();
        }

        function setModeUi(){
            if(isEditModeActive){
                const num = editOrderNumber ? ('#' + editOrderNumber) : ('#' + saleHoldOrderId);
                $modeTitle.text('ویرایش سفارش ' + num);
                $('#btn-save').text('✅ ذخیره تغییرات');
                $('#btn-save-pending').text('⏳ ذخیره موقت تغییرات');
            }else{
                $modeTitle.text('ثبت سفارش جدید');
                $('#btn-save').text(defaultSaveLabel);
                $('#btn-save-pending').text(defaultSavePendingLabel);
            }
        }

        function syncOpTypeButtonsState(){
            $('.wc-suf-optype-btn').each(function(){
                const $label = $(this);
                const $radio = $label.find('input[name="op-type"]');
                $label.toggleClass('is-active', $radio.is(':checked'));
                $label.toggleClass('is-disabled', $radio.is(':disabled') && !$radio.is(':checked'));
            });
        }

        function activateOpType(nextOpType){
            $('#save-result').hide().empty();
            if(opType) return false;
            opType = String(nextOpType || '');
            if(allowedOps.indexOf(opType) === -1){
                opType = null;
                syncOpTypeButtonsState();
                return false;
            }

            $('input[name="op-type"]').prop('disabled', true);
            syncOpTypeButtonsState();

            if(opType === 'out'){
                $('#out-destination-wrap').css('display','flex');
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').hide();
                $('#sale-customer-wrap').hide();
            } else if (opType === 'transfer') {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').css('display','flex');
                $('#return-controls-wrap').hide();
                $('#sale-customer-wrap').hide();
                syncTransferDestinationOptions();
            } else if (opType === 'return') {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').css('display','flex');
                $('#sale-customer-wrap').hide();
            } else if (opType === 'sale' || opType === 'sale_teh') {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').hide();
                $('#sale-customer-wrap').css('display','flex');
                $('#sale-method-wrap').css('display','flex');
            } else {
                $('#out-destination-wrap').hide();
                $('#transfer-controls-wrap').hide();
                $('#return-controls-wrap').hide();
                $('#sale-customer-wrap').hide();
                $('#sale-method-wrap').hide();
            }

            refreshPickerOpenButton();
            updateModalSubtitle();
            renderTable();
            return true;
        }

        function loadSaleOrderForEdit(){
            if(!isEditModeRequested) return;
            $.post(ajaxurl, {
                action: 'wc_suf_get_sale_order_for_edit',
                order_id: urlOrderId,
                _wpnonce: '<?php echo wp_create_nonce('wc_suf_get_sale_order_for_edit'); ?>'
            }).done(function(res){
                if(!(res && res.success && res.data)){
                    failEditMode((res && res.data && res.data.message) ? res.data.message : 'سفارش برای ویرایش قابل بارگذاری نیست.');
                    return;
                }
                const data = res.data || {};
                const nextOpType = String(data.op_type || '');
                const $targetRadio = $('input[name="op-type"][value="' + nextOpType + '"]');
                if(!$targetRadio.length){
                    failEditMode('نوع عملیات این سفارش در فرم فعلی در دسترس نیست.');
                    return;
                }
                $targetRadio.prop('checked', true);
                if(!activateOpType(nextOpType)){
                    failEditMode('شما به نوع عملیات این سفارش دسترسی ندارید.');
                    return;
                }

                saleHoldOrderId = parseInt(data.order_id || 0, 10) || 0;
                editOrderNumber = String(data.order_number || saleHoldOrderId || '');
                isEditModeActive = true;
                setModeUi();

                saleCustomerName = String(data.sale_customer_name || '');
                saleCustomerMobile = String(data.sale_customer_mobile || '');
                saleCustomerAddress = String(data.sale_customer_address || '');
                saleMethod = String(data.sale_method || '');
                $('#sale-customer-name').val(saleCustomerName);
                $('#sale-customer-mobile').val(saleCustomerMobile);
                $('#sale-customer-address').val(saleCustomerAddress);
                $('#sale-method').val(saleMethod);
                applySaleAddressRule();

                items.length = 0;
                const editItems = Array.isArray(data.items) ? data.items : [];
                editItems.forEach(function(row){
                    const pid = parseInt(row.id || 0, 10) || 0;
                    if(pid <= 0) return;
                    const product = findById(pid);
                    if(!product) return;
                    const allocatedQty = Math.max(0, parseInt(row.allocated_qty || 0, 10) || 0);
                    const pendingQty = Math.max(0, parseInt(row.pending_qty || 0, 10) || 0);
                    const requestedQty = Math.max(1, parseInt(row.qty || 0, 10) || 1);
                    const mainStockNow = Math.max(0, findMainStockById(pid));
                    const baseStockForEdit = Math.max(0, allocatedQty + mainStockNow);
                    items.push({
                        id: pid,
                        name: product.label,
                        stock: findProductionStockById(pid),
                        qty: requestedQty,
                        sale_base_stock: baseStockForEdit,
                        sale_allocated_qty: allocatedQty,
                        sale_pending_qty: pendingQty
                    });
                });

                renderTable();
                refreshPickerOpenButton();
                refreshActionButtons();
            }).fail(function(){
                failEditMode('خطای ارتباطی هنگام بارگذاری سفارش برای ویرایش.');
            });
        }

        const pickerQty = Object.create(null);
        const activeFilters = Object.create(null); // tax => selectedValueNormalized

        function escapeHtml(s){
            return String(s)
                .replaceAll('&','&amp;')
                .replaceAll('<','&lt;')
                .replaceAll('>','&gt;')
                .replaceAll('"','&quot;')
                .replaceAll("'","&#039;");
        }

        function norm(s){
            s = String(s || '').trim();
            s = s.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
            s = s.replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
            return s.toLowerCase();
        }
        function normalizeDigits(s){
            return String(s || '')
                .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
                .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        }
        function normalizeMobileInput(s){
            return normalizeDigits(s).replace(/[^\d]/g,'');
        }

        function findById(id){ return allProducts.find(p => String(p.id) === String(id)); }
        function findLabelById(id){ const f = findById(id); return f ? f.label : ''; }
        function findProductionStockById(id){ const f = findById(id); return f ? (+f.prod_stock || 0) : 0; }
        function findMainStockById(id){ const f = findById(id); return f ? (+f.wc_stock || 0) : 0; }
        function recomputeSaleSplitForItem(it){
            if(!it) return;
            const requested = Math.max(0, parseInt(it.qty, 10) || 0);
            const sourceStockRaw = Number.isFinite(+it.sale_base_stock) ? (+it.sale_base_stock) : findMainStockById(it.id);
            const sourceStock = Math.max(0, sourceStockRaw || 0);
            it.sale_allocated_qty = Math.min(requested, sourceStock);
            it.sale_pending_qty = Math.max(0, requested - it.sale_allocated_qty);
        }
        function recomputeSaleSplitForAll(){
            if(!(opType === 'sale' || opType === 'sale_teh')) return;
            items.forEach(function(it){ recomputeSaleSplitForItem(it); });
        }
        function hasAnyPendingSaleItem(){
            if(!(opType === 'sale' || opType === 'sale_teh')) return false;
            return items.some(function(it){ return (parseInt(it.sale_pending_qty, 10) || 0) > 0; });
        }
        function getSaleHoldPayloadItems(){
            if(!(opType === 'sale' || opType === 'sale_teh')) return items;
            const payload = [];
            items.forEach(function(it){
                const allocatedQty = Math.max(0, parseInt(it.sale_allocated_qty, 10) || 0);
                if(allocatedQty <= 0) return;
                payload.push({
                    id: it.id,
                    qty: allocatedQty,
                    requested_qty: Math.max(0, parseInt(it.qty, 10) || 0),
                    pending_qty: Math.max(0, parseInt(it.sale_pending_qty, 10) || 0),
                });
            });
            return payload;
        }
        function warehouseLabel(code){
            if(code === 'main') return 'انبار اصلی';
            if(code === 'teh') return 'انبار تهران پارس';
            if(code === 'production') return 'انبار تولید';
            return '';
        }
        function updateModalSubtitle(){
            let subtitle = 'ابتدا جستجو کنید، سپس در صورت نیاز از فیلتر ویژگی‌ها استفاده کنید. برای هر محصول تعداد را وارد کنید و «اضافه کن» را بزنید.';
            if(opType === 'transfer' && transferSource && transferDestination){
                subtitle = `در حال انتقال کالا از مبدا (${warehouseLabel(transferSource)}) به مقصد (${warehouseLabel(transferDestination)}) هستید.`;
            }
            $modalSubtitle.text(subtitle);
        }
        function updateProductStocksInMemory(stocks){
            if(!stocks || typeof stocks !== 'object') return;
            for(const pid in stocks){
                if(!Object.prototype.hasOwnProperty.call(stocks, pid)) continue;
                const product = findById(pid);
                const row = stocks[pid] || {};
                if(!product) continue;
                if(row.prod_stock != null){
                    product.prod_stock = +row.prod_stock || 0;
                    product.stock = +row.prod_stock || 0;
                }
                if(row.wc_stock != null){
                    product.wc_stock = +row.wc_stock || 0;
                }
                if(row.teh_stock != null){
                    product.teh_stock = +row.teh_stock || 0;
                }
                if(row.teh_stock_ok != null){
                    product.teh_stock_ok = +row.teh_stock_ok ? 1 : 0;
                }
            }
            recomputeSaleSplitForAll();
        }
        function refreshStocksBeforeResult(ids){
            const normalizedIds = Array.isArray(ids)
                ? ids.map(v => parseInt(v, 10)).filter(v => Number.isFinite(v) && v > 0)
                : [];
            if(normalizedIds.length === 0){
                return $.Deferred().resolve({}).promise();
            }
            return $.post(ajaxurl, {
                action   : 'wc_suf_refresh_stocks',
                ids      : JSON.stringify(normalizedIds),
                _wpnonce : '<?php echo wp_create_nonce('wc_suf_refresh_stocks'); ?>'
            });
        }
        let saleStocksRefreshTimer = null;
        function scheduleSaleStocksRefresh(ids, delayMs){
            if(!(opType === 'sale' || opType === 'sale_teh')) return;
            const normalizedIds = Array.isArray(ids)
                ? ids.map(v => parseInt(v, 10)).filter(v => Number.isFinite(v) && v > 0)
                : [];
            if(normalizedIds.length === 0) return;
            if(saleStocksRefreshTimer){
                clearTimeout(saleStocksRefreshTimer);
            }
            saleStocksRefreshTimer = setTimeout(function(){
                refreshStocksBeforeResult(normalizedIds).done(function(refreshRes){
                    if(refreshRes && refreshRes.success && refreshRes.data && refreshRes.data.stocks){
                        updateProductStocksInMemory(refreshRes.data.stocks);
                        renderTable();
                        renderPickerResults();
                    }
                });
            }, Math.max(0, parseInt(delayMs, 10) || 0));
        }
        function syncSaleHoldOrder(showErrors){
            if(!(opType === 'sale' || opType === 'sale_teh')) return $.Deferred().resolve({success:true}).promise();
            if(!Array.isArray(items) || items.length === 0){
                if(saleHoldOrderId){
                    return $.post(ajaxurl, {
                        action   : 'wc_suf_sync_sale_hold_order',
                        order_id : saleHoldOrderId,
                        op_type  : opType,
                        items    : JSON.stringify([]),
                        sale_customer_name : String(saleCustomerName || ''),
                        sale_customer_mobile : String(saleCustomerMobile || ''),
                        sale_customer_address : String(saleCustomerAddress || ''),
                        sale_method : String(saleMethod || ''),
                        _wpnonce : '<?php echo wp_create_nonce('wc_suf_sync_sale_hold_order'); ?>'
                    });
                }
                return $.Deferred().resolve({success:true}).promise();
            }
            return $.post(ajaxurl, {
                action   : 'wc_suf_sync_sale_hold_order',
                order_id : saleHoldOrderId,
                op_type  : opType,
                items    : JSON.stringify(getSaleHoldPayloadItems()),
                sale_customer_name : String(saleCustomerName || ''),
                sale_customer_mobile : String(saleCustomerMobile || ''),
                sale_customer_address : String(saleCustomerAddress || ''),
                sale_method : String(saleMethod || ''),
                _wpnonce : '<?php echo wp_create_nonce('wc_suf_sync_sale_hold_order'); ?>'
            }).done(function(res){
                if(res && res.success && res.data && res.data.order_id){
                    saleHoldOrderId = parseInt(res.data.order_id, 10) || 0;
                } else if (showErrors) {
                    showGlobalToast((res && res.data && res.data.message) ? res.data.message : 'خطا در همگام‌سازی سفارش هولد.', false);
                }
            }).fail(function(){
                if(showErrors){
                    showGlobalToast('خطای ارتباطی هنگام همگام‌سازی سفارش هولد.', false);
                }
            });
        }
        function getPickerMetaLine(p){
            const pid = String(p.id || '');
            const prod = (+p.prod_stock || 0);
            const destination = (opType === 'return') ? returnDestination : (opType === 'transfer' ? transferDestination : outDestination);
            if(opType === 'in'){
                return `ID: ${pid} | موجودی انبار تولید: ${prod}`;
            }
            if(opType === 'transfer'){
                const sourceStock = getTransferWarehouseStockByProduct(p, transferSource);
                const destinationStock = getTransferWarehouseStockByProduct(p, transferDestination);
                return `ID: ${pid} | موجودی مبدا: ${sourceStock} | موجودی مقصد: ${destinationStock}`;
            }
            if(opType === 'out' || opType === 'return'){
                if(destination === 'main'){
                    return `ID: ${pid} | موجودی انبار تولید: ${prod} | موجودی انبار اصلی: ${(+p.wc_stock || 0)}`;
                }
                if(destination === 'teh'){
                    const teh = (+p.teh_stock || 0);
                    const note = (+p.teh_stock_ok || 0) ? '' : ' (نامشخص)';
                    return `ID: ${pid} | موجودی انبار تولید: ${prod} | موجودی تهران پارس: ${teh}${note}`;
                }
                return `ID: ${pid} | موجودی انبار تولید: ${prod}`;
            }
            if(opType === 'sale' || opType === 'sale_teh'){
                return `ID: ${pid} | موجودی انبار تولید: ${prod} | موجودی انبار اصلی: ${(+p.wc_stock || 0)}`;
            }
            return `ID: ${pid} | موجودی انبار تولید: ${prod}`;
        }

        function canSave(){
            if(opType !== 'in' && opType !== 'out' && opType !== 'transfer' && opType !== 'return' && opType !== 'onlyLabel' && opType !== 'sale' && opType !== 'sale_teh') return false;
            if(allowedOps.indexOf(opType) === -1) return false;
            if(opType === 'out' && !outDestination) return false;
            if(opType === 'transfer' && (!transferSource || !transferDestination || transferSource === transferDestination)) return false;
            if(opType === 'return' && (!returnDestination || !returnReason)) return false;
            if((opType === 'sale' || opType === 'sale_teh') && !isSaleCustomerDataValid(false)) return false;
            if(isMarjooOnly && returnDestination !== 'teh') return false;
            return items.length > 0;
        }
        function canSavePending(){
            if(opType !== 'sale' && opType !== 'sale_teh') return false;
            if(saleMethod === 'main_onsite' || saleMethod === 'tehranpars_onsite') return false;
            return canSave();
        }
        function refreshActionButtons(){
            const isSaleOperation = (opType === 'sale' || opType === 'sale_teh');
            if(items.length <= 0){
                $('#btn-save').prop('disabled', true).hide();
                $('#btn-save-pending').prop('disabled', true).hide();
                return;
            }
            const hasPending = hasAnyPendingSaleItem();
            $('#btn-save').show().prop('disabled', !canSave() || hasPending);
            if(isSaleOperation && canSavePending()){
                $('#btn-save-pending').show().prop('disabled', !canSavePending());
            }else{
                $('#btn-save-pending').prop('disabled', true).hide();
            }
        }

        function canOpenPicker(){
            if(!opType) return false;
            if(allowedOps.indexOf(opType) === -1) return false;
            if(opType === 'out' && !outDestination) return false;
            if(opType === 'transfer' && (!transferSource || !transferDestination || transferSource === transferDestination)) return false;
            if(opType === 'return' && (!returnDestination || !returnReason)) return false;
            if((opType === 'sale' || opType === 'sale_teh') && !isSaleCustomerDataValid(false)) return false;
            if(isMarjooOnly && returnDestination !== 'teh') return false;
            return true;
        }

        function isSaleCustomerDataValid(showAlert){
            const name = String(saleCustomerName || '').trim();
            const mobile = normalizeMobileInput(saleCustomerMobile);
            const address = String(saleCustomerAddress || '').trim();
            if(!saleMethod){
                if(showAlert) showGlobalToast('نحوه فروش را انتخاب کنید.', false);
                return false;
            }
            if(name.length < 3){
                if(showAlert) showGlobalToast('نام و نام خانوادگی را کامل وارد کنید.', false);
                return false;
            }
            if(!/^0\d{10}$/.test(mobile)){
                if(showAlert) showGlobalToast('شماره موبایل باید با 0 شروع شود و دقیقاً 11 رقم باشد.', false);
                return false;
            }
            if(address.length < 8){
                if(showAlert) showGlobalToast('آدرس مشتری را کامل وارد کنید.', false);
                return false;
            }
            return true;
        }

        function getSaleAddressRuleByMethod(method){
            const saleMethodKey = String(method || '');
            if(saleMethodKey === 'tehranpars_onsite'){
                return { fixedOnly: true, fixedText: 'به شعبه تهرانپارس تحویل گردد.' };
            }
            if(saleMethodKey === 'snap'){
                return { fixedOnly: false, fixedPrefix: 'اسنپ شود به ' };
            }
            if(saleMethodKey === 'tipax'){
                return { fixedOnly: false, fixedPrefix: 'تیپاکس شود به ' };
            }
            return { fixedOnly: false, fixedPrefix: '' };
        }

        function applySaleAddressRule(){
            const $address = $('#sale-customer-address');
            const rule = getSaleAddressRuleByMethod(saleMethod);
            const rawValue = String($address.val() || '');
            const prefix = String(rule.fixedPrefix || '');

            if(rule.fixedOnly){
                $address.prop('readonly', true).val(rule.fixedText);
                saleCustomerAddress = rule.fixedText;
                return;
            }

            $address.prop('readonly', false);
            let nextValue = rawValue;
            if(prefix && nextValue.indexOf(prefix) !== 0){
                nextValue = prefix + nextValue.trim();
            }
            if(nextValue !== rawValue){
                $address.val(nextValue);
            }
            saleCustomerAddress = nextValue;
        }

        function getTransferWarehouseStockByProduct(p, warehouse){
            if(!p) return 0;
            if(warehouse === 'main') return (+p.wc_stock || 0);
            if(warehouse === 'teh') return (+p.teh_stock || 0);
            return 0;
        }

        function findTransferSourceStockById(id){
            const p = findById(id);
            return getTransferWarehouseStockByProduct(p, transferSource);
        }

        function syncTransferDestinationOptions(){
            const $destination = $('#transfer-destination');
            const current = String($destination.val() || '');
            let options = '<option value="">انتخاب انبار مقصد...</option>';
            if(transferSource === 'main'){
                options += '<option value="teh">انبار تهران پارس</option>';
            } else if (transferSource === 'teh'){
                options += '<option value="main">انبار اصلی</option>';
            } else {
                options += '<option value="main">انبار اصلی</option><option value="teh">انبار تهران پارس</option>';
            }
            $destination.html(options);
            if(current && current !== transferSource){
                $destination.val(current);
            }
            if($destination.val() === ''){
                transferDestination = null;
            } else {
                transferDestination = String($destination.val());
            }
        }

        function getDestinationInfoById(id){
            const p = findById(id);
            if(!p) return {label:'', stock:0};
            if(outDestination === 'main'){
                return {label:'موجودی انبار اصلی', stock:(+p.wc_stock || 0)};
            }
            if(outDestination === 'teh'){
                return {label:'موجودی انبار تهران‌پارس', stock:(+p.teh_stock || 0)};
            }
            return {label:'', stock:0};
        }

        function refreshPickerOpenButton(){
            const enabled = canOpenPicker();
            const $btn = $('#btn-open-picker');
            $('#picker-open-block').css('opacity', enabled ? 1 : 0.5);
            $btn.prop('disabled', !enabled);
            if(enabled){
                $btn.css({background:'#16a34a', borderColor:'#15803d', color:'#ffffff'});
            } else {
                $btn.css({background:'#bbf7d0', borderColor:'#10b981', color:'#065f46'});
            }
        }

        function renderTable(){
            const tbody = $('#items-table tbody').empty();
            const theadRow = $('#items-table thead tr').empty();

            const isOutMain = (opType === 'out' && outDestination === 'main');
            const isOutTeh  = (opType === 'out' && outDestination === 'teh');
            const isTransfer = (opType === 'transfer');
            const isReturnMain = (opType === 'return' && returnDestination === 'main');
            const isReturnTeh  = (opType === 'return' && returnDestination === 'teh');
            const isSaleOperation = (opType === 'sale' || opType === 'sale_teh');

            theadRow.append('<th style="padding:8px; text-align:right; width:110px">ID</th>');
            theadRow.append('<th style="padding:8px; text-align:right">محصول</th>');
            theadRow.append(`<th style="padding:8px; text-align:center; width:160px">${isTransfer ? 'موجودی انبار مبدا' : 'موجودی انبار تولید'}</th>`);
            if (isOutMain){
                theadRow.append('<th style="padding:8px; text-align:center; width:170px">موجودی انبار اصلی</th>');
            } else if (isOutTeh){
                theadRow.append('<th style="padding:8px; text-align:center; width:180px">موجودی انبار تهران‌پارس</th>');
            } else if (isTransfer){
                const dstLabel = (transferDestination === 'main') ? 'موجودی انبار اصلی' : (transferDestination === 'teh' ? 'موجودی انبار تهران‌پارس' : 'موجودی انبار مقصد');
                theadRow.append(`<th style="padding:8px; text-align:center; width:180px">${escapeHtml(dstLabel)}</th>`);
            } else if (isReturnMain){
                theadRow.append('<th style="padding:8px; text-align:center; width:170px">موجودی انبار اصلی</th>');
            } else if (isReturnTeh){
                theadRow.append('<th style="padding:8px; text-align:center; width:180px">موجودی انبار تهران‌پارس</th>');
            } else if (isSaleOperation){
                theadRow.append('<th style="padding:8px; text-align:center; width:170px">موجودی انبار اصلی</th>');
            }
            if (isSaleOperation){
                theadRow.append('<th style="padding:8px; text-align:center; width:150px">تخصیص از انبار</th>');
                theadRow.append('<th style="padding:8px; text-align:center; width:130px">در انتظار</th>');
            }
            theadRow.append('<th style="padding:8px; text-align:center; width:280px">تعداد (+/−)</th>');
            theadRow.append('<th style="padding:8px; text-align:center; width:100px">حذف</th>');

            if(items.length === 0){
                $('#items-table').hide();
                $('#btn-save').prop('disabled', true).hide();
                $('#btn-save-pending').prop('disabled', true).hide();
                $('#items-total-wrap').hide();
                $('#items-total-value').text('0');
                return;
            }
            $('#items-table').show();
            const hasPending = hasAnyPendingSaleItem();
            $('#btn-save').show().prop('disabled', !canSave() || hasPending);
            if(isSaleOperation && canSavePending()){
                $('#btn-save-pending').show().prop('disabled', !canSavePending());
            }else{
                $('#btn-save-pending').prop('disabled', true).hide();
            }
            const grandTotal = items.reduce((sum, it) => sum + (parseInt(it.qty, 10) || 0), 0);
            $('#items-total-value').text(String(grandTotal));
            $('#items-total-wrap').show();

            items.forEach((it,idx)=>{
                const tr = $('<tr style="border-top:1px solid #e5e7eb">');
                tr.append(`<td style="padding:8px">${escapeHtml(it.id)}</td>`);
                tr.append(`<td style="padding:8px">${escapeHtml(it.name)}</td>`);
                const sourceStock = isTransfer ? findTransferSourceStockById(it.id) : it.stock;
                tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(sourceStock)}</td>`);

                if (isOutMain || isOutTeh){
                    const dst = getDestinationInfoById(it.id);
                    tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(dst.stock)}</td>`);
                } else if (isTransfer){
                    const p = findById(it.id);
                    const dstStock = getTransferWarehouseStockByProduct(p, transferDestination);
                    tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(dstStock)}</td>`);
                } else if (isReturnMain || isReturnTeh){
                    const p = findById(it.id);
                    const returnStock = (returnDestination === 'main') ? (+p.wc_stock || 0) : (+p.teh_stock || 0);
                    tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(returnStock)}</td>`);
                } else if (isSaleOperation){
                    const baseMainStock = Math.max(0, Number.isFinite(+it.sale_base_stock) ? (+it.sale_base_stock) : findMainStockById(it.id));
                    const remainingMainStock = Math.max(0, baseMainStock - (parseInt(it.qty, 10) || 0));
                    tr.append(`<td style="padding:8px; text-align:center">${escapeHtml(remainingMainStock)}</td>`);
                }
                if (isSaleOperation){
                    recomputeSaleSplitForItem(it);
                    tr.append(`<td style="padding:8px; text-align:center; color:#065f46; font-weight:700">${escapeHtml(it.sale_allocated_qty || 0)}</td>`);
                    tr.append(`<td style="padding:8px; text-align:center; color:#b45309; font-weight:700">${escapeHtml(it.sale_pending_qty || 0)}</td>`);
                }

                const qtyControls = $(`
                  <td style="padding:6px; text-align:center">
                    <button class="row-dec" data-i="${idx}" style="font-size:18px; padding:4px 10px; margin-inline:4px">➖</button>
                    <input type="number" class="row-qty" data-i="${idx}" value="${escapeHtml(it.qty)}" min="1" style="width:80px; text-align:center; font-size:16px; padding:4px">
                    <button class="row-inc" data-i="${idx}" style="font-size:18px; padding:4px 10px; margin-inline:4px">➕</button>
                  </td>
                `);
                tr.append(qtyControls);
                tr.append(`<td style="padding:8px; text-align:center"><button data-i="${idx}" class="btn-del" style="cursor:pointer">❌</button></td>`);
                tbody.append(tr);
            });
        }

        function buildSubmitConfirmMessage(){
            const typeCount = items.length;
            const totalQty = items.reduce((sum, it) => sum + (parseInt(it.qty, 10) || 0), 0);

            if(opType === 'in'){
                return `در حال ورود ${typeCount} نوع محصول با مجموع ${totalQty} آیتم به انبار تولید هستید. آیا مطمئنید؟`;
            }

            if(opType === 'out'){
                let destinationLabel = 'انبار مقصد';
                if(outDestination === 'main') destinationLabel = 'انبار اصلی';
                if(outDestination === 'teh') destinationLabel = 'انبار تهران پارس';
                return `در حال خروج ${typeCount} نوع محصول و مجموعاً ${totalQty} آیتم کالا از انبار تولید به ${destinationLabel} هستید. آیا مطمئن هستید؟`;
            }
            if(opType === 'transfer'){
                const sourceLabel = transferSource === 'main' ? 'انبار اصلی' : 'انبار تهران پارس';
                const destinationLabel = transferDestination === 'main' ? 'انبار اصلی' : 'انبار تهران پارس';
                return `در حال انتقال ${typeCount} نوع محصول و مجموعاً ${totalQty} آیتم کالا از ${sourceLabel} به ${destinationLabel} هستید. آیا مطمئن هستید؟`;
            }

            if(opType === 'return'){
                let destinationLabel = 'انبار مقصد';
                if(returnDestination === 'main') destinationLabel = 'انبار اصلی';
                if(returnDestination === 'teh') destinationLabel = 'انبار تهران پارس';
                return `در حال ثبت مرجوعی ${typeCount} نوع محصول و مجموعاً ${totalQty} آیتم به ${destinationLabel} با علت «${returnReason}» هستید. آیا مطمئن هستید؟`;
            }

            return '';
        }

        function enforceOutLimit(idx){
            if(opType !== 'out') return;
            const it = items[idx];
            if(!it) return;
            if(it.qty > it.stock){ it.qty = it.stock; }
        }

        function enforceTransferLimit(idx){
            if(opType !== 'transfer') return;
            const it = items[idx];
            if(!it) return;
            const sourceStock = findTransferSourceStockById(it.id);
            if(it.qty > sourceStock){ it.qty = sourceStock; }
        }
        function enforceSaleLimit(idx){
            if(opType !== 'sale' && opType !== 'sale_teh') return;
            const it = items[idx];
            if(!it) return;
            recomputeSaleSplitForItem(it);
        }

        function openModal(){
            if(!opType) return;
            updateModalSubtitle();

            $overlay.show().attr('aria-hidden','false');
            $modal.css('display','flex').attr('aria-hidden','false');
            $('body').css('overflow','hidden');

            buildAttributeFilters();
            $q.trigger('focus');
            renderPickerResults();
        }

        function closeModal(){
            $overlay.hide().attr('aria-hidden','true');
            $modal.hide().attr('aria-hidden','true');
            $('body').css('overflow','');
            $q.val('');
            $results.empty();
            updateSelectedInfo();
            updateModalSubtitle();
        }

        function updateSelectedInfo(){
            let cnt = 0;
            let sum = 0;
            for (const k in pickerQty){
                const v = +pickerQty[k];
                if (v > 0){ cnt++; sum += v; }
            }
            if (cnt <= 0){
                $info.text('هیچ موردی انتخاب نشده است.');
            } else {
                $info.text(`تعداد محصولات انتخاب‌شده: ${cnt} | جمع تعداد: ${sum}`);
            }
        }

        function buildAttributeFilters(){
            $filters.empty();
            for (const k in activeFilters){
                if (Object.prototype.hasOwnProperty.call(activeFilters, k)) delete activeFilters[k];
            }

            if (!Array.isArray(pickerAttrDefs) || pickerAttrDefs.length === 0){
                return;
            }

            const optionsByTax = Object.create(null);

            for (let i=0; i<allProducts.length; i++){
                const p = allProducts[i];
                const attrs = p && p.attrs ? p.attrs : null;
                if (!attrs || typeof attrs !== 'object') continue;

                for (let d=0; d<pickerAttrDefs.length; d++){
                    const def = pickerAttrDefs[d];
                    if (!def || !def.tax) continue;
                    const tax = String(def.tax);

                    const vals = attrs[tax];
                    if (!Array.isArray(vals) || vals.length === 0) continue;

                    if (!optionsByTax[tax]) optionsByTax[tax] = Object.create(null);
                    for (let v=0; v<vals.length; v++){
                        const rawVal = String(vals[v] || '').trim();
                        if (!rawVal) continue;
                        const key = norm(rawVal);
                        if (!key) continue;
                        optionsByTax[tax][key] = rawVal;
                    }
                }
            }

            for (let d=0; d<pickerAttrDefs.length; d++){
                const def = pickerAttrDefs[d];
                if (!def || !def.tax) continue;

                const tax = String(def.tax);
                const label = String(def.label || tax);

                const bag = optionsByTax[tax];
                if (!bag || typeof bag !== 'object') continue;

                const keys = Object.keys(bag);
                if (keys.length === 0) continue;
                keys.sort((a,b) => a.localeCompare(b, 'fa'));

                const selectId = 'wc-suf-filter-' + tax.replace(/[^a-z0-9_]/gi,'_');

                const opts = [];
                opts.push(`<option value="">همه</option>`);
                for (let i=0; i<keys.length; i++){
                    const k = keys[i];
                    const display = bag[k];
                    opts.push(`<option value="${escapeHtml(k)}">${escapeHtml(display)}</option>`);
                }

                const html = `
                  <div class="wc-suf-filter">
                    <label for="${escapeHtml(selectId)}">${escapeHtml(label)}:</label>
                    <select id="${escapeHtml(selectId)}" data-tax="${escapeHtml(tax)}">
                      ${opts.join('')}
                    </select>
                  </div>
                `;
                $filters.append(html);
            }
        }

        function productMatchesFilters(p){
            for (const tax in activeFilters){
                if (!Object.prototype.hasOwnProperty.call(activeFilters, tax)) continue;
                const sel = String(activeFilters[tax] || '');
                if (!sel) continue;

                const attrs = p && p.attrs ? p.attrs : null;
                if (!attrs || typeof attrs !== 'object') return false;

                const vals = attrs[tax];
                if (!Array.isArray(vals) || vals.length === 0) return false;

                let ok = false;
                for (let i=0; i<vals.length; i++){
                    if (norm(vals[i]) === sel){
                        ok = true;
                        break;
                    }
                }
                if (!ok) return false;
            }
            return true;
        }

        function renderPickerResults(){
            const q = norm($q.val());
            const tokens = q.split(/\s+/).map(t => t.trim()).filter(Boolean);
            const matched = [];

            if (tokens.length > 0){
                for (let i=0; i<allProducts.length; i++){
                    const p = allProducts[i];
                    const hay = String(p.search || p.label || '').toLowerCase();
                    const ok = tokens.every(t => hay.includes(t));
                    if (!ok) continue;
                    if (!productMatchesFilters(p)) continue;
                    matched.push(p);
                }
            } else {
                // بدون جستجو: اگر فیلتر انتخاب شده باشد، اجازه بده نتایج بر اساس فیلتر دیده شوند
                const hasAnyFilter = Object.keys(activeFilters).some(k => (activeFilters[k] || '') !== '');
                if (hasAnyFilter){
                    for (let i=0; i<allProducts.length; i++){
                        const p = allProducts[i];
                        if (!productMatchesFilters(p)) continue;
                        matched.push(p);
                    }
                }
            }

            const showList = matched;
            if (showList.length === 0){
                const hasAnyFilter = Object.keys(activeFilters).some(k => (activeFilters[k] || '') !== '');
                const msg = (q.length || hasAnyFilter) ? 'موردی یافت نشد.' : 'برای نمایش نتایج، عبارت جستجو را وارد کنید یا یک فیلتر انتخاب کنید.';
                $results.html(`<div style="padding:14px" class="suf-muted">${escapeHtml(msg)}</div>`);
                updateSelectedInfo();
                return;
            }

            const frag = [];
            for (let i=0; i<showList.length; i++){
                const p = showList[i];
                const pid = String(p.id);
                const stock = (+p.prod_stock || 0);
                const cur = (pickerQty[pid] != null) ? +pickerQty[pid] : 0;
                const metaLine = getPickerMetaLine(p);

                frag.push(`
                    <div class="wc-suf-picker-row" data-pid="${escapeHtml(pid)}">
                      <div>
                        <div class="wc-suf-picker-name">${escapeHtml(p.label || ('#'+pid))}</div>
                        <div class="wc-suf-picker-meta">${escapeHtml(metaLine)}</div>
                      </div>
                      <div class="wc-suf-picker-qty">
                        <button type="button" class="picker-dec" data-pid="${escapeHtml(pid)}">➖</button>
                        <input type="number" min="0" class="picker-qty" data-pid="${escapeHtml(pid)}" value="${escapeHtml(cur)}" />
                        <button type="button" class="picker-inc" data-pid="${escapeHtml(pid)}">➕</button>
                      </div>
                    </div>
                `);
            }

            $results.html(frag.join(''));
            updateSelectedInfo();
        }

        function capQtyForOut(pid, qty, showAlert){
            if(opType !== 'out') return qty;
            const stock = findProductionStockById(pid);
            if (qty > stock){
                if (showAlert){
                    const name = findLabelById(pid) || ('#'+pid);
                    showGlobalToast(`برای "${name}" حداکثر قابل انتخاب ${stock} عدد است (موجودی انبار تولید).`, false);
                }
                return stock;
            }
            return qty;
        }

        function capQtyForTransfer(pid, qty, showAlert){
            if(opType !== 'transfer') return qty;
            const stock = findTransferSourceStockById(pid);
            if (qty > stock){
                if (showAlert){
                    const name = findLabelById(pid) || ('#'+pid);
                    showGlobalToast(`برای "${name}" حداکثر قابل انتخاب ${stock} عدد است (موجودی انبار مبدا).`, false);
                }
                return stock;
            }
            return qty;
        }
        function capQtyForSale(pid, qty, showAlert){
            return qty;
        }
        function lockReturnDestinationIfNeeded(){
            const $destination = $('#return-destination');
            const hasSelection = String($destination.val() || '') !== '';
            $destination.prop('disabled', hasSelection);
        }

        refreshPickerOpenButton();
        updateModalSubtitle();

        $('#btn-open-picker').on('click', function(){
            if(!canOpenPicker()) return;
            openModal();
        });

        $('#wc-suf-modal-close').on('click', closeModal);
        $overlay.on('click', closeModal);

        $(document).on('keydown', function(e){
            if ($modal.is(':visible') && e.key === 'Escape'){
                e.preventDefault();
                closeModal();
            }
        });

        $q.on('input', function(){
            renderPickerResults();
        });

        $filters.on('change', 'select[data-tax]', function(){
            const tax = String($(this).data('tax') || '');
            const val = String($(this).val() || '');
            if (!tax) return;
            activeFilters[tax] = val; // normalized already
            renderPickerResults();
        });

        $('#wc-suf-picker-clear').on('click', function(){
            $q.val('');
            $filters.find('select[data-tax]').val('');
            for (const k in activeFilters){
                if (Object.prototype.hasOwnProperty.call(activeFilters, k)) activeFilters[k] = '';
            }
            $results.html('<div style="padding:14px" class="suf-muted">برای نمایش نتایج، عبارت جستجو را وارد کنید یا یک فیلتر انتخاب کنید.</div>');
            updateSelectedInfo();
            $q.trigger('focus');
        });

        $results.on('click', '.picker-inc', function(){
            const pid = String($(this).data('pid'));
            let current = (+pickerQty[pid] || 0) + 1;
            current = capQtyForOut(pid, current, true);
            current = capQtyForTransfer(pid, current, true);
            current = capQtyForSale(pid, current, true);
            pickerQty[pid] = current;
            $results.find(`.picker-qty[data-pid="${pid}"]`).val(current);
            updateSelectedInfo();
        });

        $results.on('click', '.picker-dec', function(){
            const pid = String($(this).data('pid'));
            const current = Math.max(0, (+pickerQty[pid] || 0) - 1);
            pickerQty[pid] = current;
            $results.find(`.picker-qty[data-pid="${pid}"]`).val(current);
            updateSelectedInfo();
        });

        $results.on('change', '.picker-qty', function(){
            const pid = String($(this).data('pid'));
            let v = +$(this).val();
            if (!Number.isFinite(v)) v = 0;
            v = Math.max(0, Math.floor(v));
            v = capQtyForOut(pid, v, true);
            v = capQtyForTransfer(pid, v, true);
            v = capQtyForSale(pid, v, true);
            pickerQty[pid] = v;
            $(this).val(v);
            updateSelectedInfo();
        });

        $('#wc-suf-picker-add').on('click', function(){
            if(!opType) return;
            const selectedIds = Object.keys(pickerQty).filter(function(pid){
                return (+pickerQty[pid] || 0) > 0;
            });
            if (selectedIds.length === 0){
                showGlobalToast('هیچ محصولی با تعداد بالاتر از صفر انتخاب نشده است.', false);
                return;
            }

            const $addBtn = $(this);
            const originalText = $addBtn.text();
            $addBtn.prop('disabled', true).css({opacity: 0.7, cursor: 'wait'}).text('در حال بررسی موجودی...');

            const addItemsToTable = function(){
                let addedAny = false;

                for (const pid in pickerQty){
                    let qty = +pickerQty[pid];
                    if (!qty || qty <= 0) continue;

                    const name  = findLabelById(pid) || '(بدون نام)';
                    const stock = findProductionStockById(pid);

                    if (opType === 'out' && qty > stock){
                        showGlobalToast(`مقدار انتخابی برای «${name}» بیشتر از موجودی انبار تولید است.`, false);
                        return false;
                    }
                    if (opType === 'transfer'){
                        const sourceStock = findTransferSourceStockById(pid);
                        if (qty > sourceStock){
                            showGlobalToast(`مقدار انتخابی برای «${name}» بیشتر از موجودی انبار مبدا است.`, false);
                            return false;
                        }
                    }
                    const existingIdx = items.findIndex(x => String(x.id) === String(pid));
                    if (existingIdx >= 0){
                        items[existingIdx].qty = (items[existingIdx].qty || 0) + qty;
                        items[existingIdx].stock = stock;
                        if (!Number.isFinite(+items[existingIdx].sale_base_stock)) {
                            items[existingIdx].sale_base_stock = findMainStockById(pid);
                        }
                        enforceOutLimit(existingIdx);
                        enforceTransferLimit(existingIdx);
                        enforceSaleLimit(existingIdx);
                    } else {
                        items.push({
                            id: pid,
                            name,
                            qty,
                            stock,
                            sale_base_stock: findMainStockById(pid),
                            sale_allocated_qty: 0,
                            sale_pending_qty: 0
                        });
                        enforceOutLimit(items.length - 1);
                        enforceTransferLimit(items.length - 1);
                        enforceSaleLimit(items.length - 1);
                    }

                    addedAny = true;
                }

                if (!addedAny){
                    showGlobalToast('هیچ محصولی با تعداد بالاتر از صفر انتخاب نشده است.', false);
                    return false;
                }

                for (const pid in pickerQty){
                    if (Object.prototype.hasOwnProperty.call(pickerQty, pid)) pickerQty[pid] = 0;
                }

                renderTable();
                syncSaleHoldOrder(true);
                scheduleSaleStocksRefresh(selectedIds, 1000);
                closeModal();
                return true;
            };

            const afterDone = function(){
                $addBtn.prop('disabled', false).css({opacity: 1, cursor: 'pointer'}).text(originalText);
            };

            if (opType === 'sale' || opType === 'sale_teh'){
                refreshStocksBeforeResult(selectedIds).done(function(refreshRes){
                    if(refreshRes && refreshRes.success && refreshRes.data && refreshRes.data.stocks){
                        updateProductStocksInMemory(refreshRes.data.stocks);
                        renderPickerResults();
                        renderTable();
                    }
                    addItemsToTable();
                }).fail(function(){
                    showGlobalToast('به‌روزرسانی موجودی انجام نشد. لطفاً دوباره تلاش کنید.', false);
                }).always(afterDone);
                return;
            }

            addItemsToTable();
            afterDone();
        });

        $('input[name="op-type"]').on('change', function(){
            if(!activateOpType($(this).val())){
                $(this).prop('checked', false);
                showGlobalToast('شما به نوع عملیات انتخابی دسترسی ندارید.', false);
                return false;
            }
        });

        $('input[name="out-destination"]').on('change', function(){
            if(opType !== 'out') return;
            outDestination = $(this).val() || null;
            refreshPickerOpenButton();
            refreshActionButtons();
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-source').on('change', function(){
            if(opType !== 'transfer') return;
            transferSource = $(this).val() || null;
            syncTransferDestinationOptions();
            refreshPickerOpenButton();
            refreshActionButtons();
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-destination').on('change', function(){
            if(opType !== 'transfer') return;
            transferDestination = $(this).val() || null;
            refreshPickerOpenButton();
            refreshActionButtons();
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-source').on('change', function(){
            if(opType !== 'transfer') return;
            transferSource = $(this).val() || null;
            syncTransferDestinationOptions();
            refreshPickerOpenButton();
            refreshActionButtons();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#transfer-destination').on('change', function(){
            if(opType !== 'transfer') return;
            transferDestination = $(this).val() || null;
            refreshPickerOpenButton();
            refreshActionButtons();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        $('#return-destination').on('change', function(){
            if(opType !== 'return') return;
            returnDestination = $(this).val() || null;
            if(isMarjooOnly && returnDestination !== 'teh'){
                returnDestination = 'teh';
                $(this).val('teh');
            }
            lockReturnDestinationIfNeeded();
            refreshPickerOpenButton();
            refreshActionButtons();
            updateModalSubtitle();
            renderTable();
            if ($modal.is(':visible')) {
                renderPickerResults();
            }
        });

        if(isEditModeRequested){
            // مقداردهی حالت ویرایش به‌صورت async انجام می‌شود.
        } else if(isMarjooOnly){
            $('input[name="op-type"][value="return"]').prop('checked', true).trigger('change');
            $('#return-destination').val('teh').trigger('change');
        } else if (allowedOps.length === 1) {
            $('input[name="op-type"][value="' + allowedOps[0] + '"]').prop('checked', true).trigger('change');
        }

        syncOpTypeButtonsState();

        if(!canUseMainOnsiteSaleMethod || isTehSaleUserRole){
            $('#sale-method option[value="main_onsite"]').remove();
        }
        if(isSaleUserRole){
            saleMethod = 'post';
            $('#sale-method').val('post');
            applySaleAddressRule();
        }
        if(isEditModeRequested){
            loadSaleOrderForEdit();
        } else {
            setModeUi();
        }

        $('#return-reason').on('change', function(){
            if(opType !== 'return') return;
            returnReason = $(this).val() || '';
            refreshPickerOpenButton();
            refreshActionButtons();
        });
        $('#sale-customer-name').on('input', function(){
            saleCustomerName = $(this).val() || '';
            refreshPickerOpenButton();
            refreshActionButtons();
            syncSaleHoldOrder(false);
        });
        let saleCustomerLookupTimer = null;
        let lastLookupMobile = '';
        $('#sale-customer-mobile').on('input', function(){
            const normalized = normalizeDigits($(this).val() || '');
            if ( normalized !== ($(this).val() || '') ) {
                $(this).val(normalized);
            }
            saleCustomerMobile = normalized;
            const mobile = normalizeMobileInput(saleCustomerMobile);
            if (saleCustomerLookupTimer) {
                clearTimeout(saleCustomerLookupTimer);
            }
            if (/^0\d{10}$/.test(mobile) && mobile !== lastLookupMobile) {
                saleCustomerLookupTimer = setTimeout(function(){
                    $.post(ajaxurl, {
                        action: 'wc_suf_lookup_sale_customer',
                        mobile: mobile,
                        _wpnonce: '<?php echo wp_create_nonce('wc_suf_lookup_sale_customer'); ?>'
                    }).done(function(res){
                        if (!res || !res.success || !res.data) return;
                        const fullName = String(res.data.full_name || '').trim();
                        if (fullName.length >= 3) {
                            saleCustomerName = fullName;
                            $('#sale-customer-name').val(fullName);
                        }
                    }).always(function(){
                        refreshPickerOpenButton();
                        refreshActionButtons();
                    });
                }, 250);
                lastLookupMobile = mobile;
            }
            refreshPickerOpenButton();
            refreshActionButtons();
            syncSaleHoldOrder(false);
        });
        $('#sale-customer-address').on('input', function(){
            const rule = getSaleAddressRuleByMethod(saleMethod);
            const prefix = String(rule.fixedPrefix || '');
            let nextValue = String($(this).val() || '');
            if(prefix && nextValue.indexOf(prefix) !== 0){
                nextValue = prefix + nextValue.replace(prefix, '').trim();
                $(this).val(nextValue);
            }
            saleCustomerAddress = nextValue;
            refreshPickerOpenButton();
            refreshActionButtons();
            syncSaleHoldOrder(false);
        });
        $('#sale-method').on('change', function(){
            saleMethod = $(this).val() || '';
            applySaleAddressRule();
            refreshPickerOpenButton();
            refreshActionButtons();
            syncSaleHoldOrder(false);
        });

        $('#items-table').on('click','.row-inc', function(){
            const i = +$(this).data('i'); items[i].qty++; enforceOutLimit(i); enforceTransferLimit(i); enforceSaleLimit(i); renderTable();
            syncSaleHoldOrder(true);
            scheduleSaleStocksRefresh([items[i] ? items[i].id : 0], 1000);
        });
        $('#items-table').on('click','.row-dec', function(){
            const i = +$(this).data('i'); items[i].qty = Math.max(1, items[i].qty-1); enforceOutLimit(i); enforceTransferLimit(i); enforceSaleLimit(i); renderTable();
            syncSaleHoldOrder(true);
            scheduleSaleStocksRefresh([items[i] ? items[i].id : 0], 1000);
        });
        $('#items-table').on('change','.row-qty', function(){
            const i = +$(this).data('i'); let v = +$(this).val();
            v = Math.max(1, v||1); items[i].qty = v; enforceOutLimit(i); enforceTransferLimit(i); enforceSaleLimit(i); renderTable();
            syncSaleHoldOrder(true);
            scheduleSaleStocksRefresh([items[i] ? items[i].id : 0], 1000);
        });

        $('#items-table').on('click','.btn-del',function(){
            const i = +$(this).data('i');
            const removed = items[i];
            items.splice(i,1);
            renderTable();
            syncSaleHoldOrder(true);
            if((opType === 'sale' || opType === 'sale_teh') && removed && removed.id){
                scheduleSaleStocksRefresh([removed.id], 250);
            }
        });

        let submitting = false;
        function submitOrder(submitMode, skipConfirm){
            if (submitting) return;
            const mode = (submitMode === 'pending_review') ? 'pending_review' : 'final';
            if (mode === 'pending_review') {
                if (!canSavePending()) return;
            } else if (!canSave()) {
                return;
            }
            if ((opType === 'sale' || opType === 'sale_teh') && !isSaleCustomerDataValid(true)) return;

            const submittedProductIds = items.map(function(it){ return parseInt(it.id, 10); }).filter(function(v){ return Number.isFinite(v) && v > 0; });

            if (!skipConfirm && (opType === 'in' || opType === 'out' || opType === 'transfer' || opType === 'return')){
                askForConfirmation(buildSubmitConfirmMessage(), function(){ submitOrder(mode, true); }, function(){});
                return;
            }

            submitting = true;
            $('#save-result').hide().empty();

            const $btn = (mode === 'pending_review') ? $('#btn-save-pending') : $('#btn-save');
            const originalText = $btn.text();
            $btn.prop('disabled', true).css({opacity: 0.6, cursor: 'not-allowed'}).text('در حال ثبت...');
            const $otherBtn = (mode === 'pending_review') ? $('#btn-save') : $('#btn-save-pending');
            $otherBtn.prop('disabled', true).css({opacity: 0.6, cursor: 'not-allowed'});

            $.post(ajaxurl, {
                action      : 'save_stock_update',
                items       : JSON.stringify(items),
                sale_submit_mode : mode,
                user_code   : userCode,
                out_destination : String(outDestination || ''),
                transfer_source : String(transferSource || ''),
                transfer_destination : String(transferDestination || ''),
                return_destination : String(returnDestination || ''),
                return_reason : String(returnReason || ''),
                sale_customer_name : String(saleCustomerName || ''),
                sale_customer_mobile : String(saleCustomerMobile || ''),
                sale_customer_address : String(saleCustomerAddress || ''),
                sale_method : String(saleMethod || ''),
                sale_hold_order_id : saleHoldOrderId,
                op_type     : opType,
                _wpnonce    : '<?php echo wp_create_nonce('save_stock_update'); ?>'
            }).done(function(res){
                try{
                    if(res && res.success){
                        const msg = (res.data && res.data.message) ? res.data.message : 'ثبت شد.';
                        const csvUrl = (res.data && res.data.csv_url) ? String(res.data.csv_url) : '';
                        const wordUrl = (res.data && res.data.word_url) ? String(res.data.word_url) : '';
                        const refreshIdsRaw = (res.data && Array.isArray(res.data.product_ids)) ? res.data.product_ids : submittedProductIds;

                        const finishSaveUi = function(refreshFailed){
                            const shouldReloadForEditPending = (mode === 'pending_review' && isEditModeActive);
                            items.length = 0;
                            opType = null;
                            outDestination = null;
                            transferSource = null;
                            transferDestination = null;
                            returnDestination = null;
                            returnReason = '';
                            saleCustomerName = '';
                            saleCustomerMobile = '';
                            saleCustomerAddress = '';
                            saleMethod = '';
                            saleHoldOrderId = 0;
                            for (const pid in pickerQty){
                                if (Object.prototype.hasOwnProperty.call(pickerQty, pid)) pickerQty[pid] = 0;
                            }
                            $('input[name="op-type"]').prop('checked', false).prop('disabled', false);
                            $('input[name="out-destination"]').prop('checked', false);
                            $('#transfer-source').val('');
                            $('#transfer-destination').html('<option value="">انتخاب انبار مقصد...</option>');
                            $('#return-destination').val('');
                            $('#return-destination').prop('disabled', false);
                            $('#return-reason').val('');
                            $('#sale-customer-name').val('');
                            $('#sale-customer-mobile').val('');
                            $('#sale-customer-address').val('');
                            $('#sale-method').val(isSaleUserRole ? 'post' : '');
                            saleMethod = $('#sale-method').val() || '';
                            $('#out-destination-wrap').hide();
                            $('#transfer-controls-wrap').hide();
                            $('#return-controls-wrap').hide();
                            $('#sale-customer-wrap').hide();
                            $('#sale-method-wrap').hide();
                            closeModal();
                            refreshPickerOpenButton();
                            renderTable();

                            let html = '<div style="font-weight:700; color:#065f46">'+escapeHtml(msg)+'</div>';
                            if(refreshFailed){
                                html += '<div style="margin-top:8px; color:#b45309; font-weight:700">به‌روزرسانی لحظه‌ای موجودی انجام نشد؛ صفحه را یک‌بار رفرش کنید.</div>';
                            }
                            if(csvUrl){
                                html += '<div style="margin-top:8px"><a href="'+csvUrl+'" target="_blank" rel="noopener" style="color:#1d4ed8; font-weight:700">چاپ لیبل (HTML)</a></div>';
                            }
                            if(wordUrl){
                                html += '<div style="margin-top:8px"><a href="'+wordUrl+'" target="_blank" rel="noopener" style="color:#1d4ed8; font-weight:700">دانلود رسید عملیات (HTML)</a></div>';
                            }
                            $('#save-result').html(html).show();
                            showGlobalToast(msg, true);
                            if(shouldReloadForEditPending){
                                window.location.reload();
                            }
                        };

                        refreshStocksBeforeResult(refreshIdsRaw).done(function(refreshRes){
                            if(refreshRes && refreshRes.success && refreshRes.data && refreshRes.data.stocks){
                                updateProductStocksInMemory(refreshRes.data.stocks);
                            }
                            finishSaveUi(false);
                        }).fail(function(){
                            finishSaveUi(true);
                        }).always(function(){
                            submitting = false;
                            $btn.css({opacity: 1, cursor: 'pointer'}).text(originalText);
                            $otherBtn.css({opacity: 1, cursor: 'pointer'});
                            refreshActionButtons();
                        });
                    }else{
                        showGlobalToast((res && res.data && res.data.message) ? res.data.message : 'ثبت ناموفق.', false);
                        submitting = false;
                        $btn.css({opacity: 1, cursor: 'pointer'}).text(originalText);
                        $otherBtn.css({opacity: 1, cursor: 'pointer'});
                        refreshActionButtons();
                    }
                }catch(e){
                    showGlobalToast('پاسخ نامعتبر از سرور.', false);
                    submitting = false;
                    $btn.css({opacity: 1, cursor: 'pointer'}).text(originalText);
                    $otherBtn.css({opacity: 1, cursor: 'pointer'});
                    refreshActionButtons();
                }
            }).fail(function(){
                showGlobalToast('خطای ارتباطی هنگام ثبت.', false);
                submitting = false;
                $btn.css({opacity: 1, cursor: 'pointer'}).text(originalText);
                $otherBtn.css({opacity: 1, cursor: 'pointer'});
                refreshActionButtons();
            });
        }
        $('#btn-save').on('click', function(){ submitOrder('final'); });
        $('#btn-save-pending').on('click', function(){ submitOrder('pending_review'); });
    });
    </script>
    <?php
    return ob_get_clean();
});

add_shortcode('wc_suf_my_sale_orders', function(){
    if ( ! function_exists('wc_get_orders') ) {
        return '<div dir="rtl" style="color:#b91c1c">ووکامرس فعال نیست.</div>';
    }
    if ( ! is_user_logged_in() ) {
        return '<div dir="rtl" style="color:#b91c1c">برای مشاهده سفارش‌ها ابتدا وارد شوید.</div>';
    }

    $user_id = get_current_user_id();
    $can_edit_any_order = current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
    $orders_query_args = [
        'type'       => 'shop_order',
        'limit'      => 200,
        'orderby'    => 'date',
        'order'      => 'DESC',
    ];
    if ( ! $can_edit_any_order ) {
        $orders_query_args['meta_key'] = '_wc_suf_seller_id';
        $orders_query_args['meta_value'] = $user_id;
    }
    $orders = wc_get_orders( $orders_query_args );

    $stock_form_url = '';
    $stock_form_pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        's'              => '[stock_update_form',
    ]);
    if ( ! empty( $stock_form_pages ) ) {
        $stock_form_url = get_permalink( (int) $stock_form_pages[0] );
    }

    ob_start();
    echo '<div dir="rtl" style="display:grid; gap:12px">';
    echo '<style>
    .wc-suf-order-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.52);z-index:99998;padding:16px;align-items:center;justify-content:center}
    .wc-suf-order-modal.is-open{display:flex}
    .wc-suf-order-modal-card{width:min(940px,96vw);max-height:88vh;overflow:hidden;background:#fff;border-radius:14px;box-shadow:0 20px 40px rgba(15,23,42,.28);display:flex;flex-direction:column}
    .wc-suf-order-modal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border-bottom:1px solid #e5e7eb;background:#f8fafc}
    .wc-suf-order-modal-title{font-weight:800;color:#0f172a}
    .wc-suf-order-modal-close{border:1px solid #ef4444;background:#ef4444;color:#fff;border-radius:10px;padding:6px 10px;cursor:pointer;font-weight:800}
    .wc-suf-order-modal-body{padding:14px;overflow:auto;display:grid;gap:12px}
    .wc-suf-order-group{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
    .wc-suf-order-group h4{margin:0;padding:10px 12px;background:#f8fafc;border-bottom:1px solid #e5e7eb}
    .wc-suf-order-group table{width:100%;border-collapse:collapse;font-size:13px}
    .wc-suf-order-group th,.wc-suf-order-group td{padding:8px;border:1px solid #e5e7eb;text-align:right}
    .wc-suf-order-customer-meta{font-size:11px;color:#64748b;display:grid;gap:4px;padding:8px 10px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc}
    </style>';
    $pending_report_url = wp_nonce_url(
        admin_url( 'admin-ajax.php?action=wc_suf_pending_products_report' ),
        'wc_suf_pending_products_report'
    );
    echo '<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap">';
    echo '<h3 style="margin:0">' . ( $can_edit_any_order ? 'همه سفارش‌ها' : 'سفارش‌های ثبت‌شده توسط من' ) . '</h3>';
    echo '<a href="' . esc_url( $pending_report_url ) . '" target="_blank" rel="noopener" style="display:inline-flex; align-items:center; padding:8px 12px; border:1px solid #1d4ed8; background:#1d4ed8; color:#fff; border-radius:8px; text-decoration:none; font-weight:700">گزارش کل محصولات در انتظار</a>';
    echo '</div>';
    if ( empty($orders) ) {
        echo '<div style="padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; background:#f9fafb">هنوز سفارشی ثبت نکرده‌اید.</div>';
        echo '</div>';
        return ob_get_clean();
    }
    echo '<table style="width:100%; border-collapse:collapse; border:1px solid #e5e7eb; font-size:13px">';
    echo '<thead><tr style="background:#f3f4f6">';
    echo '<th style="padding:8px; border:1px solid #e5e7eb">شماره سفارش</th>';
    echo '<th style="padding:8px; border:1px solid #e5e7eb">تاریخ</th>';
    echo '<th style="padding:8px; border:1px solid #e5e7eb">وضعیت</th>';
    echo '<th style="padding:8px; border:1px solid #e5e7eb">مشتری</th>';
    echo '<th style="padding:8px; border:1px solid #e5e7eb">نحوه فروش</th>';
    echo '<th style="padding:8px; border:1px solid #e5e7eb">تعداد اقلام</th>';
    echo '<th style="padding:8px; border:1px solid #e5e7eb">در انتظار</th>';
    echo '<th style="padding:8px; border:1px solid #e5e7eb">ویرایش</th>';
    echo '<th style="padding:8px; border:1px solid #e5e7eb">تکمیل سفارش</th>';
    echo '</tr></thead><tbody>';

    foreach ( $orders as $order ) {
        $order_id = (int) $order->get_id();
        $seller_id = absint( $order->get_meta('_wc_suf_seller_id', true ) );
        $created_via = (string) $order->get_created_via();
        $status = (string) $order->get_status();
        $is_manual_sale = in_array( $created_via, [ 'wc_suf_manual_sale', 'wc_suf_manual_sale_hold' ], true );
        $is_owner = ( $seller_id > 0 && $seller_id === $user_id );
        $is_completed = in_array( $status, [ 'completed' ], true );
        $is_cancelled = in_array( $status, [ 'cancelled', 'trash' ], true );
        $can_edit_order = (
            ! empty( $stock_form_url )
            && ! $is_cancelled
            && (
                $can_edit_any_order
                || ( $is_owner && $is_manual_sale && ! $is_completed )
            )
        );
        $edit_url = $can_edit_order ? add_query_arg([
            'mode'     => 'edit',
            'order_id' => $order_id,
        ], $stock_form_url ) : '';
        $edit_block_reason = '';
        if ( empty( $stock_form_url ) ) {
            $edit_block_reason = 'صفحه فرم یافت نشد';
        } elseif ( ! $can_edit_any_order && ! $is_owner ) {
            $edit_block_reason = 'مالک سفارش نیستید';
        } elseif ( ! $can_edit_any_order && ! $is_manual_sale ) {
            $edit_block_reason = 'فقط فروش دستی قابل ویرایش است';
        } elseif ( ! $can_edit_any_order && $is_completed ) {
            $edit_block_reason = 'سفارش تکمیل‌شده قابل ویرایش نیست';
        } elseif ( $is_cancelled ) {
            $edit_block_reason = 'سفارش لغو شده است';
        }
        $pending_meta = (string) $order->get_meta('_wc_suf_pending_breakdown', true );
        $pending_rows = json_decode( $pending_meta, true );
        $pending_qty = 0;
        if ( is_array($pending_rows) ) {
            foreach ( $pending_rows as $row ) {
                $pending_qty += max( 0, (int) ( $row['pending_qty'] ?? 0 ) );
            }
        }
        $item_count = 0;
        foreach ( $order->get_items('line_item') as $item ) {
            $item_count += max( 0, (int) $item->get_quantity() );
        }

        echo '<tr>';
        echo '<td style="padding:8px; border:1px solid #e5e7eb">';
        $registered_rows = [];
        foreach ( $order->get_items('line_item') as $item ) {
            $item_product_id = (int) $item->get_variation_id();
            if ( $item_product_id <= 0 ) {
                $item_product_id = (int) $item->get_product_id();
            }
            $item_product = $item_product_id > 0 ? wc_get_product( $item_product_id ) : null;
            $item_product_code = '';
            if ( $item_product && is_a( $item_product, 'WC_Product' ) ) {
                $item_product_code = (string) $item_product->get_sku();
            }
            if ( $item_product_code === '' && $item_product_id > 0 ) {
                $item_product_code = (string) $item_product_id;
            }
            $registered_rows[] = [
                'name' => (string) $item->get_name(),
                'product_code' => $item_product_code,
                'qty' => max( 0, (float) $item->get_quantity() ),
            ];
        }
        $detail_payload = [
            'order_number' => (string) $order->get_order_number(),
            'customer_name' => (string) ( $order->get_meta('_wc_suf_sale_customer_name', true ) ?: $order->get_formatted_billing_full_name() ),
            'customer_mobile' => (string) ( $order->get_meta('_wc_suf_sale_customer_mobile', true ) ?: $order->get_billing_phone() ),
            'customer_address' => (string) ( $order->get_meta('_wc_suf_sale_customer_address', true ) ?: $order->get_billing_address_1() ),
            'registered' => $registered_rows,
            'pending' => is_array($pending_rows) ? array_values($pending_rows) : [],
        ];
        echo '<button type="button" class="wc-suf-order-detail-btn" data-order-detail=\''.esc_attr( wp_json_encode( $detail_payload, JSON_UNESCAPED_UNICODE ) ).'\' style="border:none; background:none; color:#1d4ed8; font-weight:700; text-decoration:none; cursor:pointer; padding:0">#'.esc_html( $order->get_order_number() ).'</button>';
        echo '</td>';
        echo '<td style="padding:8px; border:1px solid #e5e7eb">'.esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n('Y/m/d H:i') : '-' ).'</td>';
        echo '<td style="padding:8px; border:1px solid #e5e7eb">'.esc_html( wc_get_order_status_name( $order->get_status() ) ).'</td>';
        echo '<td style="padding:8px; border:1px solid #e5e7eb">'.esc_html( $order->get_meta('_wc_suf_sale_customer_name', true ) ?: $order->get_formatted_billing_full_name() ).'</td>';
        echo '<td style="padding:8px; border:1px solid #e5e7eb">'.esc_html( $order->get_meta('_wc_suf_sale_method_label', true ) ?: '-' ).'</td>';
        echo '<td style="padding:8px; border:1px solid #e5e7eb; text-align:center">'.esc_html( $item_count ).'</td>';
        echo '<td style="padding:8px; border:1px solid #e5e7eb; text-align:center; color:'.( $pending_qty > 0 ? '#b45309' : '#065f46' ).'; font-weight:700">'.esc_html( $pending_qty ).'</td>';
        echo '<td style="padding:8px; border:1px solid #e5e7eb; text-align:center">';
        if ( $can_edit_order ) {
            echo '<a href="'.esc_url( $edit_url ).'" title="ویرایش سفارش" aria-label="ویرایش سفارش #'.esc_attr( $order->get_order_number() ).'" style="display:inline-flex; align-items:center; gap:4px; padding:6px 10px; border:1px solid #1d4ed8; color:#1d4ed8; border-radius:8px; text-decoration:none; font-weight:700">';
            echo '<span aria-hidden="true">✏️</span><span>ویرایش</span>';
            echo '</a>';
        } else {
            echo '<span style="color:#9ca3af; font-weight:700">غیرفعال</span>';
            if ( $edit_block_reason !== '' ) {
                echo '<div style="margin-top:4px; color:#6b7280; font-size:11px">'.esc_html( $edit_block_reason ).'</div>';
            }
        }
        echo '</td>';
        echo '<td style="padding:8px; border:1px solid #e5e7eb; text-align:center">';
        if ( $pending_qty > 0 && $order->has_status('pendingreview') ) {
            echo '<button type="button" class="wc-suf-complete-order-btn" data-order-id="'.esc_attr( $order->get_id() ).'" style="padding:8px 10px; border:1px solid #2563eb; background:#2563eb; color:#fff; border-radius:8px; cursor:pointer">تکمیل سفارش</button>';
        } else {
            echo '<span style="color:#6b7280">—</span>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<div id="wc-suf-complete-order-toast" style="display:none; position:fixed; left:50%; bottom:24px; transform:translateX(-50%); z-index:99999; min-width:260px; max-width:min(92vw, 540px); padding:12px 14px; border-radius:12px; border:1px solid transparent; box-shadow:0 10px 24px rgba(15,23,42,.18); font-weight:700">';
    echo '<div style="display:flex; align-items:center; gap:10px">';
    echo '<div id="wc-suf-complete-order-toast-text" style="flex:1; text-align:right"></div>';
    echo '<button type="button" id="wc-suf-complete-order-toast-close" aria-label="بستن پیام" style="border:1px solid currentColor; background:transparent; color:inherit; border-radius:8px; cursor:pointer; font-weight:800; padding:2px 8px; line-height:1.4">✕</button>';
    echo '</div></div>';
    echo '<div id="wc-suf-order-detail-modal" class="wc-suf-order-modal" aria-hidden="true">';
    echo '<div class="wc-suf-order-modal-card">';
    echo '<div class="wc-suf-order-modal-head"><div id="wc-suf-order-detail-title" class="wc-suf-order-modal-title">جزئیات سفارش</div><button type="button" id="wc-suf-order-detail-close" class="wc-suf-order-modal-close">بستن</button></div>';
    echo '<div id="wc-suf-order-detail-body" class="wc-suf-order-modal-body"></div>';
    echo '</div></div>';
    echo '</div>';
    ?>
    <script>
    jQuery(function($){
        const ajaxurl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
        const nonce = "<?php echo esc_js( wp_create_nonce('wc_suf_complete_pending_sale') ); ?>";
        let completeOrderToastTimer = null;
        function showCompleteOrderToast(message, isSuccess){
            const bg = isSuccess ? '#ecfdf5' : '#fef2f2';
            const color = isSuccess ? '#065f46' : '#b91c1c';
            const borderColor = isSuccess ? '#10b981' : '#fca5a5';
            if(completeOrderToastTimer){
                clearTimeout(completeOrderToastTimer);
                completeOrderToastTimer = null;
            }
            $('#wc-suf-complete-order-toast-text').text(message);
            $('#wc-suf-complete-order-toast')
                .stop(true, true)
                .css({background:bg, color:color, borderColor:borderColor})
                .fadeIn(160);
            completeOrderToastTimer = setTimeout(function(){
                $('#wc-suf-complete-order-toast').fadeOut(260);
                completeOrderToastTimer = null;
            }, 5000);
        }
        $(document).on('click', '#wc-suf-complete-order-toast-close', function(){
            if(completeOrderToastTimer){
                clearTimeout(completeOrderToastTimer);
                completeOrderToastTimer = null;
            }
            $('#wc-suf-complete-order-toast').stop(true, true).fadeOut(120);
        });
        function escHtml(input){
            return String(input || '').replace(/[&<>"']/g, function(ch){
                return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch] || ch);
            });
        }
        function renderOrderGroup(title, rows, qtyKey){
            if(!Array.isArray(rows) || !rows.length){
                return '<div class="wc-suf-order-group"><h4>'+escHtml(title)+'</h4><div style="padding:10px 12px;color:#64748b">موردی ثبت نشده است.</div></div>';
            }
            let html = '<div class="wc-suf-order-group"><h4>'+escHtml(title)+'</h4><table><thead><tr><th>محصول</th><th style="width:120px">تعداد</th></tr></thead><tbody>';
            rows.forEach(function(row){
                const qty = parseFloat(row && row[qtyKey] != null ? row[qtyKey] : row && row.qty != null ? row.qty : 0) || 0;
                const name = row && (row.name || row.product_name || row.title) ? (row.name || row.product_name || row.title) : '—';
                const code = row && (row.product_code || row.sku || row.product_id || row.id) ? (row.product_code || row.sku || row.product_id || row.id) : '';
                const label = code !== '' ? (String(name) + ' (' + String(code) + ')') : String(name);
                html += '<tr><td>'+escHtml(label)+'</td><td style="text-align:center">'+escHtml(qty)+'</td></tr>';
            });
            html += '</tbody></table></div>';
            return html;
        }
        function renderCustomerMeta(payload){
            const customerName = payload && payload.customer_name ? payload.customer_name : '—';
            const customerMobile = payload && payload.customer_mobile ? payload.customer_mobile : '—';
            const customerAddress = payload && payload.customer_address ? payload.customer_address : '—';
            return '<div class="wc-suf-order-customer-meta">'
                + '<div><strong>نام مشتری:</strong> ' + escHtml(customerName) + '</div>'
                + '<div><strong>شماره همراه:</strong> ' + escHtml(customerMobile) + '</div>'
                + '<div><strong>آدرس:</strong> ' + escHtml(customerAddress) + '</div>'
                + '</div>';
        }
        $(document).on('click', '.wc-suf-order-detail-btn', function(){
            let payload = null;
            try{ payload = JSON.parse(String($(this).attr('data-order-detail') || '{}')); }catch(e){ payload = null; }
            if(!payload){ return; }
            $('#wc-suf-order-detail-title').text('جزئیات سفارش #' + String(payload.order_number || ''));
            const bodyHtml = renderCustomerMeta(payload)
                + renderOrderGroup('محصولات ثبت‌شده', payload.registered || [], 'qty')
                + renderOrderGroup('محصولات در انتظار', payload.pending || [], 'pending_qty');
            $('#wc-suf-order-detail-body').html(bodyHtml);
            $('#wc-suf-order-detail-modal').addClass('is-open').attr('aria-hidden', 'false');
        });
        $(document).on('click', '#wc-suf-order-detail-close', function(){
            $('#wc-suf-order-detail-modal').removeClass('is-open').attr('aria-hidden', 'true');
        });
        $(document).on('click', '#wc-suf-order-detail-modal', function(e){
            if(e.target === this){
                $('#wc-suf-order-detail-modal').removeClass('is-open').attr('aria-hidden', 'true');
            }
        });

        $(document).on('click', '.wc-suf-complete-order-btn', function(){
            const $btn = $(this);
            const orderId = parseInt($btn.data('order-id'), 10) || 0;
            if(orderId <= 0) return;
            $btn.prop('disabled', true).css({opacity:0.7, cursor:'not-allowed'}).text('در حال بررسی...');
            $.post(ajaxurl, {
                action: 'wc_suf_complete_pending_sale',
                order_id: orderId,
                _wpnonce: nonce
            }).done(function(res){
                const ok = !!(res && res.success);
                const msg = (res && res.data && res.data.message) ? res.data.message : (ok ? 'انجام شد.' : 'ناموفق بود.');
                showCompleteOrderToast(msg, ok);
                if(ok){
                    setTimeout(function(){ window.location.reload(); }, 600);
                }
            }).fail(function(){
                showCompleteOrderToast('خطای ارتباطی در تکمیل سفارش.', false);
            }).always(function(){
                $btn.prop('disabled', false).css({opacity:1, cursor:'pointer'}).text('تکمیل سفارش');
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
});

add_action('init', function(){
    if ( ! function_exists('wp_insert_post') ) return;
    if ( get_option('wc_suf_my_sale_orders_page_id') ) return;
    $existing = get_page_by_path('my-sale-orders', OBJECT, 'page');
    if ( $existing && ! empty($existing->ID) ) {
        update_option('wc_suf_my_sale_orders_page_id', (int) $existing->ID, false);
        return;
    }
    $page_id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'سفارش‌های من',
        'post_name' => 'my-sale-orders',
        'post_content' => '[wc_suf_my_sale_orders]',
    ]);
    if ( ! is_wp_error($page_id) && (int) $page_id > 0 ) {
        update_option('wc_suf_my_sale_orders_page_id', (int) $page_id, false);
    }
}, 40);
