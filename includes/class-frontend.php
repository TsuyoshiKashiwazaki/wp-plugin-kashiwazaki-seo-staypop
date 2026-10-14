<?php
/**
 * フロントエンド
 *
 * @package Kashiwazaki_SEO_StayPop
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * フロントエンドクラス
 */
class Kashiwazaki_SEO_StayPop_Frontend {

    /**
     * テスト表示のクエリ名
     */
    public const TEST_QUERY = 'kashiwazaki_staypop_test';

    /**
     * 設定
     *
     * @var Kashiwazaki_SEO_StayPop_Settings
     */
    private Kashiwazaki_SEO_StayPop_Settings $settings;

    /**
     * バナー
     *
     * @var Kashiwazaki_SEO_StayPop_Banners
     */
    private Kashiwazaki_SEO_StayPop_Banners $banners;

    /**
     * 条件
     *
     * @var Kashiwazaki_SEO_StayPop_Conditions
     */
    private Kashiwazaki_SEO_StayPop_Conditions $conditions;

    /**
     * 集計
     *
     * @var Kashiwazaki_SEO_StayPop_Stats
     */
    private Kashiwazaki_SEO_StayPop_Stats $stats;

    /**
     * コンストラクタ
     *
     * @param Kashiwazaki_SEO_StayPop_Settings   $settings   設定
     * @param Kashiwazaki_SEO_StayPop_Banners    $banners    バナー
     * @param Kashiwazaki_SEO_StayPop_Conditions $conditions 条件
     * @param Kashiwazaki_SEO_StayPop_Stats      $stats      集計
     */
    public function __construct(
        Kashiwazaki_SEO_StayPop_Settings $settings,
        Kashiwazaki_SEO_StayPop_Banners $banners,
        Kashiwazaki_SEO_StayPop_Conditions $conditions,
        Kashiwazaki_SEO_StayPop_Stats $stats
    ) {
        $this->settings   = $settings;
        $this->banners    = $banners;
        $this->conditions = $conditions;
        $this->stats      = $stats;

        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * 「サイトでテスト表示」の URL（管理者だけが使える。nonce つき）
     *
     * @param int $banner_id バナーID
     * @return string
     */
    public static function test_url(int $banner_id): string {
        return add_query_arg(
            [
                self::TEST_QUERY => $banner_id,
                '_ksp_nonce'     => wp_create_nonce('kashiwazaki_staypop_test_' . $banner_id),
            ],
            home_url('/')
        );
    }

    /**
     * テスト表示の要求なら、そのバナーIDを返す（権限・nonce を確かめる）
     *
     * @return int
     */
    private function test_banner_id(): int {
        if (!isset($_GET[self::TEST_QUERY], $_GET['_ksp_nonce'])) {
            return 0;
        }
        $id = intval($_GET[self::TEST_QUERY]);
        if ($id <= 0 || get_post_type($id) !== Kashiwazaki_SEO_StayPop_Banners::POST_TYPE) {
            return 0;
        }
        if (!current_user_can('edit_post', $id)) {
            return 0;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_ksp_nonce'])), 'kashiwazaki_staypop_test_' . $id)) {
            return 0;
        }
        return $id;
    }

    /**
     * 対象ページのみスクリプトとスタイルを読み込む（軽量化）
     */
    public function enqueue(): void {
        if (is_admin() || is_feed() || is_robots() || is_trackback()) {
            return;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        $path    = $this->conditions->current_path();
        $test_id = $this->test_banner_id();

        if ($test_id > 0) {
            // テスト表示: 表示するページ・期間・回数・有効かどうかを無視し、計測もしない
            $banner          = $this->export_banner($test_id);
            $banner['title'] = get_the_title($test_id);
            $this->output([
                'ajaxUrl'           => '',
                'eventAction'       => '',
                'nonce'             => '',
                'allowAnalytics'    => false,
                'page'              => $path,
                'test'              => true,
                'banners'           => [$banner],
                'conversionBanners' => [],
            ]);
            return;
        }

        $eligible = $this->conditions->eligible_banner_ids($this->banners);

        // 成果地点（除外ページ等）でも「成果で停止」を判定できるよう、
        // 表示候補とは別に、このページが成果URLに一致するバナーを集める。
        $conversion_banners = [];
        foreach ($this->banners->get_active_banner_ids() as $id) {
            $config = $this->banners->get_config($id);
            $urls   = (string) ($config['conversion']['conversion_urls'] ?? '');
            if ($urls === '' || !$this->conditions->match_patterns($path, $urls)) {
                continue;
            }
            $conversion_banners[] = [
                'id'             => $id,
                'conversionUrls' => $urls,
                'stopAfterConv'  => !empty($config['exclusion']['stop_after_conversion']),
            ];
        }

        if (empty($eligible) && empty($conversion_banners)) {
            return; // 表示候補も成果判定も無いページでは何も読み込まない
        }

        $banners_data = [];
        foreach ($eligible as $id) {
            $banners_data[] = $this->export_banner($id);
        }

        $this->output([
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'eventAction'       => 'kashiwazaki_staypop_event',
            'nonce'             => wp_create_nonce('kashiwazaki_staypop_event'),
            'allowAnalytics'    => $this->conditions->analytics_allowed() && $this->settings->is_analytics_enabled(),
            'page'              => $path,
            'banners'           => $banners_data,
            'conversionBanners' => $conversion_banners,
        ]);
    }

    /**
     * CSS/JS とデータを出力する
     *
     * @param array $data JS に渡すデータ
     */
    private function output(array $data): void {
        wp_enqueue_style(
            'kashiwazaki-seo-staypop',
            KASHIWAZAKI_SEO_STAYPOP_URL . 'assets/css/frontend.css',
            [],
            Kashiwazaki_SEO_StayPop::asset_ver('assets/css/frontend.css')
        );
        wp_register_script(
            'kashiwazaki-seo-staypop',
            KASHIWAZAKI_SEO_STAYPOP_URL . 'assets/js/frontend.js',
            [],
            Kashiwazaki_SEO_StayPop::asset_ver('assets/js/frontend.js'),
            true
        );
        wp_localize_script('kashiwazaki-seo-staypop', 'KashiwazakiSeoStayPopData', $data);
        wp_enqueue_script('kashiwazaki-seo-staypop');
    }

    /**
     * JS に渡すバナー情報を組み立てる（必要な項目だけ）
     *
     * @param int $id バナーID
     * @return array
     */
    public function export_banner(int $id): array {
        return self::export_config($id, $this->banners->get_config($id));
    }

    /**
     * 設定から JS 用のバナー情報を作る（編集画面のプレビューと同じ形）
     *
     * @param int   $id バナーID
     * @param array $c  設定
     * @return array
     */
    public static function export_config(int $id, array $c): array {
        $device = [
            'pc'     => ['image' => '', 'width' => 0],
            'mobile' => ['image' => (string) ($c['device']['mobile']['image_url'] ?? ''), 'width' => (int) ($c['device']['mobile']['width'] ?? 0)],
        ];
        $variants = [];
        foreach ((array) ($c['ab_test']['variants'] ?? []) as $v) {
            if (!is_array($v)) {
                continue;
            }
            $variants[] = [
                'label'       => (string) ($v['label'] ?? ''),
                'weight'      => (int) ($v['weight'] ?? 0),
                'image'       => (string) ($v['image_url'] ?? ''),
                'heading'     => (string) ($v['heading'] ?? ''),
                'button_text' => (string) ($v['button_text'] ?? ''),
            ];
        }
        $t = (array) ($c['triggers'] ?? []);
        return [
            'id'             => $id,
            'priority'       => (int) ($c['priority'] ?? 10),
            'format'         => (string) ($c['format'] ?? 'center'),
            'corner'         => (string) ($c['corner_position'] ?? 'bottom-right'),
            'content'        => [
                'image'     => (string) ($c['content']['image_url'] ?? ''),
                'heading'   => (string) ($c['content']['heading'] ?? ''),
                'desc'      => (string) ($c['content']['description'] ?? ''),
                'btnText'   => (string) ($c['content']['button_text'] ?? ''),
                'btnUrl'    => (string) ($c['content']['button_url'] ?? ''),
                'btnNewTab' => !empty($c['content']['button_new_tab']),
                'subText'   => (string) ($c['content']['secondary_text'] ?? ''),
                'subUrl'    => (string) ($c['content']['secondary_url'] ?? ''),
            ],
            'design'         => (array) ($c['design'] ?? []),
            'device'         => $device,
            'triggers'       => [
                'exit_intent'       => !empty($t['exit_intent']),
                'exit_sensitivity'  => (int) ($t['exit_sensitivity'] ?? 20),
                'exit_delay_ms'     => (int) ($t['exit_delay_ms'] ?? 0),
                'upward_scroll'     => !empty($t['upward_scroll']),
                'dwell_seconds'     => (int) ($t['dwell_seconds'] ?? 0),
                'read_rate'         => (int) ($t['read_rate'] ?? 0),
                'min_dwell_seconds' => (int) ($t['min_dwell_seconds'] ?? 0),
                'condition_logic'   => (($t['condition_logic'] ?? 'any') === 'all') ? 'all' : 'any',
            ],
            'frequency'      => (array) ($c['frequency'] ?? []),
            'ab'             => ['enabled' => !empty($c['ab_test']['enabled']), 'variants' => $variants],
            'conversionUrls' => (string) ($c['conversion']['conversion_urls'] ?? ''),
            'stopAfterConv'  => !empty($c['exclusion']['stop_after_conversion']),
        ];
    }
}
