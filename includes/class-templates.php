<?php
/**
 * テンプレートと既定設定
 *
 * @package Kashiwazaki_SEO_StayPop
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * テンプレート・既定設定クラス
 */
class Kashiwazaki_SEO_StayPop_Templates {

    /**
     * 用意しているテンプレート一覧
     *
     * @return array<string,string> key => ラベル
     */
    public static function list_templates(): array {
        return [
            'custom'   => __('カスタム', 'kashiwazaki-seo-staypop'),
            'coupon'   => __('クーポン', 'kashiwazaki-seo-staypop'),
            'document' => __('資料請求', 'kashiwazaki-seo-staypop'),
            'line'     => __('LINE登録', 'kashiwazaki-seo-staypop'),
            'related'  => __('関連記事', 'kashiwazaki-seo-staypop'),
            'contact'  => __('問い合わせ誘導', 'kashiwazaki-seo-staypop'),
        ];
    }

    /**
     * 表示形式の一覧
     *
     * @return array<string,string>
     */
    public static function list_formats(): array {
        return [
            'center'     => __('中央ポップアップ', 'kashiwazaki-seo-staypop'),
            'bottom_bar' => __('下部バナー', 'kashiwazaki-seo-staypop'),
            'corner'     => __('隅からのスライド表示', 'kashiwazaki-seo-staypop'),
        ];
    }

    /**
     * 隅の位置の一覧
     *
     * @return array<string,string>
     */
    public static function list_corner_positions(): array {
        return [
            'bottom-right' => __('右下', 'kashiwazaki-seo-staypop'),
            'bottom-left'  => __('左下', 'kashiwazaki-seo-staypop'),
            'top-right'    => __('右上', 'kashiwazaki-seo-staypop'),
            'top-left'     => __('左上', 'kashiwazaki-seo-staypop'),
        ];
    }

    /**
     * 頻度モードの一覧
     *
     * @return array<string,string>
     */
    public static function list_frequency_modes(): array {
        return [
            'once_session' => __('1回の訪問につき1回まで', 'kashiwazaki-seo-staypop'),
            'once_day'     => __('1日1回まで', 'kashiwazaki-seo-staypop'),
            'once_ever'    => __('最初の1回だけ（以後は表示しない）', 'kashiwazaki-seo-staypop'),
            'every_visit'  => __('ページを開くたびに', 'kashiwazaki-seo-staypop'),
        ];
    }

    /**
     * 対象ページの指定方法
     *
     * @return array<string,string>
     */
    public static function list_page_modes(): array {
        return [
            'all'     => __('サイト全体', 'kashiwazaki-seo-staypop'),
            'include' => __('指定したページだけ', 'kashiwazaki-seo-staypop'),
        ];
    }

    /**
     * ログイン状態の対象
     *
     * @return array<string,string>
     */
    public static function list_login_states(): array {
        return [
            'any'         => __('すべての訪問者', 'kashiwazaki-seo-staypop'),
            'logged_in'   => __('ログイン中のみ', 'kashiwazaki-seo-staypop'),
            'logged_out'  => __('未ログインのみ', 'kashiwazaki-seo-staypop'),
        ];
    }

    /**
     * 離脱予兆（PC）の感度: 画面の上端から何 px 以内で外に出たら離脱とみなすか
     *
     * @return array<int,string> px => ラベル
     */
    public static function list_sensitivities(): array {
        return [
            50 => __('高い（上端の近くで外に出ても反応）', 'kashiwazaki-seo-staypop'),
            20 => __('標準', 'kashiwazaki-seo-staypop'),
            0  => __('低い（上端ちょうどから外に出たときだけ）', 'kashiwazaki-seo-staypop'),
        ];
    }

    /**
     * バナーの既定設定（スキーマ）
     *
     * @return array
     */
    public static function get_default_config(): array {
        return [
            // 既定は無効。設定を保存して「有効」にチェックしたバナーだけを表示する
            // （未設定のバナーが既定値（サイト全体・有効）で誤って表示されるのを防ぐ）。
            'enabled'  => false,
            'template' => 'custom',
            'format'   => 'center',
            'corner_position' => 'bottom-right',
            'priority' => 10,
            'content'  => [
                'image_id'       => 0,
                'image_url'      => '',
                'heading'        => __('ここに見出しを入力', 'kashiwazaki-seo-staypop'),
                'description'    => __('お得な情報をお届けします。', 'kashiwazaki-seo-staypop'),
                'button_text'    => __('詳しく見る', 'kashiwazaki-seo-staypop'),
                'button_url'     => '',
                'button_new_tab' => false,
                'secondary_text' => '',
                'secondary_url'  => '',
            ],
            'design'   => [
                'width'            => 520,
                'bg_color'         => '#ffffff',
                'text_color'       => '#222222',
                'button_bg'        => '#2271b1',
                'button_color'     => '#ffffff',
                'radius'           => 12,
                'padding'          => 24,
                'backdrop_opacity' => 0.5,
                'font_size'        => 16,
            ],
            'device'   => [
                'pc'     => ['image_id' => 0, 'image_url' => '', 'width' => 0],
                'mobile' => ['image_id' => 0, 'image_url' => '', 'width' => 0],
            ],
            'triggers' => [
                'exit_intent'       => true,
                'exit_sensitivity'  => 20,
                'exit_delay_ms'     => 0,
                'dwell_seconds'     => 0,
                'scroll_pct'        => 0,
                'upward_scroll'     => true,
                'read_rate'         => 0,
                'min_dwell_seconds' => 5,
                'min_mouse_moves'   => 0,
                'condition_logic'   => 'any',
            ],
            'frequency' => [
                'mode'        => 'once_session',
                'snooze_days' => 7,
            ],
            'targeting' => [
                'pages'      => 'all',
                'urls'       => '',
                'post_ids'   => [],
                'page_ids'   => [],
                'categories' => [],
            ],
            'exclusion' => [
                'urls'                 => '',
                'post_ids'             => [],
                'page_ids'             => [],
                'stop_after_conversion' => false,
            ],
            'schedule' => [
                'start' => '',
                'end'   => '',
            ],
            'audience' => [
                'login_state' => 'any',
                'roles'       => [],
                'utm_sources' => '',
                'referrers'   => '',
            ],
            'conversion' => [
                'conversion_urls' => '',
            ],
            'ab_test' => [
                'enabled'  => false,
                'variants' => [
                    ['label' => 'A', 'weight' => 50, 'heading' => '', 'image_id' => 0, 'button_text' => ''],
                    ['label' => 'B', 'weight' => 50, 'heading' => '', 'image_id' => 0, 'button_text' => ''],
                ],
            ],
        ];
    }

    /**
     * テンプレートに応じた内容の初期値を返す
     *
     * @param string $template テンプレートキー
     * @return array 追加の内容上書き
     */
    public static function get_template_preset(string $template): array {
        $presets = [
            'coupon' => [
                'heading'     => __('【限定】クーポンをプレゼント', 'kashiwazaki-seo-staypop'),
                'description' => __('このページをご覧の方だけに、特別クーポンをご用意しました。', 'kashiwazaki-seo-staypop'),
                'button_text' => __('クーポンを受け取る', 'kashiwazaki-seo-staypop'),
            ],
            'document' => [
                'heading'     => __('資料請求はこちら', 'kashiwazaki-seo-staypop'),
                'description' => __('詳しい資料を無料でお送りします。お気軽にご請求ください。', 'kashiwazaki-seo-staypop'),
                'button_text' => __('資料を請求する', 'kashiwazaki-seo-staypop'),
            ],
            'line' => [
                'heading'     => __('LINEで最新情報を受け取る', 'kashiwazaki-seo-staypop'),
                'description' => __('友だち追加で、お得な情報や限定のお知らせをお届けします。', 'kashiwazaki-seo-staypop'),
                'button_text' => __('友だち追加する', 'kashiwazaki-seo-staypop'),
            ],
            'related' => [
                'heading'     => __('こちらの記事もおすすめ', 'kashiwazaki-seo-staypop'),
                'description' => __('あわせて読みたい関連記事をピックアップしました。', 'kashiwazaki-seo-staypop'),
                'button_text' => __('関連記事を読む', 'kashiwazaki-seo-staypop'),
            ],
            'contact' => [
                'heading'     => __('ご不明な点はありませんか？', 'kashiwazaki-seo-staypop'),
                'description' => __('お気軽にお問い合わせください。担当者がご対応します。', 'kashiwazaki-seo-staypop'),
                'button_text' => __('問い合わせる', 'kashiwazaki-seo-staypop'),
            ],
        ];
        return $presets[$template] ?? [];
    }
}
