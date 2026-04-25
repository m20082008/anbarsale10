<?php
/*--------------------------------------
| ثابت: Store تهرانپارس برای YITH POS
---------------------------------------*/
if ( ! defined('WC_SUF_TEHRANPARS_STORE_ID') ) {
    define('WC_SUF_TEHRANPARS_STORE_ID', 9343);
}

/*--------------------------------------
| DB: ساخت/آپدیت جدول لاگ + آماده‌سازی شمارنده‌ها
---------------------------------------*/
register_activation_hook(WC_SUF_PLUGIN_FILE, function(){
    global $wpdb;
    $table   = $wpdb->prefix.'stock_audit';
    $move_table = $wpdb->prefix.'stock_production_moves';
    $prod_table = $wpdb->prefix.'stock_production_inventory';
    $move_table = $wpdb->prefix.'stock_production_moves';
    $sale_pending_table = $wpdb->prefix.'wc_suf_sale_pending_items';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE `$table` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT,
      `batch_code`   VARCHAR(64) NULL,
      `csv_file_url` TEXT NULL,
      `word_file_url` TEXT NULL,
      `op_type`      VARCHAR(20) NULL,            -- in / out / onlyLabel / out_teh / in_teh
      `purpose`      TEXT NULL,                   -- برای out/out_teh/in_teh
      `print_label`  TINYINT(1) DEFAULT 0,        -- ارسال برای چاپ
      `product_id`   BIGINT UNSIGNED NOT NULL,
      `product_name` TEXT NULL,
      `old_qty`      DECIMAL(20,4) NULL,
      `added_qty`    DECIMAL(20,4) NULL,          -- مقدار تغییر (برای onlyLabel صفر)
      `new_qty`      DECIMAL(20,4) NULL,
      `user_id`      BIGINT UNSIGNED NULL,
      `user_login`   VARCHAR(60) NULL,
      `user_code`    VARCHAR(128) NULL,
      `ip`           VARCHAR(64) NULL,
      `created_at`   DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      KEY `batch_code` (`batch_code`),
      KEY `product_id` (`product_id`),
      KEY `created_at` (`created_at`),
      KEY `user_id`    (`user_id`),
      KEY `user_code`  (`user_code`)
    ) $charset;";

    dbDelta($sql);

    $sql_prod = "CREATE TABLE `$prod_table` (
      `product_id` BIGINT UNSIGNED NOT NULL,
      `product_name` TEXT NULL,
      `sku` VARCHAR(191) NULL,
      `product_type` VARCHAR(40) NULL,
      `parent_id` BIGINT UNSIGNED NULL,
      `attributes_text` TEXT NULL,
      `qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `updated_at` DATETIME NOT NULL,
      PRIMARY KEY (`product_id`),
      KEY `updated_at` (`updated_at`)
    ) $charset;";
    dbDelta($sql_prod);

    $sql_moves = "CREATE TABLE `$move_table` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT,
      `batch_code` VARCHAR(64) NULL,
      `operation` VARCHAR(20) NOT NULL,
      `destination` VARCHAR(40) NULL,
      `product_id` BIGINT UNSIGNED NOT NULL,
      `product_name` TEXT NULL,
      `sku` VARCHAR(191) NULL,
      `product_type` VARCHAR(40) NULL,
      `parent_id` BIGINT UNSIGNED NULL,
      `attributes_text` TEXT NULL,
      `old_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `change_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `new_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `destination_old_qty` DECIMAL(20,4) NULL,
      `destination_new_qty` DECIMAL(20,4) NULL,
      `user_id` BIGINT UNSIGNED NULL,
      `user_login` VARCHAR(60) NULL,
      `user_code` VARCHAR(128) NULL,
      `created_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      KEY `product_id` (`product_id`),
      KEY `batch_code` (`batch_code`),
      KEY `operation` (`operation`),
      KEY `created_at` (`created_at`)
    ) $charset;";
    dbDelta($sql_moves);
    $sql_pending = "CREATE TABLE `$sale_pending_table` (
      `order_id` BIGINT UNSIGNED NOT NULL,
      `product_id` BIGINT UNSIGNED NOT NULL,
      `requested_qty` INT UNSIGNED NOT NULL DEFAULT 0,
      `allocated_qty` INT UNSIGNED NOT NULL DEFAULT 0,
      `pending_qty` INT UNSIGNED NOT NULL DEFAULT 0,
      `updated_at` DATETIME NOT NULL,
      KEY `order_id` (`order_id`),
      KEY `product_id` (`product_id`),
      KEY `updated_at` (`updated_at`)
    ) $charset;";
    dbDelta($sql_pending);
    wc_suf_ensure_sale_pending_unique_index();
    add_option('wc_suf_db_version', '2.6.0');

    if ( get_option('wc_suf_counter_in', null) === null )        add_option('wc_suf_counter_in',  '0', '', false);
    if ( get_option('wc_suf_counter_out', null) === null )       add_option('wc_suf_counter_out', '0', '', false);
    if ( get_option('wc_suf_counter_label', null) === null )     add_option('wc_suf_counter_label', '0', '', false);
    if ( get_option('wc_suf_counter_transfer', null) === null )  add_option('wc_suf_counter_transfer', '0', '', false);

    $max_in    = $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING_INDEX(batch_code, '_', -1) AS UNSIGNED)) FROM `$table` WHERE batch_code LIKE 'in\_%'" );
    $max_out   = $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING_INDEX(batch_code, '_', -1) AS UNSIGNED)) FROM `$table` WHERE batch_code LIKE 'out\_%'" );
    $max_label = $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING_INDEX(batch_code, '_', -1) AS UNSIGNED)) FROM `$table` WHERE batch_code LIKE 'onlyLabel\_%'" );
    $max_transfer = $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING_INDEX(batch_code, '_', -1) AS UNSIGNED)) FROM `$table` WHERE batch_code LIKE 'transfer\_%'" );

    if ( is_numeric($max_in) && (int)$max_in > (int)get_option('wc_suf_counter_in', '0') )           update_option('wc_suf_counter_in', (string) (int)$max_in );
    if ( is_numeric($max_out) && (int)$max_out > (int)get_option('wc_suf_counter_out', '0') )        update_option('wc_suf_counter_out', (string) (int)$max_out );
    if ( is_numeric($max_label) && (int)$max_label > (int)get_option('wc_suf_counter_label', '0') )  update_option('wc_suf_counter_label', (string) (int)$max_label );
    if ( is_numeric($max_transfer) && (int)$max_transfer > (int)get_option('wc_suf_counter_transfer', '0') )  update_option('wc_suf_counter_transfer', (string) (int)$max_transfer );
});
add_action('plugins_loaded', function(){ wc_suf_maybe_upgrade_schema(); });
add_action('plugins_loaded', function(){ wc_suf_migrate_pending_breakdown_meta_once(); }, 20);

function wc_suf_maybe_upgrade_schema(){
    global $wpdb;
    $table = $wpdb->prefix.'stock_audit';
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s", $table
    ) );

    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    $prod_table = $wpdb->prefix.'stock_production_inventory';
    $move_table = $wpdb->prefix.'stock_production_moves';
    $sale_pending_table = $wpdb->prefix.'wc_suf_sale_pending_items';

    dbDelta("CREATE TABLE `$prod_table` (
      `product_id` BIGINT UNSIGNED NOT NULL,
      `product_name` TEXT NULL,
      `sku` VARCHAR(191) NULL,
      `product_type` VARCHAR(40) NULL,
      `parent_id` BIGINT UNSIGNED NULL,
      `attributes_text` TEXT NULL,
      `qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `updated_at` DATETIME NOT NULL,
      PRIMARY KEY (`product_id`),
      KEY `updated_at` (`updated_at`)
    ) $charset;");

    dbDelta("CREATE TABLE `$move_table` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT,
      `batch_code` VARCHAR(64) NULL,
      `operation` VARCHAR(20) NOT NULL,
      `destination` VARCHAR(40) NULL,
      `product_id` BIGINT UNSIGNED NOT NULL,
      `product_name` TEXT NULL,
      `sku` VARCHAR(191) NULL,
      `product_type` VARCHAR(40) NULL,
      `parent_id` BIGINT UNSIGNED NULL,
      `attributes_text` TEXT NULL,
      `old_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `change_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `new_qty` DECIMAL(20,4) NOT NULL DEFAULT 0,
      `destination_old_qty` DECIMAL(20,4) NULL,
      `destination_new_qty` DECIMAL(20,4) NULL,
      `user_id` BIGINT UNSIGNED NULL,
      `user_login` VARCHAR(60) NULL,
      `user_code` VARCHAR(128) NULL,
      `created_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      KEY `product_id` (`product_id`),
      KEY `batch_code` (`batch_code`),
      KEY `operation` (`operation`),
      KEY `created_at` (`created_at`)
    ) $charset;");

    dbDelta("CREATE TABLE `$sale_pending_table` (
      `order_id` BIGINT UNSIGNED NOT NULL,
      `product_id` BIGINT UNSIGNED NOT NULL,
      `requested_qty` INT UNSIGNED NOT NULL DEFAULT 0,
      `allocated_qty` INT UNSIGNED NOT NULL DEFAULT 0,
      `pending_qty` INT UNSIGNED NOT NULL DEFAULT 0,
      `updated_at` DATETIME NOT NULL,
      KEY `order_id` (`order_id`),
      KEY `product_id` (`product_id`),
      KEY `updated_at` (`updated_at`)
    ) $charset;");
    wc_suf_ensure_sale_pending_unique_index();

    if ( ! $exists ) return;

    $needed = [
        'batch_code'  => "ADD COLUMN `batch_code` VARCHAR(64) NULL AFTER `id`",
        'csv_file_url'=> "ADD COLUMN `csv_file_url` TEXT NULL AFTER `batch_code`",
        'word_file_url'=> "ADD COLUMN `word_file_url` TEXT NULL AFTER `csv_file_url`",
        'op_type'     => "ADD COLUMN `op_type` VARCHAR(20) NULL AFTER `word_file_url`",
        'purpose'     => "ADD COLUMN `purpose` TEXT NULL AFTER `op_type`",
        'print_label' => "ADD COLUMN `print_label` TINYINT(1) DEFAULT 0 AFTER `purpose`",
        'product_name'=> "ADD COLUMN `product_name` TEXT NULL AFTER `product_id`",
        'old_qty'     => "ADD COLUMN `old_qty` DECIMAL(20,4) NULL AFTER `product_name`",
        'added_qty'   => "ADD COLUMN `added_qty` DECIMAL(20,4) NULL AFTER `old_qty`",
        'new_qty'     => "ADD COLUMN `new_qty` DECIMAL(20,4) NULL AFTER `added_qty`",
        'user_id'     => "ADD COLUMN `user_id` BIGINT UNSIGNED NULL AFTER `new_qty`",
        'user_login'  => "ADD COLUMN `user_login` VARCHAR(60) NULL AFTER `user_id`",
        'user_code'   => "ADD COLUMN `user_code` VARCHAR(128) NULL AFTER `user_login`",
        'ip'          => "ADD COLUMN `ip` VARCHAR(64) NULL AFTER `user_code`",
        'created_at'  => "ADD COLUMN `created_at` DATETIME NOT NULL AFTER `ip`",
    ];
    $missing = [];
    foreach($needed as $col => $ddl){
        $has = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $col) );
        if ( ! $has ) $missing[] = $ddl;
    }
    if ( $missing ){
        $sql = "ALTER TABLE `$table` " . implode(", ", $missing) . ";";
        $wpdb->query($sql);
    }

    $move_needed = [
        'destination_old_qty' => "ADD COLUMN `destination_old_qty` DECIMAL(20,4) NULL AFTER `new_qty`",
        'destination_new_qty' => "ADD COLUMN `destination_new_qty` DECIMAL(20,4) NULL AFTER `destination_old_qty`",
    ];
    $move_missing = [];
    foreach ( $move_needed as $col => $ddl ) {
        $has = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM `$move_table` LIKE %s", $col) );
        if ( ! $has ) $move_missing[] = $ddl;
    }
    if ( $move_missing ) {
        $wpdb->query( "ALTER TABLE `$move_table` " . implode(', ', $move_missing) . ';' );
    }

    $indexes = [
        'batch_code' => "ADD KEY `batch_code` (`batch_code`)",
        'product_id' => "ADD KEY `product_id` (`product_id`)",
        'created_at' => "ADD KEY `created_at` (`created_at`)",
        'user_id'    => "ADD KEY `user_id` (`user_id`)",
        'user_code'  => "ADD KEY `user_code` (`user_code`)",
    ];
    foreach($indexes as $iname => $add){
        $hasIdx = $wpdb->get_var( $wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s", $iname) );
        if ( ! $hasIdx ){ $wpdb->query("ALTER TABLE `$table` $add"); }
    }

    if ( get_option('wc_suf_counter_in', null) === null )      add_option('wc_suf_counter_in',  '0', '', false);
    if ( get_option('wc_suf_counter_out', null) === null )     add_option('wc_suf_counter_out', '0', '', false);
    if ( get_option('wc_suf_counter_label', null) === null )   add_option('wc_suf_counter_label', '0', '', false);
    if ( get_option('wc_suf_counter_transfer', null) === null ) add_option('wc_suf_counter_transfer', '0', '', false);
}

function wc_suf_migrate_pending_breakdown_meta_once() {
    if ( get_option( 'wc_suf_pending_items_migration_v1_done', '0' ) === '1' ) {
        return;
    }
    if ( ! function_exists( 'wc_get_orders' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wc_suf_sale_pending_items';
    $migrated_count = 0;
    $page = 1;
    $per_page = 100;

    do {
        $orders = wc_get_orders([
            'type'      => 'shop_order',
            'limit'     => $per_page,
            'page'      => $page,
            'orderby'   => 'date',
            'order'     => 'DESC',
            'meta_key'  => '_wc_suf_pending_breakdown',
            'meta_compare' => 'EXISTS',
            'return'    => 'objects',
        ]);
        if ( ! is_array( $orders ) || empty( $orders ) ) {
            break;
        }

        foreach ( $orders as $order ) {
            if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
                continue;
            }
            $rows = json_decode( (string) $order->get_meta( '_wc_suf_pending_breakdown', true ), true );
            if ( ! is_array( $rows ) || empty( $rows ) ) {
                continue;
            }
            $order_id = (int) $order->get_id();
            if ( $order_id <= 0 ) {
                continue;
            }
            foreach ( $rows as $row ) {
                $pid = absint( $row['product_id'] ?? 0 );
                if ( $pid <= 0 ) {
                    continue;
                }
                $requested = max( 0, (int) ( $row['requested_qty'] ?? 0 ) );
                $allocated = max( 0, (int) ( $row['allocated_qty'] ?? 0 ) );
                $pending = max( 0, (int) ( $row['pending_qty'] ?? 0 ) );
                if ( $requested <= 0 && $allocated <= 0 && $pending <= 0 ) {
                    continue;
                }
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT INTO `$table` (`order_id`, `product_id`, `requested_qty`, `allocated_qty`, `pending_qty`, `updated_at`)
                         VALUES (%d, %d, %d, %d, %d, %s)
                         ON DUPLICATE KEY UPDATE
                            `requested_qty` = VALUES(`requested_qty`),
                            `allocated_qty` = VALUES(`allocated_qty`),
                            `pending_qty` = VALUES(`pending_qty`),
                            `updated_at` = VALUES(`updated_at`)",
                        $order_id,
                        $pid,
                        $requested,
                        $allocated,
                        $pending,
                        current_time( 'mysql' )
                    )
                );
                $migrated_count++;
            }
        }
        $page++;
    } while ( count( $orders ) === $per_page );

    update_option( 'wc_suf_pending_items_migration_v1_done', '1', false );
    update_option( 'wc_suf_pending_items_migration_v1_count', (string) (int) $migrated_count, false );
    error_log( '[wc_suf] pending items migration completed. Migrated rows: ' . (int) $migrated_count );
}

function wc_suf_ensure_sale_pending_unique_index() {
    global $wpdb;
    $table = $wpdb->prefix . 'wc_suf_sale_pending_items';
    $table_exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
        $table
    ) );
    if ( ! $table_exists ) {
        return;
    }

    $has_unique = $wpdb->get_var(
        "SHOW INDEX FROM `$table` WHERE Key_name = 'order_product' AND Non_unique = 0"
    );
    if ( $has_unique ) {
        return;
    }

    $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
    $unique_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM `$table` GROUP BY order_id, product_id) t" );
    if ( $total_rows > $unique_rows ) {
        $tmp_table = $table . '_dedupe_tmp';
        $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS `$tmp_table`" );
        $wpdb->query(
            "CREATE TEMPORARY TABLE `$tmp_table` AS
             SELECT
                order_id,
                product_id,
                MAX(requested_qty) AS requested_qty,
                MAX(allocated_qty) AS allocated_qty,
                MAX(pending_qty) AS pending_qty,
                MAX(updated_at) AS updated_at
             FROM `$table`
             GROUP BY order_id, product_id"
        );
        $wpdb->query( "TRUNCATE TABLE `$table`" );
        $wpdb->query(
            "INSERT INTO `$table` (`order_id`, `product_id`, `requested_qty`, `allocated_qty`, `pending_qty`, `updated_at`)
             SELECT order_id, product_id, requested_qty, allocated_qty, pending_qty, updated_at
             FROM `$tmp_table`"
        );
        $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS `$tmp_table`" );
    }

    $has_plain_index = $wpdb->get_var(
        "SHOW INDEX FROM `$table` WHERE Key_name = 'order_product'"
    );
    if ( $has_plain_index ) {
        $wpdb->query( "ALTER TABLE `$table` DROP INDEX `order_product`" );
    }
    $wpdb->query( "ALTER TABLE `$table` ADD UNIQUE KEY `order_product` (`order_id`, `product_id`)" );
}
