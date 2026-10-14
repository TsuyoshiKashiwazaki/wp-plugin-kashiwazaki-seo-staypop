<?php
/**
 * 表示・クリック・成果の集計
 *
 * @package Kashiwazaki_SEO_StayPop
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 集計クラス
 */
class Kashiwazaki_SEO_StayPop_Stats {

    /**
     * イベント種別
     */
    public const EVENTS = ['impression', 'click', 'close', 'conversion'];

    /**
     * 全体設定
     *
     * @var Kashiwazaki_SEO_StayPop_Settings|null
     */
    private ?Kashiwazaki_SEO_StayPop_Settings $settings;

    /**
     * 条件判定（計測の可否）
     *
     * @var Kashiwazaki_SEO_StayPop_Conditions|null
     */
    private ?Kashiwazaki_SEO_StayPop_Conditions $conditions;

    /**
     * フック登録
     *
     * @param Kashiwazaki_SEO_StayPop_Settings|null   $settings   全体設定
     * @param Kashiwazaki_SEO_StayPop_Conditions|null $conditions 条件判定
     */
    public function __construct(?Kashiwazaki_SEO_StayPop_Settings $settings = null, ?Kashiwazaki_SEO_StayPop_Conditions $conditions = null) {
        $this->settings   = $settings;
        $this->conditions = $conditions;
        add_action('wp_ajax_kashiwazaki_staypop_event', [$this, 'handle_event']);
        add_action('wp_ajax_nopriv_kashiwazaki_staypop_event', [$this, 'handle_event']);
        // 保持日数を過ぎたログを毎日自動で消す
        add_action(self::CRON_HOOK, [$this, 'cron_prune']);
        add_action('init', [$this, 'schedule_prune']);
    }

    /**
     * 毎日のログ削除のフック名
     */
    public const CRON_HOOK = 'kashiwazaki_staypop_daily_prune';

    /**
     * 毎日のログ削除を予約する（未予約のときだけ）
     */
    public function schedule_prune(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * 毎日のログ削除の予約を外す（無効化のとき）
     */
    public static function unschedule_prune(): void {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    /**
     * 保持日数を過ぎたログを消す
     */
    public function cron_prune(): void {
        $days = $this->settings ? (int) $this->settings->get('stats_retention_days') : 180;
        $this->prune($days > 0 ? $days : 180);
    }

    /**
     * テーブル名を取得
     *
     * @return string
     */
    public function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'kashiwazaki_staypop_events';
    }

    /**
     * 集計テーブルを作成する（dbDelta）
     */
    public function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = $this->table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            banner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event varchar(20) NOT NULL DEFAULT '',
            variant varchar(8) NOT NULL DEFAULT '',
            device varchar(10) NOT NULL DEFAULT '',
            page_url varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY banner_id (banner_id),
            KEY event (event),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta($sql);
    }

    /**
     * フロントからのイベント記録（Ajax）
     */
    public function handle_event(): void {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'kashiwazaki_staypop_event')) {
            wp_send_json_error(['message' => 'invalid_nonce'], 403);
        }
        $banner_id = isset($_POST['banner_id']) ? intval($_POST['banner_id']) : 0;
        $event     = isset($_POST['event']) ? sanitize_key(wp_unslash($_POST['event'])) : '';
        // パターンは A / B のどちらか（sanitize_key は小文字にするため、大文字に戻して集計の行と合わせる）
        $variant   = isset($_POST['variant']) ? strtoupper(sanitize_key(wp_unslash($_POST['variant']))) : '';
        if (!in_array($variant, ['A', 'B'], true)) {
            $variant = '';
        }
        $device    = isset($_POST['device']) ? sanitize_key(wp_unslash($_POST['device'])) : '';
        $page      = isset($_POST['page']) ? esc_url_raw(wp_unslash($_POST['page'])) : '';

        if ($banner_id <= 0 || !in_array($event, self::EVENTS, true)) {
            wp_send_json_error(['message' => 'invalid'], 400);
        }
        // 計測の許可（OFF / Do Not Track / 同意なし）をサーバー側でも再確認する
        if ($this->conditions !== null && !$this->conditions->analytics_allowed()) {
            wp_send_json_error(['message' => 'analytics_disabled'], 403);
        }
        // 存在する公開バナー以外は記録しない（不正な ID での記録を防ぐ）
        if (get_post_type($banner_id) !== Kashiwazaki_SEO_StayPop_Banners::POST_TYPE
            || get_post_status($banner_id) !== 'publish') {
            wp_send_json_error(['message' => 'invalid_banner'], 404);
        }
        if (!in_array($device, ['pc', 'mobile'], true)) {
            $device = '';
        }
        // 記録するページはパスだけに丸める（クエリ・外部情報を持ち込まない）
        $path = wp_parse_url($page, PHP_URL_PATH);
        $path = is_string($path) ? substr($path, 0, 255) : '';

        $ok = $this->log($banner_id, $event, $variant, $device, $path);
        if (!$ok) {
            // 記録できなかったことを本文で伝える（クライアントは res.ok だけでなく本文の success も確認する）
            wp_send_json_error(['message' => 'log_failed']);
        }
        wp_send_json_success(['ok' => true]);
    }

    /**
     * イベントを記録
     *
     * @param int    $banner_id バナーID
     * @param string $event     種別
     * @param string $variant   A/B
     * @param string $device    pc/mobile
     * @param string $path      ページパス
     */
    public function log(int $banner_id, string $event, string $variant = '', string $device = '', string $path = ''): bool {
        global $wpdb;
        $ok = $wpdb->insert(
            $this->table_name(),
            [
                'banner_id'  => $banner_id,
                'event'      => $event,
                'variant'    => substr($variant, 0, 8),
                'device'     => $device,
                'page_url'   => substr($path, 0, 255),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
        return $ok !== false;
    }

    /**
     * バナー別の集計
     *
     * @param int $days 直近の日数
     * @return array
     */
    public function totals_by_banner(int $days = 30): array {
        global $wpdb;
        $table = $this->table_name();
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT banner_id, event, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY banner_id, event",
            $since
        ), ARRAY_A);
        return $this->pivot($rows, 'banner_id');
    }

    /**
     * 端末別の集計
     *
     * @param int $days 直近の日数
     * @return array
     */
    public function totals_by_device(int $days = 30): array {
        global $wpdb;
        $table = $this->table_name();
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT device, event, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY device, event",
            $since
        ), ARRAY_A);
        return $this->pivot($rows, 'device');
    }

    /**
     * ページ別の集計
     *
     * @param int $days  直近の日数
     * @param int $limit 件数
     * @return array
     */
    public function totals_by_page(int $days = 30, int $limit = 30): array {
        global $wpdb;
        $table = $this->table_name();
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT page_url, event, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY page_url, event",
            $since
        ), ARRAY_A);
        $pivot = $this->pivot($rows, 'page_url');
        // 合計（total）の降順で並べる（表示数の多いページを上位に）
        uasort($pivot, static function ($a, $b) {
            return ($b['total'] ?? 0) <=> ($a['total'] ?? 0);
        });
        return array_slice($pivot, 0, $limit, true);
    }

    /**
     * A/B（variant）別の集計（バナー絞り込み）
     *
     * @param int $banner_id バナーID
     * @param int $days      直近の日数
     * @return array
     */
    public function totals_by_variant(int $banner_id, int $days = 30): array {
        global $wpdb;
        $table = $this->table_name();
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare(
            // 小文字（a / b）で記録されたログも、大文字にそろえて数える
            "SELECT UPPER(variant) AS variant, event, COUNT(*) AS n FROM {$table} WHERE created_at >= %s AND banner_id = %d GROUP BY UPPER(variant), event",
            $since, $banner_id
        ), ARRAY_A);
        return $this->pivot($rows, 'variant');
    }

    /**
     * GROUP BY の結果を キー => [event => n] に整形
     *
     * @param array  $rows 結果
     * @param string $key  グループ列
     * @return array
     */
    private function pivot(array $rows, string $key): array {
        $out = [];
        foreach ($rows as $row) {
            $k = (string) $row[$key];
            if (!isset($out[$k])) {
                $out[$k] = array_fill_keys(self::EVENTS, 0);
                $out[$k]['total'] = 0;
            }
            $out[$k][$row['event']] = (int) $row['n'];
            $out[$k]['total'] += (int) $row['n'];
        }
        return $out;
    }

    /**
     * 保持日数を超えた古いログを削除
     *
     * @param int $days 保持日数
     * @return int 削除件数
     */
    public function prune(int $days): int {
        global $wpdb;
        $days = max(1, $days);
        $cut  = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cut));
        return (int) $deleted;
    }

    /**
     * 全ログを削除
     *
     * @return int
     */
    public function clear_all(): int {
        global $wpdb;
        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query("DELETE FROM {$table}");
    }
}
