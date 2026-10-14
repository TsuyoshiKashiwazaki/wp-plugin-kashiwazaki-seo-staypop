<?php
/**
 * 管理画面（左メニュー1つ・タブで切り替え）
 *
 * タブ: バナー一覧 / 集計 / 設定。バナーの作成・編集は class-editor.php の編集画面で行う。
 *
 * @package Kashiwazaki_SEO_StayPop
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 管理画面クラス
 */
class Kashiwazaki_SEO_StayPop_Admin {

    /**
     * 管理画面のスラッグ
     */
    public const MENU_SLUG = 'kashiwazaki-seo-staypop';

    /**
     * 集計で選べる期間（日）
     */
    private const PERIODS = [7, 30, 90];

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
     * 集計
     *
     * @var Kashiwazaki_SEO_StayPop_Stats
     */
    private Kashiwazaki_SEO_StayPop_Stats $stats;

    /**
     * 条件判定
     *
     * @var Kashiwazaki_SEO_StayPop_Conditions
     */
    private Kashiwazaki_SEO_StayPop_Conditions $conditions;

    /**
     * コンストラクタ
     *
     * @param Kashiwazaki_SEO_StayPop_Settings   $settings   設定
     * @param Kashiwazaki_SEO_StayPop_Banners    $banners    バナー
     * @param Kashiwazaki_SEO_StayPop_Stats      $stats      集計
     * @param Kashiwazaki_SEO_StayPop_Conditions $conditions 条件判定
     */
    public function __construct(
        Kashiwazaki_SEO_StayPop_Settings $settings,
        Kashiwazaki_SEO_StayPop_Banners $banners,
        Kashiwazaki_SEO_StayPop_Stats $stats,
        Kashiwazaki_SEO_StayPop_Conditions $conditions
    ) {
        $this->settings   = $settings;
        $this->banners    = $banners;
        $this->stats      = $stats;
        $this->conditions = $conditions;

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_kashiwazaki_staypop_save_settings', [$this, 'handle_settings_save']);
        add_action('admin_post_kashiwazaki_staypop_export', [$this, 'handle_export']);
        add_action('admin_post_kashiwazaki_staypop_import', [$this, 'handle_import']);
        add_action('admin_post_kashiwazaki_staypop_stats_action', [$this, 'handle_stats_action']);
    }

    /**
     * 管理メニューを追加（位置81・1ページをタブで切り替える）
     */
    public function add_admin_menu(): void {
        add_menu_page(
            __('Kashiwazaki SEO StayPop', 'kashiwazaki-seo-staypop'),
            __('Kashiwazaki SEO StayPop', 'kashiwazaki-seo-staypop'),
            'edit_posts',
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-megaphone',
            81
        );
    }

    /**
     * 管理画面の CSS/JS（このプラグインの画面だけ）
     *
     * @param string $hook 現在のフック
     */
    public function enqueue_admin_assets(string $hook): void {
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }
        wp_enqueue_style('kashiwazaki-seo-staypop-admin', KASHIWAZAKI_SEO_STAYPOP_URL . 'assets/css/admin.css', [], Kashiwazaki_SEO_StayPop::asset_ver('assets/css/admin.css'));
        // 一覧の「表示のタイミング」を編集画面と同じ文で出すため、フロントの文章化処理を使う（発火はしない）
        wp_enqueue_script('kashiwazaki-seo-staypop-render', KASHIWAZAKI_SEO_STAYPOP_URL . 'assets/js/frontend.js', [], Kashiwazaki_SEO_StayPop::asset_ver('assets/js/frontend.js'), true);
        wp_enqueue_script('kashiwazaki-seo-staypop-admin', KASHIWAZAKI_SEO_STAYPOP_URL . 'assets/js/admin.js', ['jquery', 'kashiwazaki-seo-staypop-render'], Kashiwazaki_SEO_StayPop::asset_ver('assets/js/admin.js'), true);
        wp_localize_script('kashiwazaki-seo-staypop-admin', 'KashiwazakiSeoStayPopList', [
            'notShown' => __('表示されません', 'kashiwazaki-seo-staypop'),
        ]);
    }

    /* ---- 通知 ---- */

    /**
     * 画面遷移（リダイレクト）をまたいで出す通知を登録する
     *
     * @param string $message 本文
     * @param string $type    'updated'（成功）または 'error'
     */
    public static function queue_notice(string $message, string $type = 'updated'): void {
        set_transient(
            'kashiwazaki_staypop_notice_' . get_current_user_id(),
            ['message' => $message, 'type' => $type],
            60
        );
    }

    /**
     * 登録された通知を表示して消す
     */
    private function print_notice(): void {
        $notice = get_transient('kashiwazaki_staypop_notice_' . get_current_user_id());
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient('kashiwazaki_staypop_notice_' . get_current_user_id());
        $class = (($notice['type'] ?? 'updated') === 'error') ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html((string) $notice['message']) . '</p></div>';
    }

    /**
     * 一覧の「ゴミ箱へ」「元に戻す」「完全に削除」のあとの通知を登録する。
     * WordPress はこれらの操作のあと、元の画面（この画面）へ trashed=1 などを付けて戻す。
     */
    private function queue_core_action_notice(): void {
        $messages = [
            'trashed'   => __('バナーをゴミ箱に移動しました。「ゴミ箱」から元に戻せます。', 'kashiwazaki-seo-staypop'),
            'untrashed' => __('バナーをゴミ箱から戻しました。', 'kashiwazaki-seo-staypop'),
            'deleted'   => __('バナーを完全に削除しました。', 'kashiwazaki-seo-staypop'),
        ];
        foreach ($messages as $key => $message) {
            if (!empty($_GET[$key])) {
                self::queue_notice($message);
                return;
            }
        }
    }

    /**
     * 管理画面（指定タブ）へ戻る
     *
     * @param string $tab  タブ
     * @param array  $args 追加のクエリ
     */
    private function redirect_to_tab(string $tab, array $args = []): void {
        wp_safe_redirect(add_query_arg(array_merge(['page' => self::MENU_SLUG, 'tab' => $tab], $args), admin_url('admin.php')));
        exit;
    }

    /**
     * (i) の説明（編集画面と同じ形）
     *
     * @param string $text 説明
     * @return string
     */
    private function tip(string $text): string {
        return ' <span class="ksp-tip" tabindex="0" role="note" aria-label="' . esc_attr($text) . '" data-tip="' . esc_attr($text) . '">i</span>';
    }

    /* ---- 画面 ---- */

    /**
     * 管理画面（1ページ・タブ切り替え）
     */
    public function render_page(): void {
        $tabs = [];
        if (current_user_can('edit_posts')) {
            $tabs['banners'] = __('バナー一覧', 'kashiwazaki-seo-staypop');
        }
        if (current_user_can('manage_options')) {
            $tabs['stats']    = __('集計', 'kashiwazaki-seo-staypop');
            $tabs['settings'] = __('設定', 'kashiwazaki-seo-staypop');
        }
        if (empty($tabs)) {
            return;
        }
        $current = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'banners';
        if (!isset($tabs[$current])) {
            $current = array_key_first($tabs);
        }
        ?>
        <div class="wrap ksp-wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Kashiwazaki SEO StayPop', 'kashiwazaki-seo-staypop'); ?></h1>
            <?php if ($current === 'banners' && current_user_can('edit_posts')) : ?>
                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . Kashiwazaki_SEO_StayPop_Banners::POST_TYPE)); ?>" class="page-title-action"><?php esc_html_e('バナーを追加', 'kashiwazaki-seo-staypop'); ?></a>
            <?php endif; ?>
            <hr class="wp-header-end">
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG, 'tab' => $key], admin_url('admin.php'))); ?>" class="nav-tab <?php echo esc_attr($current === $key ? 'nav-tab-active' : ''); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php
            $this->queue_core_action_notice();
            $this->print_notice();
            ?>
            <div class="ksp-tab-body">
                <?php
                switch ($current) {
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'stats':
                        $this->render_stats_tab();
                        break;
                    default:
                        $this->render_banners_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * 掲載期間から見た状態（表示中・表示予定・掲載終了）
     *
     * @param array                              $c          設定
     * @param Kashiwazaki_SEO_StayPop_Conditions $conditions 条件判定（サイトでの表示と同じ判定を使う）
     * @return array ['state' => 'on'|'scheduled'|'ended', 'label' => 文言]
     */
    public static function schedule_state(array $c, Kashiwazaki_SEO_StayPop_Conditions $conditions): array {
        $tz    = wp_timezone();
        $parse = static function ($v) use ($tz) {
            return !empty($v) ? DateTime::createFromFormat('Y-m-d\TH:i', (string) $v, $tz) : false;
        };
        $start = $parse($c['schedule']['start'] ?? '');
        $end   = $parse($c['schedule']['end'] ?? '');
        if (!$conditions->in_schedule($c)) {
            if ($start && new DateTime('now', $tz) < $start) {
                return ['state' => 'scheduled', 'label' => sprintf(__('表示予定（%s から）', 'kashiwazaki-seo-staypop'), $start->format('n/j H:i'))];
            }
            return ['state' => 'ended', 'label' => __('掲載終了', 'kashiwazaki-seo-staypop')];
        }
        if ($end) {
            return ['state' => 'on', 'label' => sprintf(__('表示中（%s まで）', 'kashiwazaki-seo-staypop'), $end->format('n/j H:i'))];
        }
        return ['state' => 'on', 'label' => __('表示中', 'kashiwazaki-seo-staypop')];
    }

    /**
     * 掲載期間から見た状態の文言
     *
     * @param array                              $c          設定
     * @param Kashiwazaki_SEO_StayPop_Conditions $conditions 条件判定
     * @return string
     */
    public static function schedule_label(array $c, Kashiwazaki_SEO_StayPop_Conditions $conditions): string {
        return self::schedule_state($c, $conditions)['label'];
    }

    /**
     * 一覧の状態バッジ
     *
     * @param WP_Post $p 投稿
     * @param array   $c 設定
     * @return array [文言, 種類]
     */
    private function status_badge($p, array $c): array {
        if ($p->post_status === 'future') {
            return [__('公開予約', 'kashiwazaki-seo-staypop'), 'scheduled'];
        }
        if ($p->post_status !== 'publish') {
            return [__('下書き', 'kashiwazaki-seo-staypop'), 'draft'];
        }
        if (empty($c['enabled'])) {
            return [__('停止中', 'kashiwazaki-seo-staypop'), 'off'];
        }
        $st = self::schedule_state($c, $this->conditions);
        return [$st['label'], $st['state']];
    }

    /**
     * バナー一覧タブ
     */
    private function render_banners_tab(): void {
        if (!current_user_can('edit_posts')) {
            return;
        }
        $view     = (isset($_GET['view']) && sanitize_key(wp_unslash($_GET['view'])) === 'trash') ? 'trash' : 'all';
        $statuses = $view === 'trash' ? ['trash'] : ['publish', 'draft', 'private', 'pending', 'future'];
        $posts    = get_posts([
            'post_type'      => Kashiwazaki_SEO_StayPop_Banners::POST_TYPE,
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);
        $posts    = array_values(array_filter($posts, static function ($p) {
            return current_user_can('edit_post', $p->ID); // 編集できないバナーは出さない
        }));
        $counts   = wp_count_posts(Kashiwazaki_SEO_StayPop_Banners::POST_TYPE);
        $n_trash  = (int) ($counts->trash ?? 0);
        $n_all    = 0;
        foreach (['publish', 'draft', 'private', 'pending', 'future'] as $st) {
            $n_all += (int) ($counts->$st ?? 0);
        }
        $base     = add_query_arg(['page' => self::MENU_SLUG, 'tab' => 'banners'], admin_url('admin.php'));
        $formats  = Kashiwazaki_SEO_StayPop_Templates::list_formats();
        $totals   = current_user_can('manage_options') ? $this->stats->totals_by_banner(30) : [];
        ?>
        <ul class="subsubsub">
            <li><a href="<?php echo esc_url($base); ?>" class="<?php echo esc_attr($view === 'all' ? 'current' : ''); ?>"><?php esc_html_e('すべて', 'kashiwazaki-seo-staypop'); ?> <span class="count">(<?php echo esc_html((string) $n_all); ?>)</span></a><?php echo $n_trash > 0 ? ' |' : ''; ?></li>
            <?php if ($n_trash > 0) : ?>
                <li><a href="<?php echo esc_url(add_query_arg('view', 'trash', $base)); ?>" class="<?php echo esc_attr($view === 'trash' ? 'current' : ''); ?>"><?php esc_html_e('ゴミ箱', 'kashiwazaki-seo-staypop'); ?> <span class="count">(<?php echo esc_html((string) $n_trash); ?>)</span></a></li>
            <?php endif; ?>
        </ul>
        <table class="widefat striped ksp-list">
            <thead>
                <tr>
                    <th class="ksp-col-thumb"><span class="screen-reader-text"><?php esc_html_e('画像', 'kashiwazaki-seo-staypop'); ?></span></th>
                    <th><?php esc_html_e('バナー', 'kashiwazaki-seo-staypop'); ?></th>
                    <?php if ($view === 'all') : ?>
                        <th class="ksp-col-status"><?php esc_html_e('状態', 'kashiwazaki-seo-staypop'); ?></th>
                        <th><?php esc_html_e('表示のタイミング', 'kashiwazaki-seo-staypop'); ?></th>
                        <th class="ksp-col-format"><?php esc_html_e('表示形式', 'kashiwazaki-seo-staypop'); ?></th>
                        <?php if (current_user_can('manage_options')) : ?>
                            <th class="ksp-col-stats"><?php esc_html_e('直近30日', 'kashiwazaki-seo-staypop'); ?></th>
                        <?php endif; ?>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($posts)) : ?>
                <tr><td colspan="6" class="ksp-empty">
                    <?php if ($view === 'trash') : ?>
                        <?php esc_html_e('ゴミ箱は空です。', 'kashiwazaki-seo-staypop'); ?>
                    <?php else : ?>
                        <?php esc_html_e('まだバナーがありません。', 'kashiwazaki-seo-staypop'); ?>
                        <a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . Kashiwazaki_SEO_StayPop_Banners::POST_TYPE)); ?>" class="button button-primary"><?php esc_html_e('最初のバナーを作る', 'kashiwazaki-seo-staypop'); ?></a>
                    <?php endif; ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($posts as $p) : ?>
                <?php
                $c     = $this->banners->get_config($p->ID);
                $title = $p->post_title !== '' ? $p->post_title : __('（名前なし）', 'kashiwazaki-seo-staypop');
                $thumb = (string) ($c['content']['image_url'] ?? '');
                ?>
                <tr>
                    <td class="ksp-col-thumb">
                        <?php if ($thumb !== '') : ?>
                            <img src="<?php echo esc_url($thumb); ?>" alt="">
                        <?php else : ?>
                            <span class="ksp-thumb-none ksp-pic-<?php echo esc_attr((string) $c['format']); ?>" aria-hidden="true"><b></b></span>
                        <?php endif; ?>
                    </td>
                    <?php if ($view === 'trash') : ?>
                        <td>
                            <strong><?php echo esc_html($title); ?></strong>
                            <div class="ksp-actions">
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('post.php?post=' . $p->ID . '&action=untrash'), 'untrash-post_' . $p->ID)); ?>"><?php esc_html_e('元に戻す', 'kashiwazaki-seo-staypop'); ?></a>
                                | <a class="ksp-danger" href="<?php echo esc_url((string) get_delete_post_link($p->ID, '', true)); ?>" onclick="return confirm('<?php echo esc_js(__('このバナーを完全に削除します。元に戻せません。よろしいですか？', 'kashiwazaki-seo-staypop')); ?>');"><?php esc_html_e('完全に削除', 'kashiwazaki-seo-staypop'); ?></a>
                            </div>
                        </td>
                    <?php else : ?>
                        <?php
                        [$badge, $kind] = $this->status_badge($p, $c);
                        $edit   = (string) get_edit_post_link($p->ID);
                        $dup    = wp_nonce_url(admin_url('admin-post.php?action=kashiwazaki_staypop_duplicate&post=' . $p->ID), 'kashiwazaki_staypop_duplicate_' . $p->ID);
                        $toggle = wp_nonce_url(admin_url('admin-post.php?action=kashiwazaki_staypop_toggle&post=' . $p->ID), 'kashiwazaki_staypop_toggle_' . $p->ID);
                        $test   = Kashiwazaki_SEO_StayPop_Frontend::test_url($p->ID);
                        $row    = $totals[(string) $p->ID] ?? ['impression' => 0, 'click' => 0, 'conversion' => 0];
                        $imp    = (int) ($row['impression'] ?? 0);
                        ?>
                        <td>
                            <strong><a class="row-title" href="<?php echo esc_url($edit); ?>"><?php echo esc_html($title); ?></a></strong>
                            <div class="ksp-actions">
                                <a href="<?php echo esc_url($edit); ?>"><?php esc_html_e('編集', 'kashiwazaki-seo-staypop'); ?></a>
                                | <a href="<?php echo esc_url($dup); ?>"><?php esc_html_e('複製', 'kashiwazaki-seo-staypop'); ?></a>
                                | <a href="<?php echo esc_url($test); ?>" target="_blank" rel="noopener"><?php esc_html_e('テスト表示', 'kashiwazaki-seo-staypop'); ?></a>
                                | <a class="ksp-danger" href="<?php echo esc_url((string) get_delete_post_link($p->ID)); ?>"><?php esc_html_e('ゴミ箱へ', 'kashiwazaki-seo-staypop'); ?></a>
                            </div>
                        </td>
                        <td class="ksp-col-status">
                            <span class="ksp-badge ksp-badge-<?php echo esc_attr($kind); ?>"><?php echo esc_html($badge); ?></span>
                            <?php if ($p->post_status === 'publish') : ?>
                                <a class="button button-small ksp-toggle" href="<?php echo esc_url($toggle); ?>"><?php echo empty($c['enabled']) ? esc_html__('表示する', 'kashiwazaki-seo-staypop') : esc_html__('停止する', 'kashiwazaki-seo-staypop'); ?></a>
                            <?php else : ?>
                                <a class="button button-small" href="<?php echo esc_url($edit); ?>"><?php esc_html_e('編集して公開', 'kashiwazaki-seo-staypop'); ?></a>
                            <?php endif; ?>
                        </td>
                        <td class="ksp-col-timing" data-triggers="<?php echo esc_attr((string) wp_json_encode(Kashiwazaki_SEO_StayPop_Frontend::export_config($p->ID, $c)['triggers'])); ?>">
                            <div><span class="ksp-summary-label"><?php esc_html_e('PC', 'kashiwazaki-seo-staypop'); ?></span> <span data-summary="pc"></span></div>
                            <div><span class="ksp-summary-label"><?php esc_html_e('スマホ', 'kashiwazaki-seo-staypop'); ?></span> <span data-summary="mobile"></span></div>
                        </td>
                        <td class="ksp-col-format"><?php echo esc_html($formats[$c['format']] ?? (string) $c['format']); ?></td>
                        <?php if (current_user_can('manage_options')) : ?>
                            <td class="ksp-col-stats">
                                <?php if ($imp > 0) : ?>
                                    <?php
                                    echo esc_html(sprintf(
                                        /* translators: 1: 表示回数 2: クリック率 3: 成果 */
                                        __('表示 %1$s ・ クリック率 %2$s ・ 成果 %3$s', 'kashiwazaki-seo-staypop'),
                                        number_format_i18n($imp),
                                        $this->rate((int) ($row['click'] ?? 0), $imp),
                                        number_format_i18n((int) ($row['conversion'] ?? 0))
                                    ));
                                    ?>
                                <?php else : ?>
                                    <span class="ksp-muted"><?php esc_html_e('まだ表示されていません', 'kashiwazaki-seo-staypop'); ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * 割合（%）の文字列
     *
     * @param int $num 分子
     * @param int $den 分母
     * @return string
     */
    private function rate(int $num, int $den): string {
        return $den > 0 ? number_format_i18n($num / $den * 100, 1) . '%' : '—';
    }

    /* ---- 集計 ---- */

    /**
     * 集計タブ
     */
    private function render_stats_tab(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        if (!in_array($days, self::PERIODS, true)) {
            $days = 30;
        }
        $focus     = isset($_GET['banner']) ? intval($_GET['banner']) : 0;
        $by_banner = $this->stats->totals_by_banner($days);
        $by_device = $this->stats->totals_by_device($days);
        $by_page   = $this->stats->totals_by_page($days, 20);
        $all       = get_posts([
            'post_type'      => Kashiwazaki_SEO_StayPop_Banners::POST_TYPE,
            'post_status'    => ['publish', 'draft', 'private', 'pending', 'future'],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
        $names = [];
        foreach ($all as $p) {
            $names[(string) $p->ID] = $p->post_title !== '' ? $p->post_title : ('#' . $p->ID);
        }
        // 表示されていないバナーも 0 として並べる
        foreach (array_keys($names) as $id) {
            if (!isset($by_banner[$id])) {
                $by_banner[$id] = ['impression' => 0, 'click' => 0, 'close' => 0, 'conversion' => 0, 'total' => 0];
            }
        }
        uasort($by_banner, static function ($a, $b) {
            return ($b['impression'] ?? 0) <=> ($a['impression'] ?? 0);
        });
        if (!$this->settings->is_analytics_enabled()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('いまは計測をしていません（「設定」タブで計測をオンにできます）。', 'kashiwazaki-seo-staypop') . '</p></div>';
        }
        ?>
        <form method="get" class="ksp-period">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
            <input type="hidden" name="tab" value="stats">
            <?php if ($focus) : ?><input type="hidden" name="banner" value="<?php echo esc_attr((string) $focus); ?>"><?php endif; ?>
            <label for="ksp-days"><?php esc_html_e('期間', 'kashiwazaki-seo-staypop'); ?></label>
            <select id="ksp-days" name="days" onchange="this.form.submit()">
                <?php foreach (self::PERIODS as $d) : ?>
                    <option value="<?php echo esc_attr((string) $d); ?>" <?php selected($days, $d); ?>><?php echo esc_html(sprintf(__('直近 %d 日', 'kashiwazaki-seo-staypop'), $d)); ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="button"><?php esc_html_e('表示', 'kashiwazaki-seo-staypop'); ?></button></noscript>
        </form>

        <h2><?php esc_html_e('バナー別', 'kashiwazaki-seo-staypop'); ?></h2>
        <?php $this->render_table($by_banner, 'banner', $names, $focus); ?>

        <?php
        $ab_rows = '';
        foreach ($all as $p) {
            $c = $this->banners->get_config($p->ID);
            if (empty($c['ab_test']['enabled'])) {
                continue;
            }
            ob_start();
            echo '<h3 id="banner-' . esc_attr((string) $p->ID) . '">' . esc_html($names[(string) $p->ID] ?? ('#' . $p->ID)) . '</h3>';
            $variants = $this->stats->totals_by_variant($p->ID, $days);
            foreach (['A', 'B'] as $label) {
                if (!isset($variants[$label])) {
                    $variants[$label] = ['impression' => 0, 'click' => 0, 'close' => 0, 'conversion' => 0, 'total' => 0];
                }
            }
            unset($variants['']);
            ksort($variants);
            $this->render_table($variants, 'variant');
            $ab_rows .= (string) ob_get_clean();
        }
        ?>
        <h2><?php esc_html_e('A/Bテスト', 'kashiwazaki-seo-staypop'); ?></h2>
        <?php if ($ab_rows !== '') : ?>
            <?php echo $ab_rows; // phpcs:ignore WordPress.Security.EscapeOutput -- render_table 内でエスケープ済み ?>
        <?php else : ?>
            <p class="ksp-muted"><?php esc_html_e('A/Bテストをしているバナーはありません（バナーの編集画面の「成果・A/Bテスト」で始められます）。', 'kashiwazaki-seo-staypop'); ?></p>
        <?php endif; ?>

        <h2><?php esc_html_e('端末別', 'kashiwazaki-seo-staypop'); ?></h2>
        <?php $this->render_table($by_device, 'device'); ?>

        <h2><?php esc_html_e('ページ別（表示の多い順に20件）', 'kashiwazaki-seo-staypop'); ?></h2>
        <?php $this->render_table($by_page, 'page'); ?>

        <div class="ksp-danger-zone">
            <h2><?php esc_html_e('ログの管理', 'kashiwazaki-seo-staypop'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(__('%d 日を過ぎたログは毎日自動で削除します（日数は「設定」タブで変えられます）。', 'kashiwazaki-seo-staypop'), (int) $this->settings->get('stats_retention_days'))); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="kashiwazaki_staypop_stats_action">
                <?php wp_nonce_field('kashiwazaki_staypop_stats_action'); ?>
                <button class="button ksp-button-danger" name="do" value="clear" onclick="return confirm('<?php echo esc_js(__('すべてのログを削除します。集計は0に戻り、元に戻せません。よろしいですか？', 'kashiwazaki-seo-staypop')); ?>');"><?php esc_html_e('すべてのログを削除', 'kashiwazaki-seo-staypop'); ?></button>
            </form>
        </div>
        <?php
    }

    /**
     * 集計テーブルを描画
     *
     * @param array  $data  集計
     * @param string $type  種別（banner/device/page/variant）
     * @param array  $names バナー名（banner のとき）
     * @param int    $focus 強調するバナー
     */
    private function render_table(array $data, string $type, array $names = [], int $focus = 0): void {
        $heads = [
            'banner'  => __('バナー', 'kashiwazaki-seo-staypop'),
            'device'  => __('端末', 'kashiwazaki-seo-staypop'),
            'page'    => __('ページ', 'kashiwazaki-seo-staypop'),
            'variant' => __('パターン', 'kashiwazaki-seo-staypop'),
        ];
        echo '<table class="widefat striped ksp-stats"><thead><tr>';
        echo '<th>' . esc_html($heads[$type] ?? '') . '</th>';
        echo '<th class="num">' . esc_html__('表示', 'kashiwazaki-seo-staypop') . '</th>';
        echo '<th class="num">' . esc_html__('クリック', 'kashiwazaki-seo-staypop') . '</th>';
        echo '<th class="num">' . esc_html__('クリック率', 'kashiwazaki-seo-staypop') . $this->tip(__('クリック ÷ 表示', 'kashiwazaki-seo-staypop')) . '</th>'; // phpcs:ignore
        echo '<th class="num">' . esc_html__('閉じた', 'kashiwazaki-seo-staypop') . '</th>';
        echo '<th class="num">' . esc_html__('成果', 'kashiwazaki-seo-staypop') . '</th>';
        echo '<th class="num">' . esc_html__('成果率', 'kashiwazaki-seo-staypop') . $this->tip(__('成果 ÷ 表示', 'kashiwazaki-seo-staypop')) . '</th>'; // phpcs:ignore
        echo '</tr></thead><tbody>';
        if (empty($data)) {
            echo '<tr><td colspan="7" class="ksp-muted">' . esc_html__('この期間のデータはありません。', 'kashiwazaki-seo-staypop') . '</td></tr>';
        }
        $devices = ['pc' => __('PC', 'kashiwazaki-seo-staypop'), 'mobile' => __('スマホ', 'kashiwazaki-seo-staypop')];
        foreach ($data as $key => $row) {
            $key   = (string) $key;
            $label = $key;
            if ($type === 'banner') {
                $label = $names[$key] ?? sprintf(__('削除したバナー #%s', 'kashiwazaki-seo-staypop'), $key);
            } elseif ($type === 'device') {
                $label = $devices[$key] ?? __('不明', 'kashiwazaki-seo-staypop');
            } elseif ($type === 'variant') {
                $label = sprintf(__('パターン %s', 'kashiwazaki-seo-staypop'), $key);
            } elseif ($key === '') {
                $label = __('不明', 'kashiwazaki-seo-staypop');
            }
            $imp = (int) ($row['impression'] ?? 0);
            $clk = (int) ($row['click'] ?? 0);
            $cv  = (int) ($row['conversion'] ?? 0);
            echo '<tr' . ($focus && $type === 'banner' && (int) $key === $focus ? ' class="ksp-focus"' : '') . '>';
            if ($type === 'banner' && isset($names[$key])) {
                echo '<td><a href="' . esc_url((string) get_edit_post_link((int) $key)) . '">' . esc_html($label) . '</a></td>';
            } else {
                echo '<td>' . esc_html($label) . '</td>';
            }
            echo '<td class="num">' . esc_html(number_format_i18n($imp)) . '</td>';
            echo '<td class="num">' . esc_html(number_format_i18n($clk)) . '</td>';
            echo '<td class="num">' . esc_html($this->rate($clk, $imp)) . '</td>';
            echo '<td class="num">' . esc_html(number_format_i18n((int) ($row['close'] ?? 0))) . '</td>';
            echo '<td class="num">' . esc_html(number_format_i18n($cv)) . '</td>';
            echo '<td class="num">' . esc_html($this->rate($cv, $imp)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * 集計の操作（全削除）
     */
    public function handle_stats_action(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('権限がありません。', 'kashiwazaki-seo-staypop'), '', ['response' => 403]);
        }
        check_admin_referer('kashiwazaki_staypop_stats_action');
        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        if ($do === 'clear') {
            $n = $this->stats->clear_all();
            self::queue_notice(sprintf(__('ログを %d 件削除しました。', 'kashiwazaki-seo-staypop'), $n));
        } elseif ($do === 'prune') {
            $n = $this->stats->prune((int) $this->settings->get('stats_retention_days'));
            self::queue_notice(sprintf(__('古いログを %d 件削除しました。', 'kashiwazaki-seo-staypop'), $n));
        } else {
            self::queue_notice(__('操作が不明です。', 'kashiwazaki-seo-staypop'), 'error');
        }
        $this->redirect_to_tab('stats');
    }

    /* ---- 設定 ---- */

    /**
     * 設定タブ
     */
    private function render_settings_tab(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = $this->settings->get_all();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ksp-settings">
            <input type="hidden" name="action" value="kashiwazaki_staypop_save_settings">
            <?php wp_nonce_field('kashiwazaki_staypop_save_settings'); ?>
            <h2><?php esc_html_e('計測', 'kashiwazaki-seo-staypop'); ?></h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('計測', 'kashiwazaki-seo-staypop'); ?></th>
                        <td><label><input type="checkbox" name="kashiwazaki_seo_staypop_settings[analytics_enabled]" value="1" <?php checked(!empty($s['analytics_enabled'])); ?>> <?php esc_html_e('表示・クリック・閉じる・成果の回数を記録する', 'kashiwazaki-seo-staypop'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('訪問者の同意', 'kashiwazaki-seo-staypop'); ?><?php echo $this->tip(__('Cookie の同意バナーを使っている場合は「同意した人だけ」を選び、同意の結果が入る Cookie の名前を入れてください。値が空・false・0・no・reject などのときは同意していないものとして記録しません。名前が空のままだと、だれも記録しません。', 'kashiwazaki-seo-staypop')); // phpcs:ignore ?></th>
                        <td>
                            <fieldset class="ksp-radios">
                                <label><input type="radio" name="kashiwazaki_seo_staypop_settings[consent_mode]" value="always" <?php checked($s['consent_mode'], 'always'); ?>> <?php esc_html_e('同意を確認せずに記録する', 'kashiwazaki-seo-staypop'); ?></label>
                                <label><input type="radio" name="kashiwazaki_seo_staypop_settings[consent_mode]" value="require" <?php checked($s['consent_mode'], 'require'); ?>> <?php esc_html_e('同意した人だけ記録する', 'kashiwazaki-seo-staypop'); ?></label>
                            </fieldset>
                            <p data-show-if-setting="consent_mode=require">
                                <label for="ksp-cookie"><?php esc_html_e('同意の結果が入る Cookie の名前', 'kashiwazaki-seo-staypop'); ?></label><br>
                                <input type="text" id="ksp-cookie" class="regular-text code" name="kashiwazaki_seo_staypop_settings[consent_cookie_name]" value="<?php echo esc_attr((string) $s['consent_cookie_name']); ?>" placeholder="cookie_notice_accepted">
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('トラッキング拒否', 'kashiwazaki-seo-staypop'); ?></th>
                        <td><label><input type="checkbox" name="kashiwazaki_seo_staypop_settings[respect_dnt]" value="1" <?php checked(!empty($s['respect_dnt'])); ?>> <?php esc_html_e('ブラウザで「トラッキング拒否（Do Not Track）」にしている人は記録しない', 'kashiwazaki-seo-staypop'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ksp-retention"><?php esc_html_e('ログを残す期間', 'kashiwazaki-seo-staypop'); ?></label></th>
                        <td><input type="number" id="ksp-retention" class="small-text" name="kashiwazaki_seo_staypop_settings[stats_retention_days]" value="<?php echo esc_attr((string) $s['stats_retention_days']); ?>" min="1" max="3650"> <?php esc_html_e('日（過ぎたログは毎日自動で削除します）', 'kashiwazaki-seo-staypop'); ?></td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(__('設定を保存', 'kashiwazaki-seo-staypop')); ?>
        </form>

        <hr>
        <h2><?php esc_html_e('移行・バックアップ', 'kashiwazaki-seo-staypop'); ?></h2>
        <p class="description"><?php esc_html_e('すべてのバナーと、この画面の設定をファイルに書き出し、別のサイトで読み込めます。', 'kashiwazaki-seo-staypop'); ?></p>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('書き出し', 'kashiwazaki-seo-staypop'); ?></th>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="kashiwazaki_staypop_export">
                            <?php wp_nonce_field('kashiwazaki_staypop_export'); ?>
                            <button class="button"><?php esc_html_e('ファイルをダウンロード', 'kashiwazaki-seo-staypop'); ?></button>
                        </form>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('読み込み', 'kashiwazaki-seo-staypop'); ?><?php echo $this->tip(__('読み込んだバナーは「停止中」で追加されます（今あるバナーは消えません）。内容を確かめてから、一覧の「表示する」で表示を始めてください。画像は書き出し元の URL のまま使います。', 'kashiwazaki-seo-staypop')); // phpcs:ignore ?></th>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="kashiwazaki_staypop_import">
                            <?php wp_nonce_field('kashiwazaki_staypop_import'); ?>
                            <p><input type="file" name="kashiwazaki_staypop_import_file" accept=".json,application/json" required></p>
                            <p><label><input type="checkbox" name="kashiwazaki_staypop_import_settings" value="1"> <?php esc_html_e('この画面の設定（計測など）も読み込む', 'kashiwazaki-seo-staypop'); ?></label></p>
                            <button class="button"><?php esc_html_e('読み込む', 'kashiwazaki-seo-staypop'); ?></button>
                        </form>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * 設定の保存
     */
    public function handle_settings_save(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('権限がありません。', 'kashiwazaki-seo-staypop'), '', ['response' => 403]);
        }
        check_admin_referer('kashiwazaki_staypop_save_settings');
        $raw = isset($_POST['kashiwazaki_seo_staypop_settings']) ? wp_unslash($_POST['kashiwazaki_seo_staypop_settings']) : [];
        $raw = is_array($raw) ? $raw : [];
        $this->settings->save($raw);
        if (($raw['consent_mode'] ?? '') === 'require' && trim((string) ($raw['consent_cookie_name'] ?? '')) === '') {
            self::queue_notice(__('保存しました。ただし Cookie の名前が空のため、だれも記録されません。', 'kashiwazaki-seo-staypop'), 'error');
        } else {
            self::queue_notice(__('設定を保存しました。', 'kashiwazaki-seo-staypop'));
        }
        $this->redirect_to_tab('settings');
    }

    /* ---- エクスポート / インポート ---- */

    /**
     * エクスポート
     */
    public function handle_export(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('権限がありません。', 'kashiwazaki-seo-staypop'), '', ['response' => 403]);
        }
        check_admin_referer('kashiwazaki_staypop_export');
        $payload = [
            'plugin'   => 'kashiwazaki-seo-staypop',
            'version'  => KASHIWAZAKI_SEO_STAYPOP_VERSION,
            'settings' => $this->settings->get_all(),
            'banners'  => [],
        ];
        $all = get_posts(['post_type' => Kashiwazaki_SEO_StayPop_Banners::POST_TYPE, 'post_status' => ['publish', 'draft', 'private', 'pending', 'future'], 'posts_per_page' => -1, 'no_found_rows' => true]);
        foreach ($all as $p) {
            $payload['banners'][] = [
                'title'  => $p->post_title,
                'status' => $p->post_status,
                'config' => $this->banners->get_config($p->ID),
            ];
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="kashiwazaki-seo-staypop-' . gmdate('Ymd') . '.json"');
        echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * 設定の中の画像の添付ID を外し、URL だけを残す（読み込み用）
     *
     * @param array $config 設定
     * @return array
     */
    private function drop_attachment_ids(array $config): array {
        $config['content']['image_id'] = 0;
        foreach (['pc', 'mobile'] as $device) {
            if (isset($config['device'][$device]) && is_array($config['device'][$device])) {
                $config['device'][$device]['image_id'] = 0;
            }
        }
        if (isset($config['ab_test']['variants']) && is_array($config['ab_test']['variants'])) {
            foreach ($config['ab_test']['variants'] as $i => $v) {
                if (is_array($v)) {
                    $config['ab_test']['variants'][$i]['image_id'] = 0;
                }
            }
        }
        return $config;
    }

    /**
     * インポート
     */
    public function handle_import(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('権限がありません。', 'kashiwazaki-seo-staypop'), '', ['response' => 403]);
        }
        check_admin_referer('kashiwazaki_staypop_import');
        $json = '';
        $file = $_FILES['kashiwazaki_staypop_import_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if (is_array($file) && isset($file['tmp_name'], $file['error']) && (int) $file['error'] === UPLOAD_ERR_OK
            && is_uploaded_file((string) $file['tmp_name']) && (int) ($file['size'] ?? 0) <= 5 * MB_IN_BYTES) {
            $json = (string) file_get_contents((string) $file['tmp_name']); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['banners']) || !is_array($data['banners'])) {
            self::queue_notice(__('読み込めませんでした。このプラグインで書き出したファイルを選んでください。', 'kashiwazaki-seo-staypop'), 'error');
            $this->redirect_to_tab('settings');
        }
        $count = 0;
        foreach ($data['banners'] as $banner) {
            if (!is_array($banner) || empty($banner['config']) || !is_array($banner['config'])) {
                continue;
            }
            // 先に設定を検証・整形してから投稿を作成する（不正な設定で空の投稿を作らない）
            $config = $this->banners->sanitize_config($banner['config']);
            // 読み込んだバナーは停止の状態で作る（確かめる前に訪問者に表示しない）
            $config['enabled'] = false;
            // 画像は URL で持つ。添付ID は書き出し元のサイトのもので、このサイトでは別の画像を指すことがある
            $config = $this->drop_attachment_ids($config);
            $title  = sanitize_text_field((string) ($banner['title'] ?? ''));
            if ($title === '') {
                $title = __('読み込んだバナー', 'kashiwazaki-seo-staypop');
            }
            $new_id = wp_insert_post([
                'post_type'   => Kashiwazaki_SEO_StayPop_Banners::POST_TYPE,
                'post_status' => ($banner['status'] ?? 'draft') === 'publish' ? 'publish' : 'draft',
                'post_title'  => $title,
            ]);
            if ($new_id && !is_wp_error($new_id)) {
                update_post_meta($new_id, Kashiwazaki_SEO_StayPop_Banners::META_KEY, wp_slash($config));
                $count++;
            }
        }
        if (!empty($_POST['kashiwazaki_staypop_import_settings']) && !empty($data['settings']) && is_array($data['settings'])) {
            $this->settings->save($data['settings']);
        }
        self::queue_notice(sprintf(__('バナーを %d 件読み込みました（停止中）。内容を確かめてから「表示する」にしてください。', 'kashiwazaki-seo-staypop'), $count));
        $this->redirect_to_tab('banners');
    }
}
