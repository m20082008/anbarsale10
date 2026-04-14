<?php
function wc_suf_generate_batch_label_html( $batch_code, $rows ) {
    if ( empty( $rows ) || ! is_array( $rows ) ) {
        return new WP_Error( 'label_empty', 'داده‌ای برای ساخت صفحه چاپ لیبل وجود ندارد.' );
    }

    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'label_upload_dir', 'مسیر آپلود در دسترس نیست: ' . $upload['error'] );
    }

    $dir = trailingslashit( $upload['basedir'] ) . 'wc-suf-exports';
    if ( ! wp_mkdir_p( $dir ) ) {
        return new WP_Error( 'label_mkdir_failed', 'ساخت پوشه فایل‌های چاپ لیبل ناموفق بود.' );
    }

    $safe_batch = preg_replace( '/[^A-Za-z0-9_\-]/', '_', (string) $batch_code );
    $filename   = sprintf( '%s-%s-labels.html', $safe_batch, wp_generate_password( 6, false, false ) );
    $filepath   = trailingslashit( $dir ) . $filename;
    $fileurl    = trailingslashit( $upload['baseurl'] ) . 'wc-suf-exports/' . $filename;

    $website_name = 'www.doukshop.ir';
    $logo_url     = 'https://douk.sepandpitch.com/wp-content/uploads/2026/03/enlogo.jpg';

    /*
     * اندازه کل ردیف چاپ
     */
    $page_width_mm    = 95.0;
    $page_height_mm   = 27.0;

    /*
     * فاصله‌های موردنظر شما
     */
    $row_margin_x_mm  = 1.0; // فاصله از راست و چپ
    $row_margin_y_mm  =  0.0; // فاصله از بالا و پایین
    $label_gap_mm     = 5.0; // فاصله بین دو لیبل

    /*
     * اندازه ناحیه قابل استفاده
     */
    $usable_row_width  = $page_width_mm - ( 2 * $row_margin_x_mm );
    $usable_row_height = $page_height_mm - ( 2 * $row_margin_y_mm );

    $label_width_mm    = 44.0;
$label_height_mm   = 27.0;

    $label_items = [];
    foreach ( $rows as $r ) {
        $line_count = max( 1, (int) ( $r['qty'] ?? 1 ) );
        for ( $i = 0; $i < $line_count; $i++ ) {
            $label_items[] = [
                'id'    => (string) ( $r['id'] ?? '' ),
                'name'  => (string) ( $r['name'] ?? '' ),
                'price' => (string) ( $r['price'] ?? '' ),
            ];
        }
    }

    $render_one_label = function( $it ) use ( $website_name, $logo_url ) {
        if ( empty( $it ) || ! is_array( $it ) ) {
            return '<article class="wc-suf-label wc-suf-label--empty" aria-hidden="true"></article>';
        }

        $id = preg_replace( '/[^0-9A-Za-z\-\.]/', '', (string) $it['id'] );
        if ( $id === '' ) {
            $id = (string) ( $it['id'] ?? '' );
        }

        $price_text = number_format_i18n( (float) ( $it['price'] ?? 0 ) ) . ' تومان';

        return
            '<article class="wc-suf-label">'
                . '<div class="label-inner">'
                    . '<div class="label-row name-row">'
                        . '<div class="name">' . esc_html( (string) $it['name'] ) . '</div>'
                    . '</div>'
                    . '<div class="label-row middle-row">'
                        . '<div class="barcode-wrap">'
                            . '<svg class="barcode" jsbarcode-format="CODE128" jsbarcode-value="' . esc_attr( $id ) . '" jsbarcode-textmargin="0" jsbarcode-fontoptions="bold"></svg>'
                        . '</div>'
                        . '<div class="price-wrap">'
                            . '<div class="price-line"><strong>قیمت:</strong> <span>' . esc_html( $price_text ) . '</span></div>'
                            . '<div class="code-line"><strong>کد محصول:</strong> <span>' . esc_html( $id ) . '</span></div>'
                        . '</div>'
                    . '</div>'
                    . '<div class="label-row footer-row">'
                        . '<div class="logo-wrap"><img src="' . esc_url( $logo_url ) . '" alt="logo"></div>'
                        . '<div class="site-wrap">' . esc_html( $website_name ) . '</div>'
                    . '</div>'
                . '</div>'
            . '</article>';
    };

    $rows_html = '';
    $chunks    = array_chunk( $label_items, 2 );

    foreach ( $chunks as $pair ) {
        $left_html  = $render_one_label( $pair[0] ?? null );
        $right_html = $render_one_label( $pair[1] ?? null );

        $rows_html .=
            '<section class="print-row">'
                . $left_html
                . $right_html
            . '</section>';
    }

    $html = '<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>چاپ لیبل - ' . esc_html( $batch_code ) . '</title>
<style>
*{
    box-sizing:border-box;
}
html, body{
    margin:0;
    padding:0;
    width:' . esc_attr( $page_width_mm ) . 'mm;
    background:#fff;
    font-family:Tahoma,Arial,sans-serif;
}
@page{
    size:' . esc_attr( $page_width_mm ) . 'mm ' . esc_attr( $page_height_mm ) . 'mm;
    margin:0;
}
body{
    -webkit-print-color-adjust:exact;
    print-color-adjust:exact;
}
.sheet{
    width:' . esc_attr( $page_width_mm ) . 'mm;
    margin:0;
    padding:0;
}
.print-row{
    width:' . esc_attr( $page_width_mm ) . 'mm;
    height:' . esc_attr( $page_height_mm ) . 'mm;
    padding:' . esc_attr( $row_margin_y_mm ) . 'mm ' . esc_attr( $row_margin_x_mm ) . 'mm;
    display:flex;
    flex-direction:row;
    align-items:stretch;
    justify-content:flex-start;
    gap:' . esc_attr( $label_gap_mm ) . 'mm;
    overflow:hidden;
    page-break-after:always;
    break-after:page;
}
.print-row:last-child{
    page-break-after:auto;
    break-after:auto;
}
.wc-suf-label{
    width:' . esc_attr( $label_width_mm ) . 'mm;
    min-width:' . esc_attr( $label_width_mm ) . 'mm;
    max-width:' . esc_attr( $label_width_mm ) . 'mm;
    height:' . esc_attr( $label_height_mm ) . 'mm;
    min-height:' . esc_attr( $label_height_mm ) . 'mm;
    max-height:' . esc_attr( $label_height_mm ) . 'mm;
    overflow:hidden;
    background:#fff;
    flex:0 0 auto;
}
.wc-suf-label--empty{
    visibility:hidden;
}
.label-inner{
    width:100%;
    height:100%;
    border:0.35mm solid #111;
    border-radius:3.2mm;
    overflow:hidden;
    display:flex;
    flex-direction:column;
}
.label-row{
    width:100%;
    min-width:0;
}
.name-row{
    height:8.2mm;
    display:flex;
    align-items:center;
    justify-content:center;
    border-bottom:0.35mm solid #111;
    padding:0.7mm 1.2mm;
}
.name{
    width:100%;
    text-align:center;
    font-size:3.1mm;
    font-weight:700;
    line-height:1.15;
    overflow:hidden;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    word-break:break-word;
}
.middle-row{
    height:11.8mm;
    display:grid;
    grid-template-columns:40% 60%;
    direction:ltr;
    border-bottom:0.35mm solid #111;
}
.barcode-wrap{
    border-right:0.35mm solid #111;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0.5mm 0.5mm 0.4mm;
    overflow:hidden;
}
.barcode{
    width:100%;
    height:100%;
    display:block;
}
.price-wrap{
    direction:rtl;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:flex-end;
    padding:0.8mm 1.0mm 0.6mm 0.8mm;
    font-size:2.7mm;
    line-height:1.35;
    text-align:right;
    overflow:hidden;
}
.price-line,
.code-line{
    width:100%;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.footer-row{
    height:calc(100% - 8.2mm - 11.8mm);
    display:grid;
    grid-template-columns:16mm 1fr;
    align-items:center;
}
.logo-wrap{
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0.2mm 0.4mm 0.8mm 0.2mm;
    overflow:hidden;
}
.logo-wrap img{
    max-width:100%;
    max-height:75%;
    object-fit:contain;
    display:block;
}
.site-wrap{
    display:flex;
    align-items:center;
    justify-content:flex-start;
    padding:0 1.1mm 0.5mm 1.1mm;
    font-size:2.8mm;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    direction:ltr;
}
@media screen{
    body{
        margin:0 auto;
    }
}
@media print{
    html, body, .sheet{
        margin:0 !important;
        padding:0 !important;
        width:' . esc_attr( $page_width_mm ) . 'mm !important;
    }
    .print-row{
        margin:0 !important;
    }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
</head>
<body>
<main class="sheet">' . $rows_html . '</main>
<script>
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".barcode").forEach(function (el) {
        try {
            JsBarcode(el, el.getAttribute("jsbarcode-value") || "", {
                format: "CODE128",
                displayValue: false,
                margin: 0,
                width: 1.0,
                height: 22
            });
        } catch (e) {}
    });
});
</script>
</body>
</html>';

    $bytes = file_put_contents( $filepath, $html );
    if ( false === $bytes ) {
        return new WP_Error( 'label_write_failed', 'ایجاد صفحه چاپ لیبل ناموفق بود.' );
    }

    return [
        'path' => $filepath,
        'url'  => $fileurl,
    ];
}


function wc_suf_generate_batch_word_receipt( $batch_code, $context, $rows ) {
    if ( empty($rows) || ! is_array($rows) ) {
        return new WP_Error( 'word_empty', 'داده‌ای برای ساخت رسید HTML وجود ندارد.' );
    }

    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'word_upload_dir', 'مسیر آپلود در دسترس نیست: ' . $upload['error'] );
    }

    $dir = trailingslashit( $upload['basedir'] ) . 'wc-suf-exports';
    if ( ! wp_mkdir_p( $dir ) ) {
        return new WP_Error( 'word_mkdir_failed', 'ساخت پوشه فایل‌های رسید HTML ناموفق بود.' );
    }

    $safe_batch = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $batch_code );
    $filename   = sprintf( '%s-%s-receipt.html', $safe_batch, wp_generate_password(6, false, false) );
    $filepath   = trailingslashit( $dir ) . $filename;
    $fileurl    = trailingslashit( $upload['baseurl'] ) . 'wc-suf-exports/' . $filename;

    $op_type   = (string) ($context['op_type'] ?? '');
    $purpose   = (string) ($context['purpose'] ?? '');
    $user_disp = (string) ($context['user_display'] ?? '');
    $user_code = (string) ($context['user_code'] ?? '');
    $created   = (string) ($context['created_at'] ?? current_time('mysql'));
    $jalali    = wc_suf_format_jalali_datetime($created);
    $op_label  = wc_suf_op_label($op_type);
    $is_sale   = in_array( $op_type, [ 'sale', 'sale_teh' ], true );

    $sum = 0;
    $rows_html = '';
    foreach ( $rows as $i => $r ) {
        $qty = (float) ($r['qty'] ?? 0);
        $sum += $qty;
        $rows_html .= '<tr>'
            . '<td>' . esc_html( (string)($i+1) ) . '</td>'
            . '<td>' . esc_html( (string)($r['id'] ?? '') ) . '</td>'
            . '<td>' . esc_html( (string)($r['name'] ?? '') ) . '</td>'
            . '<td>' . esc_html( (string)($r['qty'] ?? 0) ) . '</td>'
            . '</tr>';
    }

    $html = '<html><head><meta charset="UTF-8"><style>'
        . 'body{font-family:Tahoma,Arial,sans-serif; direction:rtl; color:#111827; font-size:12pt;}'
        . '.box{border:1px solid #d1d5db; border-radius:10px; padding:14px;}'
        . 'h1{font-size:18pt; margin:0 0 12px 0; color:#1e3a8a;}'
        . 'table{border-collapse:collapse; width:100%; margin-top:12px;}'
        . 'th,td{border:1px solid #9ca3af; padding:6px; text-align:right;}'
        . 'th{background:#f3f4f6;}'
        . '.meta{margin:3px 0;}'
        . '</style></head><body>'
        . '<div class="box">'
        . '<h1>رسید ' . esc_html($op_label) . '</h1>'
        . '<div class="meta"><strong>' . esc_html( $is_sale ? 'شماره سفارش:' : 'کد عملیات:' ) . '</strong> ' . esc_html($batch_code) . '</div>'
        . '<div class="meta"><strong>' . esc_html( $is_sale ? 'تاریخ و ساعت:' : 'تاریخ:' ) . '</strong> ' . esc_html($jalali) . '</div>'
        . '<div class="meta"><strong>' . esc_html( $is_sale ? 'فروشنده:' : 'کاربر:' ) . '</strong> ' . esc_html($user_disp ?: 'مهمان') . '</div>'
        . ( $is_sale ? '' : '<div class="meta"><strong>کد کاربر:</strong> ' . esc_html($user_code ?: '—') . '</div>' )
        . ( $is_sale ? '' : '<div class="meta"><strong>توضیحات:</strong> ' . esc_html($purpose ?: '—') . '</div>' )
        . '<div class="meta"><strong>تعداد اقلام:</strong> ' . esc_html( (string) count($rows) ) . ' | <strong>جمع تعداد:</strong> ' . esc_html( (string) $sum ) . '</div>'
        . '<table><thead><tr><th>#</th><th>ID</th><th>نام محصول</th><th>تعداد</th></tr></thead><tbody>' . $rows_html . '</tbody></table>'
        . '</div></body></html>';

    $bytes = file_put_contents( $filepath, $html );
    if ( false === $bytes ) {
        return new WP_Error( 'word_write_failed', 'ایجاد فایل رسید HTML ناموفق بود.' );
    }

    return [ 'path' => $filepath, 'url' => $fileurl ];
}
