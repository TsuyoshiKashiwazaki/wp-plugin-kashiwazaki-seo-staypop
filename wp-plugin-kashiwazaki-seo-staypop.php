<?php
/**
 * Plugin Name: Kashiwazaki SEO StayPop
 * Plugin URI: https://www.tsuyoshikashiwazaki.jp
 * Description: 訪問者の離脱予兆を検知し、バナーやポップアップで購入・問い合わせ・登録を促すWordPressプラグインです。PCのマウス離脱・スマホの上スクロール/滞在時間/読了率などの条件、表示ページ・除外ページ、端末別設定、頻度制限、A/Bテスト、表示/クリック/成果の集計に対応します。
 * Version: 1.0.0
 * Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
 * Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kashiwazaki-seo-staypop
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Kashiwazaki_SEO_StayPop
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

// プラグイン定数
define('KASHIWAZAKI_SEO_STAYPOP_VERSION', '1.0.0');
define('KASHIWAZAKI_SEO_STAYPOP_FILE', __FILE__);
define('KASHIWAZAKI_SEO_STAYPOP_DIR', plugin_dir_path(__FILE__));
define('KASHIWAZAKI_SEO_STAYPOP_URL', plugin_dir_url(__FILE__));
define('KASHIWAZAKI_SEO_STAYPOP_BASENAME', plugin_basename(__FILE__));

/**
 * メインプラグインクラス
 */
final class Kashiwazaki_SEO_StayPop {

    /**
     * シングルトンインスタンス
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * 設定インスタンス
     *
     * @var Kashiwazaki_SEO_StayPop_Settings
     */
    public Kashiwazaki_SEO_StayPop_Settings $settings;

    /**
     * バナー（投稿タイプ）管理
     *
     * @var Kashiwazaki_SEO_StayPop_Banners
     */
    public Kashiwazaki_SEO_StayPop_Banners $banners;

    /**
     * 集計
     *
     * @var Kashiwazaki_SEO_StayPop_Stats
     */
    public Kashiwazaki_SEO_StayPop_Stats $stats;

    /**
     * 条件判定
     *
     * @var Kashiwazaki_SEO_StayPop_Conditions
     */
    public Kashiwazaki_SEO_StayPop_Conditions $conditions;

    /**
     * 管理画面
     *
     * @var Kashiwazaki_SEO_StayPop_Admin|null
     */
    public ?Kashiwazaki_SEO_StayPop_Admin $admin = null;

    /**
     * バナーの編集画面
     *
     * @var Kashiwazaki_SEO_StayPop_Editor|null
     */
    public ?Kashiwazaki_SEO_StayPop_Editor $editor = null;

    /**
     * フロントエンド
     *
     * @var Kashiwazaki_SEO_StayPop_Frontend|null
     */
    public ?Kashiwazaki_SEO_StayPop_Frontend $frontend = null;

    /**
     * シングルトンインスタンスを取得
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * 依存ファイルを読み込み
     */
    private function load_dependencies(): void {
        require_once KASHIWAZAKI_SEO_STAYPOP_DIR . 'includes/class-settings.php';
        require_once KASHIWAZAKI_SEO_STAYPOP_DIR . 'includes/class-templates.php';
        require_once KASHIWAZAKI_SEO_STAYPOP_DIR . 'includes/class-banners.php';
        require_once KASHIWAZAKI_SEO_STAYPOP_DIR . 'includes/class-stats.php';
        require_once KASHIWAZAKI_SEO_STAYPOP_DIR . 'includes/class-conditions.php';
        require_once KASHIWAZAKI_SEO_STAYPOP_DIR . 'includes/class-frontend.php';
        require_once KASHIWAZAKI_SEO_STAYPOP_DIR . 'includes/class-admin.php';
        require_once KASHIWAZAKI_SEO_STAYPOP_DIR . 'includes/class-editor.php';
    }

    /**
     * コンポーネントを初期化
     */
    private function init_components(): void {
        $this->settings   = new Kashiwazaki_SEO_StayPop_Settings();
        $this->conditions = new Kashiwazaki_SEO_StayPop_Conditions($this->settings);
        $this->stats      = new Kashiwazaki_SEO_StayPop_Stats($this->settings, $this->conditions);
        $this->banners    = new Kashiwazaki_SEO_StayPop_Banners();

        if (is_admin()) {
            $this->admin  = new Kashiwazaki_SEO_StayPop_Admin($this->settings, $this->banners, $this->stats, $this->conditions);
            $this->editor = new Kashiwazaki_SEO_StayPop_Editor($this->banners, $this->conditions);
        } else {
            $this->frontend = new Kashiwazaki_SEO_StayPop_Frontend($this->settings, $this->banners, $this->conditions, $this->stats);
        }
    }

    /**
     * フックを登録
     */
    private function register_hooks(): void {
        register_activation_hook(KASHIWAZAKI_SEO_STAYPOP_FILE, [$this, 'activate']);
        register_deactivation_hook(KASHIWAZAKI_SEO_STAYPOP_FILE, ['Kashiwazaki_SEO_StayPop_Stats', 'unschedule_prune']);

        // プラグイン一覧に「設定」リンク
        add_filter(
            'plugin_action_links_' . KASHIWAZAKI_SEO_STAYPOP_BASENAME,
            [$this, 'add_action_links']
        );

        add_action('init', [$this, 'load_textdomain']);
    }

    /**
     * プラグイン有効化時
     */
    public function activate(): void {
        if (get_option(Kashiwazaki_SEO_StayPop_Settings::OPTION_NAME) === false) {
            update_option(Kashiwazaki_SEO_StayPop_Settings::OPTION_NAME, $this->settings->get_defaults());
        }
        $this->stats->create_table();
        $this->stats->schedule_prune();
        // バナー投稿タイプを登録してからリライトを反映
        $this->banners->register_post_type();
        flush_rewrite_rules();
    }

    /**
     * CSS/JS の版（プラグインの版＋ファイルの更新時刻）。
     * 版が同じままだとブラウザが古いファイルを使い続けるため、ファイルを更新したら URL が変わるようにする。
     *
     * @param string $rel プラグインフォルダからの相対パス
     * @return string
     */
    public static function asset_ver(string $rel): string {
        $path = KASHIWAZAKI_SEO_STAYPOP_DIR . $rel;
        $time = file_exists($path) ? (int) filemtime($path) : 0;
        return KASHIWAZAKI_SEO_STAYPOP_VERSION . ($time > 0 ? '.' . $time : '');
    }

    /**
     * プラグイン一覧に「設定」リンクを追加
     *
     * @param array $links 既存のアクションリンク
     * @return array
     */
    public function add_action_links(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=kashiwazaki-seo-staypop&tab=settings')),
            esc_html__('設定', 'kashiwazaki-seo-staypop')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * 翻訳ファイルを読み込み
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'kashiwazaki-seo-staypop',
            false,
            dirname(KASHIWAZAKI_SEO_STAYPOP_BASENAME) . '/languages'
        );
    }
}

/**
 * プラグインインスタンスを取得
 *
 * @return Kashiwazaki_SEO_StayPop
 */
function kashiwazaki_seo_staypop(): Kashiwazaki_SEO_StayPop {
    return Kashiwazaki_SEO_StayPop::get_instance();
}

kashiwazaki_seo_staypop();
