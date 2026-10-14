<?php
/**
 * プラグインアンインストール時の処理
 *
 * @package Kashiwazaki_SEO_StayPop
 */

// アンインストール以外からのアクセスを禁止
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 全体設定を削除
delete_option('kashiwazaki_seo_staypop_settings');

// 毎日のログ削除の予約を外す
wp_clear_scheduled_hook('kashiwazaki_staypop_daily_prune');

// バナー（投稿タイプ）とそのメタを削除
$banner_ids = $wpdb->get_col(
    $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'kashiwazaki_staypop')
);
foreach ((array) $banner_ids as $id) {
    wp_delete_post((int) $id, true);
}

// 集計テーブルを削除
$table = $wpdb->prefix . 'kashiwazaki_staypop_events';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query("DROP TABLE IF EXISTS {$table}");

wp_cache_flush();
