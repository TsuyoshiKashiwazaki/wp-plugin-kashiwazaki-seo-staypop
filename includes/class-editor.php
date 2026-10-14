<?php
/**
 * バナーの編集画面
 *
 * タイトルのすぐ下に、畳んだり動かしたりできない1枚の編集パネルを出す。
 * 左に設定（タブで切り替え）、右にライブプレビュー。
 *
 * @package Kashiwazaki_SEO_StayPop
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 編集画面クラス
 */
class Kashiwazaki_SEO_StayPop_Editor {

    /**
     * バナー
     *
     * @var Kashiwazaki_SEO_StayPop_Banners
     */
    private Kashiwazaki_SEO_StayPop_Banners $banners;

    /**
     * 条件判定（掲載期間の判定に使う）
     *
     * @var Kashiwazaki_SEO_StayPop_Conditions
     */
    private Kashiwazaki_SEO_StayPop_Conditions $conditions;

    /**
     * 入力欄の id 連番
     *
     * @var int
     */
    private int $seq = 0;

    /**
     * コンストラクタ
     *
     * @param Kashiwazaki_SEO_StayPop_Banners    $banners    バナー
     * @param Kashiwazaki_SEO_StayPop_Conditions $conditions 条件判定
     */
    public function __construct(Kashiwazaki_SEO_StayPop_Banners $banners, Kashiwazaki_SEO_StayPop_Conditions $conditions) {
        $this->banners    = $banners;
        $this->conditions = $conditions;

        add_action('edit_form_after_title', [$this, 'render']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('enter_title_here', [$this, 'title_placeholder'], 10, 2);
        add_filter('post_updated_messages', [$this, 'updated_messages']);
        add_filter('parent_file', [$this, 'parent_file']);
        add_filter('submenu_file', [$this, 'submenu_file'], 10, 2);
        add_action('load-edit.php', [$this, 'redirect_core_list']);
        add_action('wp_ajax_kashiwazaki_staypop_search_posts', [$this, 'ajax_search_posts']);
        // ゴミ箱から戻したときは、捨てる前の状態（公開など）に戻す（標準は下書きになる）
        add_filter('wp_untrash_post_status', [$this, 'untrash_status'], 10, 3);
    }

    /**
     * いまの画面がバナーの編集画面か
     *
     * @return bool
     */
    private function is_banner_screen(): bool {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && $screen->post_type === Kashiwazaki_SEO_StayPop_Banners::POST_TYPE && $screen->base === 'post';
    }

    /* ---- 画面まわり ---- */

    /**
     * タイトル欄のプレースホルダー
     *
     * @param string  $text 既定
     * @param WP_Post $post 投稿
     * @return string
     */
    public function title_placeholder($text, $post) {
        if ($post && $post->post_type === Kashiwazaki_SEO_StayPop_Banners::POST_TYPE) {
            return __('バナーの名前（管理用。サイトには表示されません）', 'kashiwazaki-seo-staypop');
        }
        return $text;
    }

    /**
     * 保存したときのメッセージ（「投稿を表示」などのリンクは出さない）
     *
     * @param array $messages メッセージ
     * @return array
     */
    public function updated_messages($messages) {
        $saved = __('バナーを保存しました。', 'kashiwazaki-seo-staypop');
        $messages[Kashiwazaki_SEO_StayPop_Banners::POST_TYPE] = [
            0  => '',
            1  => $saved,
            2  => $saved,
            3  => $saved,
            4  => $saved,
            5  => false,
            6  => $saved,
            7  => $saved,
            8  => $saved,
            9  => __('バナーを予約しました。', 'kashiwazaki-seo-staypop'),
            10 => __('バナーを下書きとして保存しました。下書きのあいだはサイトに表示されません。', 'kashiwazaki-seo-staypop'),
        ];
        return $messages;
    }

    /**
     * バナーの編集中も、左メニューの「Kashiwazaki SEO StayPop」を選択中にする
     *
     * @param string $parent_file 親メニュー
     * @return string
     */
    public function parent_file($parent_file) {
        global $typenow;
        if ($typenow === Kashiwazaki_SEO_StayPop_Banners::POST_TYPE) {
            return Kashiwazaki_SEO_StayPop_Admin::MENU_SLUG;
        }
        return $parent_file;
    }

    /**
     * 選択中のサブメニュー
     *
     * @param string $submenu_file サブメニュー
     * @param string $parent_file  親メニュー
     * @return string
     */
    public function submenu_file($submenu_file, $parent_file) {
        global $typenow;
        if ($typenow === Kashiwazaki_SEO_StayPop_Banners::POST_TYPE) {
            return Kashiwazaki_SEO_StayPop_Admin::MENU_SLUG;
        }
        return $submenu_file;
    }

    /**
     * 標準の一覧画面（edit.php）は使わず、タブの画面へ送る。
     * ゴミ箱への移動・復元・削除のあとに標準の一覧へ戻されたときも、ここで送り直す。
     */
    public function redirect_core_list(): void {
        global $typenow;
        if ($typenow !== Kashiwazaki_SEO_StayPop_Banners::POST_TYPE) {
            return;
        }
        $args = ['page' => Kashiwazaki_SEO_StayPop_Admin::MENU_SLUG, 'tab' => 'banners'];
        if (isset($_GET['post_status']) && sanitize_key(wp_unslash($_GET['post_status'])) === 'trash') {
            $args['view'] = 'trash';
        }
        if (!empty($_GET['trashed'])) {
            Kashiwazaki_SEO_StayPop_Admin::queue_notice(__('バナーをゴミ箱に移動しました。「ゴミ箱」から元に戻せます。', 'kashiwazaki-seo-staypop'));
        } elseif (!empty($_GET['untrashed'])) {
            Kashiwazaki_SEO_StayPop_Admin::queue_notice(__('バナーをゴミ箱から戻しました。', 'kashiwazaki-seo-staypop'));
        } elseif (!empty($_GET['deleted'])) {
            Kashiwazaki_SEO_StayPop_Admin::queue_notice(__('バナーを完全に削除しました。', 'kashiwazaki-seo-staypop'));
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * ゴミ箱から戻すときの状態
     *
     * @param string $new_status      既定（draft）
     * @param int    $post_id         投稿ID
     * @param string $previous_status 捨てる前の状態
     * @return string
     */
    public function untrash_status($new_status, $post_id, $previous_status) {
        if (get_post_type($post_id) === Kashiwazaki_SEO_StayPop_Banners::POST_TYPE && $previous_status) {
            return $previous_status;
        }
        return $new_status;
    }

    /**
     * 投稿・固定ページの検索（表示ページ・除外ページの指定に使う）
     */
    public function ajax_search_posts(): void {
        check_ajax_referer('kashiwazaki_staypop_search', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        $q    = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $args = [
            'post_type'      => ['post', 'page'],
            'post_status'    => ['publish', 'future', 'private'],
            'perm'           => 'readable', // 非公開の記事は、読める権限のある人にだけ出す
            'posts_per_page' => 20,
            'no_found_rows'  => true,
        ];
        if ($q !== '') {
            if (ctype_digit($q)) {
                $args['p'] = (int) $q;
            } else {
                $args['s'] = $q;
            }
        }
        $query = new WP_Query($args);
        $out   = [];
        foreach ($query->posts as $p) {
            // 予約投稿・非公開の記事は、その記事を読める人にだけ出す（perm=readable は予約投稿を絞らない）
            if (!current_user_can('read_post', $p->ID)) {
                continue;
            }
            $out[] = $this->post_item($p);
        }
        wp_send_json_success($out);
    }

    /**
     * 検索結果・選択済みの1件
     *
     * @param WP_Post $p 投稿
     * @return array
     */
    private function post_item($p): array {
        return [
            'id'    => (int) $p->ID,
            'type'  => $p->post_type === 'page' ? 'page' : 'post',
            'label' => $p->post_type === 'page' ? __('固定ページ', 'kashiwazaki-seo-staypop') : __('投稿', 'kashiwazaki-seo-staypop'),
            'title' => $p->post_title !== '' ? $p->post_title : __('（タイトルなし）', 'kashiwazaki-seo-staypop'),
        ];
    }

    /**
     * 編集画面の CSS/JS
     *
     * @param string $hook 画面
     */
    public function enqueue($hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || !$this->is_banner_screen()) {
            return;
        }
        $post = get_post();
        wp_enqueue_media();
        wp_enqueue_style('kashiwazaki-seo-staypop-front', KASHIWAZAKI_SEO_STAYPOP_URL . 'assets/css/frontend.css', [], Kashiwazaki_SEO_StayPop::asset_ver('assets/css/frontend.css'));
        wp_enqueue_style('kashiwazaki-seo-staypop-admin', KASHIWAZAKI_SEO_STAYPOP_URL . 'assets/css/admin.css', ['kashiwazaki-seo-staypop-front'], Kashiwazaki_SEO_StayPop::asset_ver('assets/css/admin.css'));
        // フロントの描画処理（プレビューに使う。Data が無いので発火・計測はしない）
        wp_enqueue_script('kashiwazaki-seo-staypop-render', KASHIWAZAKI_SEO_STAYPOP_URL . 'assets/js/frontend.js', [], Kashiwazaki_SEO_StayPop::asset_ver('assets/js/frontend.js'), true);
        wp_enqueue_script('kashiwazaki-seo-staypop-admin', KASHIWAZAKI_SEO_STAYPOP_URL . 'assets/js/admin.js', ['jquery', 'kashiwazaki-seo-staypop-render'], Kashiwazaki_SEO_StayPop::asset_ver('assets/js/admin.js'), true);

        $presets = [];
        foreach (array_keys(Kashiwazaki_SEO_StayPop_Templates::list_templates()) as $key) {
            $presets[$key] = Kashiwazaki_SEO_StayPop_Templates::get_template_preset($key);
        }
        $test_url = '';
        if ($post && $post->post_status !== 'auto-draft') {
            $test_url = Kashiwazaki_SEO_StayPop_Frontend::test_url((int) $post->ID);
        }
        wp_localize_script('kashiwazaki-seo-staypop-admin', 'KashiwazakiSeoStayPopEditor', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'searchNonce' => wp_create_nonce('kashiwazaki_staypop_search'),
            'presets'     => $presets,
            'testUrl'     => $test_url,
            'i18n'        => [
                'selectImage'    => __('画像を選ぶ', 'kashiwazaki-seo-staypop'),
                'useImage'       => __('この画像を使う', 'kashiwazaki-seo-staypop'),
                'presetConfirm'  => __('見出し・説明文・ボタンの文言を、このテンプレートの文言に置き換えます。よろしいですか？', 'kashiwazaki-seo-staypop'),
                'noResult'       => __('見つかりませんでした', 'kashiwazaki-seo-staypop'),
                'searching'      => __('検索中…', 'kashiwazaki-seo-staypop'),
                'remove'         => __('外す', 'kashiwazaki-seo-staypop'),
                'notShown'       => __('表示されません（この端末で使える条件がありません）', 'kashiwazaki-seo-staypop'),
                'unsaved'        => __('変更がまだ保存されていません。テスト表示は保存した内容で行います。', 'kashiwazaki-seo-staypop'),
                'sampleHeading'  => __('見出し', 'kashiwazaki-seo-staypop'),
                'fullClose'      => __('閉じる（Esc）', 'kashiwazaki-seo-staypop'),
                'fullNote'       => __('実物大のプレビューです。ボタンやリンクは動きません。', 'kashiwazaki-seo-staypop'),
            ],
        ]);
    }

    /* ---- 入力部品 ---- */

    /**
     * 入力名（FIELD[a][b]）
     *
     * @param string ...$keys キー
     * @return string
     */
    private function n(string ...$keys): string {
        return Kashiwazaki_SEO_StayPop_Banners::FIELD . '[' . implode('][', $keys) . ']';
    }

    /**
     * 新しい要素 id
     *
     * @return string
     */
    private function id(): string {
        $this->seq++;
        return 'ksp-f' . $this->seq;
    }

    /**
     * 説明を (i) にまとめる（全項目で同じ形）
     *
     * @param string $text 説明
     * @return string
     */
    private function tip(string $text): string {
        if ($text === '') {
            return '';
        }
        return ' <span class="ksp-tip" tabindex="0" role="note" aria-label="' . esc_attr($text) . '" data-tip="' . esc_attr($text) . '">i</span>';
    }

    /**
     * 表の1行
     *
     * @param string $label ラベル
     * @param string $field 入力部品（HTML）
     * @param string $tip   (i) の説明
     * @param string $for   label の for
     * @param string $attrs tr に付ける属性（data-show-if など）
     */
    private function row(string $label, string $field, string $tip = '', string $for = '', string $attrs = ''): void {
        echo '<tr' . $attrs . '><th scope="row">';
        if ($for !== '') {
            echo '<label for="' . esc_attr($for) . '">' . esc_html($label) . '</label>';
        } else {
            echo esc_html($label);
        }
        echo $this->tip($tip) . '</th><td>' . $field . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- 部品側でエスケープ済み
    }

    /**
     * チェックボックス（未チェックでも 0 が送られる）
     *
     * @param string $name    入力名
     * @param bool   $checked 値
     * @param string $label   文言
     * @param string $attrs   追加属性
     * @return string
     */
    private function checkbox(string $name, bool $checked, string $label, string $attrs = ''): string {
        return '<input type="hidden" name="' . esc_attr($name) . '" value="0">'
            . '<label class="ksp-check"><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . $attrs . '> ' . esc_html($label) . '</label>';
    }

    /**
     * 数値（単位つき）
     *
     * @param string $name  入力名
     * @param mixed  $value 値
     * @param string $unit  単位
     * @param array  $opt   min/max/step/id/placeholder/class/attrs
     * @return string
     */
    private function number(string $name, $value, string $unit, array $opt = []): string {
        $html = '<input type="number" class="small-text ' . esc_attr($opt['class'] ?? '') . '"'
            . (isset($opt['id']) ? ' id="' . esc_attr($opt['id']) . '"' : '')
            . ' name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '"'
            . ' min="' . esc_attr((string) ($opt['min'] ?? 0)) . '"'
            . (isset($opt['max']) ? ' max="' . esc_attr((string) $opt['max']) . '"' : '')
            . ' step="' . esc_attr((string) ($opt['step'] ?? 1)) . '"'
            . (isset($opt['placeholder']) ? ' placeholder="' . esc_attr($opt['placeholder']) . '"' : '')
            . ($opt['attrs'] ?? '') . '>';
        return $html . ($unit !== '' ? ' <span class="ksp-unit">' . esc_html($unit) . '</span>' : '');
    }

    /**
     * セレクト
     *
     * @param string $name    入力名
     * @param string $value   値
     * @param array  $options 選択肢
     * @param string $id      id
     * @return string
     */
    private function select(string $name, string $value, array $options, string $id = ''): string {
        $html = '<select name="' . esc_attr($name) . '"' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . '>';
        foreach ($options as $k => $v) {
            $html .= '<option value="' . esc_attr((string) $k) . '"' . selected($value, (string) $k, false) . '>' . esc_html($v) . '</option>';
        }
        return $html . '</select>';
    }

    /**
     * 画像（メディアライブラリから選ぶ）
     *
     * @param string $name 入力名（添付ID）
     * @param int    $id   添付ID
     * @param string $url  いまの画像URL（ID が無効でも URL があれば見せる）
     * @param string $role プレビューで使う役割（content / mobile / a / b）
     * @return string
     */
    private function media(string $name, int $id, string $url, string $role): string {
        $thumb = $url;
        if ($id > 0) {
            $medium = wp_get_attachment_image_url($id, 'medium');
            $large  = wp_get_attachment_image_url($id, 'large');
            if ($medium && $large) {
                $thumb = (string) $medium;
                $url   = (string) $large;
            } else {
                // このサイトに無い添付ID（読み込んだ古い設定など）は、保存済みの URL の画像として扱う
                // （ID を送ると保存時に URL が空で解決され、画像が消える）
                $id = 0;
            }
        }
        $has = $thumb !== '' && $thumb !== false;
        // URL だけで指定された画像（読み込んだバナーなど）は、URL の欄で持ち回る（「外す」で消せるように）
        $url_name = preg_replace('/\[image_id\]$/', '[image_url]', $name);
        return '<div class="ksp-media' . ($has ? ' has-image' : '') . '" data-role="' . esc_attr($role) . '" data-url="' . esc_url((string) $url) . '">'
            . '<input type="hidden" class="ksp-media-id" name="' . esc_attr($name) . '" value="' . esc_attr($id > 0 ? (string) $id : '') . '">'
            . '<input type="hidden" class="ksp-media-url" name="' . esc_attr((string) $url_name) . '" value="' . esc_attr($id > 0 ? '' : (string) $url) . '">'
            . '<img class="ksp-media-thumb" src="' . esc_url($has ? (string) $thumb : '') . '" alt="">'
            . '<span class="ksp-media-buttons">'
            . '<button type="button" class="button ksp-media-select">' . esc_html__('画像を選ぶ', 'kashiwazaki-seo-staypop') . '</button> '
            . '<button type="button" class="button-link ksp-media-clear">' . esc_html__('外す', 'kashiwazaki-seo-staypop') . '</button>'
            . '</span></div>';
    }

    /**
     * 投稿・固定ページの選択（検索して追加）
     *
     * @param string $base     入力名の基点（targeting / exclusion）
     * @param array  $post_ids 投稿ID
     * @param array  $page_ids 固定ページID
     * @return string
     */
    private function post_picker(string $base, array $post_ids, array $page_ids): string {
        $ids   = array_values(array_unique(array_merge(array_map('intval', $post_ids), array_map('intval', $page_ids))));
        $chips = '';
        if ($ids) {
            foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => 'any', 'post__in' => $ids, 'posts_per_page' => count($ids), 'orderby' => 'post__in']) as $p) {
                $item = $this->post_item($p);
                if (!current_user_can('read_post', $p->ID)) {
                    // 読めない記事はタイトルを出さない（指定は消さずに残す）
                    $item['title'] = sprintf(__('（表示できない記事 #%d）', 'kashiwazaki-seo-staypop'), (int) $p->ID);
                }
                $field  = $item['type'] === 'page' ? 'page_ids' : 'post_ids';
                $chips .= '<span class="ksp-chip" data-id="' . esc_attr((string) $item['id']) . '">'
                    . '<span class="ksp-chip-type">' . esc_html($item['label']) . '</span> ' . esc_html($item['title'])
                    . '<input type="hidden" name="' . esc_attr($this->n($base, $field) . '[]') . '" value="' . esc_attr((string) $item['id']) . '">'
                    . '<button type="button" class="ksp-chip-remove" aria-label="' . esc_attr__('外す', 'kashiwazaki-seo-staypop') . '">&times;</button></span>';
            }
        }
        $id = $this->id();
        return '<div class="ksp-picker" data-base="' . esc_attr($this->n($base)) . '">'
            . '<div class="ksp-chips">' . $chips . '</div>'
            . '<div class="ksp-picker-search"><input type="search" id="' . esc_attr($id) . '" class="regular-text" placeholder="' . esc_attr__('タイトルで検索して追加', 'kashiwazaki-seo-staypop') . '" autocomplete="off">'
            . '<ul class="ksp-picker-results" role="listbox" hidden></ul></div></div>';
    }

    /**
     * チェックボックスの一覧（カテゴリー・権限）
     *
     * @param string $name     入力名（[] は付けない）
     * @param array  $options  key => ラベル
     * @param array  $selected 選択済み
     * @return string
     */
    private function checklist(string $name, array $options, array $selected): string {
        if (!$options) {
            return '<span class="description">' . esc_html__('選べるものがありません。', 'kashiwazaki-seo-staypop') . '</span>';
        }
        $html = '<div class="ksp-checklist">';
        foreach ($options as $k => $label) {
            $html .= '<label><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr((string) $k) . '"' . checked(in_array((string) $k, array_map('strval', $selected), true), true, false) . '> ' . esc_html($label) . '</label>';
        }
        return $html . '</div>';
    }

    /* ---- 描画 ---- */

    /**
     * 編集パネル
     *
     * @param WP_Post $post 投稿
     */
    public function render($post): void {
        if (!$post || $post->post_type !== Kashiwazaki_SEO_StayPop_Banners::POST_TYPE) {
            return;
        }
        $c = $this->banners->get_config((int) $post->ID);
        wp_nonce_field('kashiwazaki_staypop_save_' . $post->ID, 'kashiwazaki_staypop_nonce');

        $tabs = [
            'content'  => __('内容', 'kashiwazaki-seo-staypop'),
            'design'   => __('デザイン', 'kashiwazaki-seo-staypop'),
            'timing'   => __('表示のタイミング', 'kashiwazaki-seo-staypop'),
            'pages'    => __('表示するページ', 'kashiwazaki-seo-staypop'),
            'schedule' => __('回数・期間', 'kashiwazaki-seo-staypop'),
            'audience' => __('出し分け', 'kashiwazaki-seo-staypop'),
            'measure'  => __('成果・A/Bテスト', 'kashiwazaki-seo-staypop'),
        ];
        ?>
        <div class="ksp-editor" id="ksp-editor">
            <div class="ksp-topbar">
                <div class="ksp-switch-wrap">
                    <input type="hidden" name="<?php echo esc_attr($this->n('enabled')); ?>" value="0">
                    <label class="ksp-switch">
                        <?php // 新しいバナーは最初からオン（公開すれば表示される。下書きの間は表示されない） ?>
                        <input type="checkbox" name="<?php echo esc_attr($this->n('enabled')); ?>" value="1" <?php checked($post->post_status === 'auto-draft' || !empty($c['enabled'])); ?>>
                        <span class="ksp-switch-ui" aria-hidden="true"></span>
                        <span class="ksp-switch-label"><?php esc_html_e('サイトに表示する', 'kashiwazaki-seo-staypop'); ?></span>
                    </label>
                    <span class="ksp-status"><?php echo esc_html($this->status_text($post, $c)); ?></span>
                </div>
                <div class="ksp-summary" aria-live="polite">
                    <div><span class="ksp-summary-label"><?php esc_html_e('PC', 'kashiwazaki-seo-staypop'); ?></span> <span data-summary="pc"></span></div>
                    <div><span class="ksp-summary-label"><?php esc_html_e('スマホ', 'kashiwazaki-seo-staypop'); ?></span> <span data-summary="mobile"></span></div>
                </div>
            </div>

            <div class="ksp-layout">
                <div class="ksp-main">
                    <nav class="ksp-tabs" role="tablist">
                        <?php foreach ($tabs as $key => $label) : ?>
                            <button type="button" class="ksp-tab" role="tab" data-tab="<?php echo esc_attr($key); ?>" aria-controls="ksp-panel-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </nav>
                    <?php
                    $this->panel_content($c);
                    $this->panel_design($c);
                    $this->panel_timing($c);
                    $this->panel_pages($c);
                    $this->panel_schedule($c);
                    $this->panel_audience($c);
                    $this->panel_measure($c, (int) $post->ID);
                    ?>
                </div>
                <aside class="ksp-preview" aria-label="<?php esc_attr_e('プレビュー', 'kashiwazaki-seo-staypop'); ?>">
                    <div class="ksp-preview-head">
                        <strong><?php esc_html_e('プレビュー', 'kashiwazaki-seo-staypop'); ?></strong>
                        <span class="ksp-seg" data-preview="device">
                            <button type="button" class="is-active" data-value="pc"><?php esc_html_e('PC', 'kashiwazaki-seo-staypop'); ?></button><button type="button" data-value="mobile"><?php esc_html_e('スマホ', 'kashiwazaki-seo-staypop'); ?></button>
                        </span>
                        <span class="ksp-seg" data-preview="variant" hidden>
                            <button type="button" class="is-active" data-value="A">A</button><button type="button" data-value="B">B</button>
                        </span>
                        <button type="button" class="button-link ksp-preview-full"><?php esc_html_e('実物大で見る', 'kashiwazaki-seo-staypop'); ?></button>
                    </div>
                    <div class="ksp-frame" data-device="pc">
                        <div class="ksp-frame-inner">
                            <div class="ksp-fake-page" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
                            <div class="ksp-frame-mount"></div>
                        </div>
                    </div>
                    <div class="ksp-preview-foot">
                        <?php if ($post->post_status === 'auto-draft') : ?>
                            <span class="description"><?php esc_html_e('保存すると、サイトで表示のタイミングを試せます。', 'kashiwazaki-seo-staypop'); ?></span>
                        <?php else : ?>
                            <a class="button ksp-test-link" href="<?php echo esc_url(Kashiwazaki_SEO_StayPop_Frontend::test_url((int) $post->ID)); ?>" target="_blank" rel="noopener"><?php esc_html_e('サイトでテスト表示', 'kashiwazaki-seo-staypop'); ?></a>
                            <?php echo $this->tip(__('保存した内容で、サイトのトップページに表示を試します。表示するページ・期間・回数の制限は無視し、計測もしません。画面の左下に、あと何をすれば表示されるかが出ます。', 'kashiwazaki-seo-staypop')); // phpcs:ignore ?>
                            <p class="ksp-unsaved" hidden><?php esc_html_e('変更がまだ保存されていません。テスト表示は保存した内容で行います。', 'kashiwazaki-seo-staypop'); ?></p>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        </div>
        <?php
    }

    /**
     * いまの状態の文言
     *
     * @param WP_Post $post 投稿
     * @param array   $c    設定
     * @return string
     */
    private function status_text($post, array $c): string {
        if ($post->post_status === 'auto-draft') {
            return __('「公開」を押すと表示が始まります', 'kashiwazaki-seo-staypop');
        }
        if ($post->post_status !== 'publish') {
            return __('下書き：公開するまで表示されません', 'kashiwazaki-seo-staypop');
        }
        if (empty($c['enabled'])) {
            return __('停止中', 'kashiwazaki-seo-staypop');
        }
        return Kashiwazaki_SEO_StayPop_Admin::schedule_label($c, $this->conditions);
    }

    /**
     * パネルの開始
     *
     * @param string $key   キー
     * @param string $intro 1行の説明
     */
    private function open_panel(string $key, string $intro = ''): void {
        echo '<section class="ksp-panel" id="ksp-panel-' . esc_attr($key) . '" role="tabpanel" data-panel="' . esc_attr($key) . '">';
        if ($intro !== '') {
            echo '<p class="ksp-intro">' . esc_html($intro) . '</p>';
        }
        echo '<table class="form-table ksp-table" role="presentation"><tbody>';
    }

    /**
     * パネルの終了
     */
    private function close_panel(): void {
        echo '</tbody></table></section>';
    }

    /**
     * 内容
     *
     * @param array $c 設定
     */
    private function panel_content(array $c): void {
        $ct = $c['content'];
        $this->open_panel('content');

        $id   = $this->id();
        $tpl  = $this->select($this->n('template'), (string) $c['template'], Kashiwazaki_SEO_StayPop_Templates::list_templates(), $id);
        $tpl .= ' <button type="button" class="button ksp-apply-preset">' . esc_html__('この文言を入れる', 'kashiwazaki-seo-staypop') . '</button>';
        $this->row(__('テンプレート', 'kashiwazaki-seo-staypop'), $tpl, __('クーポン・資料請求などのよくある文言を入れられます。入れたあとで自由に書き換えてください。', 'kashiwazaki-seo-staypop'), $id);

        $this->row(__('画像', 'kashiwazaki-seo-staypop'), $this->media($this->n('content', 'image_id'), (int) $ct['image_id'], (string) $ct['image_url'], 'content'), __('メディアライブラリから選びます。画像だけのバナーにする場合は、見出しと説明文を空にしてください。', 'kashiwazaki-seo-staypop'));
        $this->row(__('スマホ用の画像', 'kashiwazaki-seo-staypop'), $this->media($this->n('device', 'mobile', 'image_id'), (int) $c['device']['mobile']['image_id'], (string) $c['device']['mobile']['image_url'], 'mobile') . '<p class="description">' . esc_html__('任意。空ならスマホでも上の画像を使います。', 'kashiwazaki-seo-staypop') . '</p>');

        $id = $this->id();
        $this->row(__('見出し', 'kashiwazaki-seo-staypop'), '<input type="text" class="large-text" id="' . esc_attr($id) . '" name="' . esc_attr($this->n('content', 'heading')) . '" value="' . esc_attr((string) $ct['heading']) . '" data-preview-field="heading">', '', $id);
        $id = $this->id();
        $this->row(__('説明文', 'kashiwazaki-seo-staypop'), '<textarea class="large-text" rows="3" id="' . esc_attr($id) . '" name="' . esc_attr($this->n('content', 'description')) . '">' . esc_textarea((string) $ct['description']) . '</textarea>', '', $id);
        $id = $this->id();
        $this->row(__('ボタンの文言', 'kashiwazaki-seo-staypop'), '<input type="text" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($this->n('content', 'button_text')) . '" value="' . esc_attr((string) $ct['button_text']) . '">', __('空にするとボタンを出しません。', 'kashiwazaki-seo-staypop'), $id);
        $id   = $this->id();
        $link = '<input type="url" class="large-text" id="' . esc_attr($id) . '" name="' . esc_attr($this->n('content', 'button_url')) . '" value="' . esc_attr((string) $ct['button_url']) . '" placeholder="https://">'
            . '<p>' . $this->checkbox($this->n('content', 'button_new_tab'), !empty($ct['button_new_tab']), __('新しいタブで開く', 'kashiwazaki-seo-staypop')) . '</p>';
        $this->row(__('ボタンのリンク先', 'kashiwazaki-seo-staypop'), $link, '', $id);

        $sub_open = ((string) $ct['secondary_text'] !== '' || (string) $ct['secondary_url'] !== '');
        $sub      = '<details class="ksp-details"' . ($sub_open ? ' open' : '') . '><summary>' . esc_html__('ボタンの横に小さなリンクを出す（任意）', 'kashiwazaki-seo-staypop') . '</summary>'
            . '<p><label>' . esc_html__('文言', 'kashiwazaki-seo-staypop') . '<br><input type="text" class="regular-text" name="' . esc_attr($this->n('content', 'secondary_text')) . '" value="' . esc_attr((string) $ct['secondary_text']) . '"></label></p>'
            . '<p><label>' . esc_html__('リンク先', 'kashiwazaki-seo-staypop') . '<br><input type="url" class="large-text" name="' . esc_attr($this->n('content', 'secondary_url')) . '" value="' . esc_attr((string) $ct['secondary_url']) . '" placeholder="https://"></label></p></details>';
        $this->row(__('サブリンク', 'kashiwazaki-seo-staypop'), $sub);
        $this->close_panel();
    }

    /**
     * デザイン
     *
     * @param array $c 設定
     */
    private function panel_design(array $c): void {
        $d = $c['design'];
        $this->open_panel('design');

        $cards = '<div class="ksp-cards" role="radiogroup">';
        foreach (Kashiwazaki_SEO_StayPop_Templates::list_formats() as $k => $label) {
            $cards .= '<label class="ksp-card"><input type="radio" name="' . esc_attr($this->n('format')) . '" value="' . esc_attr($k) . '"' . checked($c['format'], $k, false) . '>'
                . '<span class="ksp-card-pic ksp-pic-' . esc_attr($k) . '" aria-hidden="true"><b></b></span><span class="ksp-card-label">' . esc_html($label) . '</span></label>';
        }
        $cards .= '</div>';
        $this->row(__('表示形式', 'kashiwazaki-seo-staypop'), $cards, __('中央ポップアップは画面を暗くして中央に出します。下部バナーと隅のスライドは、ページを読みながら閉じられます。', 'kashiwazaki-seo-staypop'));

        $id = $this->id();
        $this->row(__('隅の位置', 'kashiwazaki-seo-staypop'), $this->select($this->n('corner_position'), (string) $c['corner_position'], Kashiwazaki_SEO_StayPop_Templates::list_corner_positions(), $id), '', $id, ' data-show-if="format=corner"');

        $id    = $this->id();
        $width = '<span class="ksp-inline"><span>' . esc_html__('PC', 'kashiwazaki-seo-staypop') . '</span> ' . $this->number($this->n('design', 'width'), (int) $d['width'], 'px', ['min' => 200, 'max' => 1200, 'id' => $id]) . '</span>'
            . '<span class="ksp-inline"><span>' . esc_html__('スマホ', 'kashiwazaki-seo-staypop') . '</span> ' . $this->number($this->n('device', 'mobile', 'width'), (int) $c['device']['mobile']['width'] > 0 ? (int) $c['device']['mobile']['width'] : '', 'px', ['min' => 0, 'max' => 1200, 'placeholder' => __('自動', 'kashiwazaki-seo-staypop')]) . '</span>';
        $this->row(__('幅', 'kashiwazaki-seo-staypop'), $width, __('最大の幅です。画面のほうが狭いときは画面に合わせて縮みます。スマホを空にすると画面の幅に合わせます。隅のスライドは最大 380px です。', 'kashiwazaki-seo-staypop'), $id, ' data-show-if="format!=bottom_bar"');

        $colors = '<div class="ksp-colors">';
        foreach ([
            'bg_color'     => __('背景', 'kashiwazaki-seo-staypop'),
            'text_color'   => __('文字', 'kashiwazaki-seo-staypop'),
            'button_bg'    => __('ボタン', 'kashiwazaki-seo-staypop'),
            'button_color' => __('ボタンの文字', 'kashiwazaki-seo-staypop'),
        ] as $k => $label) {
            $colors .= '<label class="ksp-color"><input type="color" name="' . esc_attr($this->n('design', $k)) . '" value="' . esc_attr((string) $d[$k]) . '"><span>' . esc_html($label) . '</span></label>';
        }
        $colors .= '</div>';
        $this->row(__('色', 'kashiwazaki-seo-staypop'), $colors);

        $id = $this->id();
        $this->row(__('角の丸み', 'kashiwazaki-seo-staypop'), $this->number($this->n('design', 'radius'), (int) $d['radius'], 'px', ['max' => 60, 'id' => $id]), '', $id);
        $id = $this->id();
        $this->row(__('内側の余白', 'kashiwazaki-seo-staypop'), $this->number($this->n('design', 'padding'), (int) $d['padding'], 'px', ['max' => 80, 'id' => $id]), '', $id);
        $id = $this->id();
        $this->row(__('文字の大きさ', 'kashiwazaki-seo-staypop'), $this->number($this->n('design', 'font_size'), (int) $d['font_size'], 'px', ['min' => 10, 'max' => 32, 'id' => $id]), '', $id);
        $id = $this->id();
        $this->row(__('背景の暗さ', 'kashiwazaki-seo-staypop'), $this->number($this->n('design', 'backdrop_pct'), (int) round(((float) $d['backdrop_opacity']) * 100), '%', ['max' => 100, 'step' => 5, 'id' => $id]), __('0% で暗くしません。', 'kashiwazaki-seo-staypop'), $id, ' data-show-if="format=center"');
        $this->close_panel();
    }

    /**
     * 表示のタイミング
     *
     * @param array $c 設定
     */
    private function panel_timing(array $c): void {
        $t = $c['triggers'];
        $this->open_panel('timing', __('チェックした「きっかけ」が起きた瞬間に表示します。', 'kashiwazaki-seo-staypop'));

        // PC
        $sens_options = Kashiwazaki_SEO_StayPop_Templates::list_sensitivities();
        $sens         = (int) $t['exit_sensitivity'];
        if (!array_key_exists($sens, $sens_options)) {
            $sens_options = [$sens => sprintf(__('カスタム（上端から %dpx 以内）', 'kashiwazaki-seo-staypop'), $sens)] + $sens_options;
        }
        $sid  = $this->id();
        $did  = $this->id();
        $pc   = $this->checkbox($this->n('triggers', 'exit_intent'), !empty($t['exit_intent']), __('マウスが画面の上端から外に出たとき（タブやアドレスバーに向かったとき）', 'kashiwazaki-seo-staypop'), ' data-trigger="exit"');
        $pc  .= '<div class="ksp-sub" data-show-if="triggers.exit_intent=1">'
            . '<p><label for="' . esc_attr($sid) . '">' . esc_html__('感度', 'kashiwazaki-seo-staypop') . '</label> ' . $this->select($this->n('triggers', 'exit_sensitivity'), (string) $sens, $sens_options, $sid) . '</p>'
            . '<p><label for="' . esc_attr($did) . '">' . esc_html__('表示までの待ち時間', 'kashiwazaki-seo-staypop') . '</label> '
            . $this->number($this->n('triggers', 'exit_delay_sec'), rtrim(rtrim(number_format(((int) $t['exit_delay_ms']) / 1000, 1, '.', ''), '0'), '.'), '秒', ['max' => 5, 'step' => 0.1, 'id' => $did])
            . $this->tip(__('待っている間にマウスが画面に戻ったら表示しません。', 'kashiwazaki-seo-staypop')) . '</p></div>';
        $this->row(__('PC', 'kashiwazaki-seo-staypop'), $pc);

        // スマホ
        $sp = $this->checkbox($this->n('triggers', 'upward_scroll'), !empty($t['upward_scroll']), __('下に読んだあと、上に戻るスクロールをしたとき', 'kashiwazaki-seo-staypop'), ' data-trigger="upward"');
        $this->row(__('スマホ', 'kashiwazaki-seo-staypop'), $sp, __('ある程度下まで読んだ人が、ページの上に戻ろうとしたときに表示します。', 'kashiwazaki-seo-staypop'));

        // 共通
        $dwell = (int) $t['dwell_seconds'];
        $read  = (int) $t['read_rate'];
        $both  = '<p class="ksp-pair">' . $this->checkbox($this->n('triggers', 'dwell_enabled'), $dwell > 0, __('ページを', 'kashiwazaki-seo-staypop'), ' data-trigger="dwell" data-enables="ksp-dwell"')
            . ' ' . $this->number($this->n('triggers', 'dwell_seconds'), $dwell > 0 ? $dwell : 30, '', ['min' => 1, 'max' => 3600, 'id' => 'ksp-dwell']) . ' <span>' . esc_html__('秒見たとき', 'kashiwazaki-seo-staypop') . '</span></p>'
            . '<p class="ksp-pair">' . $this->checkbox($this->n('triggers', 'read_enabled'), $read > 0, __('ページを', 'kashiwazaki-seo-staypop'), ' data-trigger="read" data-enables="ksp-read"')
            . ' ' . $this->number($this->n('triggers', 'read_rate'), $read > 0 ? $read : 50, '', ['min' => 1, 'max' => 100, 'id' => 'ksp-read']) . ' <span>' . esc_html__('％までスクロールしたとき', 'kashiwazaki-seo-staypop') . '</span></p>';
        $this->row(__('PC・スマホ共通', 'kashiwazaki-seo-staypop'), $both, __('秒数は、ページが画面に出ていた時間だけを数えます（別のタブを見ている間は数えません）。', 'kashiwazaki-seo-staypop'));

        // 組み合わせ
        $logic = '<fieldset class="ksp-radios">'
            . '<label><input type="radio" name="' . esc_attr($this->n('triggers', 'condition_logic')) . '" value="any"' . checked($t['condition_logic'], 'any', false) . '> ' . esc_html__('どれか1つが起きたとき', 'kashiwazaki-seo-staypop') . '</label>'
            . '<label><input type="radio" name="' . esc_attr($this->n('triggers', 'condition_logic')) . '" value="all"' . checked($t['condition_logic'], 'all', false) . '> ' . esc_html__('すべてそろったとき（例：30秒以上見て、50％以上スクロールした人）', 'kashiwazaki-seo-staypop') . '</label>'
            . '</fieldset>';
        $this->row(__('組み合わせ', 'kashiwazaki-seo-staypop'), $logic, __('「すべてそろったとき」で離脱のきっかけ（PCの上端・スマホの上スクロール）を選んでいる場合は、秒数やスクロールの条件を満たしたあとで離脱しそうになった瞬間に表示します。', 'kashiwazaki-seo-staypop'), '', ' data-show-if="triggers.count>=2"');

        // 誤表示の抑制
        $id = $this->id();
        $this->row(
            __('開いた直後', 'kashiwazaki-seo-staypop'),
            '<span>' . esc_html__('ページを開いてから', 'kashiwazaki-seo-staypop') . '</span> ' . $this->number($this->n('triggers', 'min_dwell_seconds'), (int) $t['min_dwell_seconds'], '', ['max' => 3600, 'id' => $id]) . ' <span>' . esc_html__('秒は表示しない', 'kashiwazaki-seo-staypop') . '</span>'
            . '<p class="description">' . esc_html__('マウスを少し動かしただけのときや、フォームに入力している間（入力のあと10秒も）は、自動的に表示しません。', 'kashiwazaki-seo-staypop') . '</p>',
            __('この間に起きたきっかけは数えません。あとからまとめて表示することもありません。', 'kashiwazaki-seo-staypop'),
            $id
        );
        $this->close_panel();
    }

    /**
     * 表示するページ
     *
     * @param array $c 設定
     */
    private function panel_pages(array $c): void {
        $t = $c['targeting'];
        $e = $c['exclusion'];
        $this->open_panel('pages');

        $radios = '<fieldset class="ksp-radios">';
        foreach (Kashiwazaki_SEO_StayPop_Templates::list_page_modes() as $k => $label) {
            $radios .= '<label><input type="radio" name="' . esc_attr($this->n('targeting', 'pages')) . '" value="' . esc_attr($k) . '"' . checked($t['pages'], $k, false) . '> ' . esc_html($label) . '</label>';
        }
        $radios .= '</fieldset>';
        $this->row(__('表示する場所', 'kashiwazaki-seo-staypop'), $radios);

        $id = $this->id();
        $this->row(__('URL・パス', 'kashiwazaki-seo-staypop'), '<textarea class="large-text code" rows="3" id="' . esc_attr($id) . '" name="' . esc_attr($this->n('targeting', 'urls')) . '" placeholder="/campaign/&#10;/blog/*">' . esc_textarea((string) $t['urls']) . '</textarea>', __('1行に1つ。/blog/ と書くと /blog/ の下のページすべてに一致します。* はワイルドカードです。https:// から書いても構いません。', 'kashiwazaki-seo-staypop'), $id, ' data-show-if="targeting.pages=include"');
        $this->row(__('投稿・固定ページ', 'kashiwazaki-seo-staypop'), $this->post_picker('targeting', (array) $t['post_ids'], (array) $t['page_ids']), '', '', ' data-show-if="targeting.pages=include"');
        $cats = [];
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $cats[$term->term_id] = $term->name;
            }
        }
        $this->row(__('カテゴリー', 'kashiwazaki-seo-staypop'), $this->checklist($this->n('targeting', 'categories'), $cats, (array) $t['categories']), __('選んだカテゴリーの一覧ページと、そのカテゴリーの投稿に表示します。', 'kashiwazaki-seo-staypop'), '', ' data-show-if="targeting.pages=include"');

        echo '<tr class="ksp-sep"><th colspan="2">' . esc_html__('表示しないページ', 'kashiwazaki-seo-staypop') . '</th></tr>';
        $id = $this->id();
        $this->row(__('URL・パス', 'kashiwazaki-seo-staypop'), '<textarea class="large-text code" rows="3" id="' . esc_attr($id) . '" name="' . esc_attr($this->n('exclusion', 'urls')) . '" placeholder="/thanks/&#10;/checkout/*">' . esc_textarea((string) $e['urls']) . '</textarea>', __('購入完了・問い合わせ完了・決済のページなど。書き方は上と同じです。', 'kashiwazaki-seo-staypop'), $id);
        $this->row(__('投稿・固定ページ', 'kashiwazaki-seo-staypop'), $this->post_picker('exclusion', (array) $e['post_ids'], (array) $e['page_ids']));
        $this->close_panel();
    }

    /**
     * 回数・期間
     *
     * @param array $c 設定
     */
    private function panel_schedule(array $c): void {
        $f = $c['frequency'];
        $s = $c['schedule'];
        $this->open_panel('schedule');

        $id = $this->id();
        $this->row(__('表示する回数', 'kashiwazaki-seo-staypop'), $this->select($this->n('frequency', 'mode'), (string) $f['mode'], Kashiwazaki_SEO_StayPop_Templates::list_frequency_modes(), $id), __('「1回の訪問」は、ページを見なくなってから30分たつと次の訪問として数えます。別のタブで開いても同じ訪問です。', 'kashiwazaki-seo-staypop'), $id);
        $id = $this->id();
        $this->row(__('閉じたあと', 'kashiwazaki-seo-staypop'), $this->number($this->n('frequency', 'snooze_days'), (int) $f['snooze_days'], '', ['max' => 365, 'id' => $id]) . ' <span>' . esc_html__('日は表示しない', 'kashiwazaki-seo-staypop') . '</span>', __('0 にすると、閉じても上の回数の決まりで再び表示します。', 'kashiwazaki-seo-staypop'), $id);

        $tz  = wp_timezone_string();
        $sid = $this->id();
        $range = '<span class="ksp-inline"><input type="datetime-local" id="' . esc_attr($sid) . '" name="' . esc_attr($this->n('schedule', 'start')) . '" value="' . esc_attr((string) $s['start']) . '"></span>'
            . '<span class="ksp-inline">〜 <input type="datetime-local" name="' . esc_attr($this->n('schedule', 'end')) . '" value="' . esc_attr((string) $s['end']) . '"></span>'
            . '<p class="description">' . esc_html(sprintf(__('空欄なら期限なし。時刻はサイトのタイムゾーン（%s）です。', 'kashiwazaki-seo-staypop'), $tz)) . '</p>';
        $this->row(__('掲載期間', 'kashiwazaki-seo-staypop'), $range, '', $sid);

        $id = $this->id();
        $this->row(__('優先度', 'kashiwazaki-seo-staypop'), $this->number($this->n('priority'), (int) $c['priority'], '', ['max' => 100, 'id' => $id]), __('ほかのバナーと同時に表示のきっかけが起きたときは、数字が大きいほうを1つだけ表示します。', 'kashiwazaki-seo-staypop'), $id);
        $this->close_panel();
    }

    /**
     * 出し分け
     *
     * @param array $c 設定
     */
    private function panel_audience(array $c): void {
        $a = $c['audience'];
        $this->open_panel('audience', __('何も指定しなければ、すべての訪問者に表示します。', 'kashiwazaki-seo-staypop'));

        $id = $this->id();
        $this->row(__('ログイン', 'kashiwazaki-seo-staypop'), $this->select($this->n('audience', 'login_state'), (string) $a['login_state'], Kashiwazaki_SEO_StayPop_Templates::list_login_states(), $id), '', $id);
        $roles = wp_roles()->get_names();
        $roles = array_map('translate_user_role', $roles);
        $this->row(__('ユーザーの権限', 'kashiwazaki-seo-staypop'), $this->checklist($this->n('audience', 'roles'), $roles, (array) $a['roles']), __('選んだ権限のログイン中のユーザーにだけ表示します。何も選ばなければ権限で絞りません。', 'kashiwazaki-seo-staypop'), '', ' data-show-if="audience.login_state!=logged_out"');
        $id = $this->id();
        $this->row(__('流入元（utm_source）', 'kashiwazaki-seo-staypop'), '<textarea class="large-text code" rows="2" id="' . esc_attr($id) . '" name="' . esc_attr($this->n('audience', 'utm_sources')) . '" placeholder="newsletter&#10;twitter">' . esc_textarea((string) $a['utm_sources']) . '</textarea>', __('URL の utm_source= の値が一致した人にだけ表示します。1行に1つ。* はワイルドカードです。', 'kashiwazaki-seo-staypop'), $id);
        $id = $this->id();
        $this->row(__('参照元のサイト', 'kashiwazaki-seo-staypop'), '<textarea class="large-text code" rows="2" id="' . esc_attr($id) . '" name="' . esc_attr($this->n('audience', 'referrers')) . '" placeholder="google.com&#10;*.example.com">' . esc_textarea((string) $a['referrers']) . '</textarea>', __('どのサイトから来た人に表示するかを、ドメインで指定します。google.com と書くと www.google.com も含みます。ブラウザによっては参照元が送られないため、一致しない人もいます。', 'kashiwazaki-seo-staypop'), $id);
        $this->close_panel();
    }

    /**
     * 成果・A/Bテスト
     *
     * @param array $c       設定
     * @param int   $post_id 投稿ID
     */
    private function panel_measure(array $c, int $post_id): void {
        $ab = $c['ab_test'];
        $this->open_panel('measure');

        $id = $this->id();
        $this->row(__('成果ページ', 'kashiwazaki-seo-staypop'), '<textarea class="large-text code" rows="2" id="' . esc_attr($id) . '" name="' . esc_attr($this->n('conversion', 'conversion_urls')) . '" placeholder="/thanks/">' . esc_textarea((string) $c['conversion']['conversion_urls']) . '</textarea>'
            . '<p>' . $this->checkbox($this->n('exclusion', 'stop_after_conversion'), !empty($c['exclusion']['stop_after_conversion']), __('成果に至った人には、このバナーを表示しない', 'kashiwazaki-seo-staypop')) . '</p>',
            __('購入完了・問い合わせ完了などのページ。このバナーを見た（またはクリックした）人がこのページに来たら「成果」として数えます。計測が有効なときだけ数えます。', 'kashiwazaki-seo-staypop'), $id);

        $abf  = $this->checkbox($this->n('ab_test', 'enabled'), !empty($ab['enabled']), __('A/Bテストをする', 'kashiwazaki-seo-staypop'));
        $va   = $ab['variants'][0] ?? [];
        $vb   = $ab['variants'][1] ?? [];
        $wa   = (int) ($va['weight'] ?? 50);
        $wb   = (int) ($vb['weight'] ?? 50);
        $wa   = ($wa + $wb) > 0 ? (int) round($wa * 100 / ($wa + $wb)) : 50;
        $abf .= '<div class="ksp-sub" data-show-if="ab_test.enabled=1">'
            . '<p>' . esc_html__('A を', 'kashiwazaki-seo-staypop') . ' ' . $this->number($this->n('ab_test', 'weight_a'), $wa, '%', ['max' => 100, 'step' => 5]) . ' ' . esc_html__('の人に、B を残りの人に見せます。', 'kashiwazaki-seo-staypop') . '</p>'
            . '<div class="ksp-ab">';
        foreach ([0 => $va, 1 => $vb] as $i => $v) {
            $label = $i === 0 ? 'A' : 'B';
            $abf  .= '<div class="ksp-ab-col"><h4>' . esc_html(sprintf(__('パターン %s', 'kashiwazaki-seo-staypop'), $label)) . '</h4>'
                . '<p><label>' . esc_html__('見出し', 'kashiwazaki-seo-staypop') . '<br><input type="text" class="large-text" name="' . esc_attr($this->n('ab_test', 'variants', (string) $i, 'heading')) . '" value="' . esc_attr((string) ($v['heading'] ?? '')) . '" placeholder="' . esc_attr__('空なら「内容」の見出し', 'kashiwazaki-seo-staypop') . '"></label></p>'
                . '<p><label>' . esc_html__('ボタンの文言', 'kashiwazaki-seo-staypop') . '<br><input type="text" class="large-text" name="' . esc_attr($this->n('ab_test', 'variants', (string) $i, 'button_text')) . '" value="' . esc_attr((string) ($v['button_text'] ?? '')) . '" placeholder="' . esc_attr__('空なら「内容」の文言', 'kashiwazaki-seo-staypop') . '"></label></p>'
                . '<div>' . esc_html__('画像', 'kashiwazaki-seo-staypop') . '</div>' . $this->media($this->n('ab_test', 'variants', (string) $i, 'image_id'), (int) ($v['image_id'] ?? 0), (string) ($v['image_url'] ?? ''), $i === 0 ? 'a' : 'b')
                . '</div>';
        }
        $abf .= '</div><p class="description">' . esc_html__('空の欄は「内容」の設定を使います。結果（表示・クリック率・成果率）は「集計」タブで比べられます。', 'kashiwazaki-seo-staypop') . '</p></div>';
        $this->row(__('A/Bテスト', 'kashiwazaki-seo-staypop'), $abf);

        if ($post_id > 0 && get_post_status($post_id) !== 'auto-draft') {
            $url = add_query_arg(['page' => Kashiwazaki_SEO_StayPop_Admin::MENU_SLUG, 'tab' => 'stats', 'banner' => $post_id], admin_url('admin.php'));
            $this->row(__('集計', 'kashiwazaki-seo-staypop'), '<a href="' . esc_url($url) . '">' . esc_html__('このバナーの集計を見る', 'kashiwazaki-seo-staypop') . '</a>');
        }
        $this->close_panel();
    }
}
