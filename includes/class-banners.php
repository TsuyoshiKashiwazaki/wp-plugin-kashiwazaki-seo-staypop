<?php
/**
 * バナー（投稿タイプ）の登録・設定の読み書き
 *
 * 編集画面の描画は class-editor.php が受け持つ。
 *
 * @package Kashiwazaki_SEO_StayPop
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * バナー管理クラス
 */
class Kashiwazaki_SEO_StayPop_Banners {

    /**
     * 投稿タイプ名（20文字以内）
     */
    public const POST_TYPE = 'kashiwazaki_staypop';

    /**
     * 設定を保存する投稿メタのキー
     */
    public const META_KEY = '_kashiwazaki_staypop_config';

    /**
     * フォームの入力名プレフィックス
     */
    public const FIELD = 'kashiwazaki_seo_staypop';

    /**
     * フック登録
     */
    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_meta'], 10, 2);
        add_action('admin_post_kashiwazaki_staypop_duplicate', [$this, 'handle_duplicate']);
        add_action('admin_post_kashiwazaki_staypop_toggle', [$this, 'handle_toggle']);
    }

    /**
     * 投稿タイプを登録（init で実行）
     */
    public function register_post_type(): void {
        $labels = [
            'name'               => __('バナー', 'kashiwazaki-seo-staypop'),
            'singular_name'      => __('バナー', 'kashiwazaki-seo-staypop'),
            'add_new'            => __('新規追加', 'kashiwazaki-seo-staypop'),
            'add_new_item'       => __('バナーを追加', 'kashiwazaki-seo-staypop'),
            'edit_item'          => __('バナーを編集', 'kashiwazaki-seo-staypop'),
            'new_item'           => __('新しいバナー', 'kashiwazaki-seo-staypop'),
            'search_items'       => __('バナーを検索', 'kashiwazaki-seo-staypop'),
            'not_found'          => __('バナーがありません', 'kashiwazaki-seo-staypop'),
            'not_found_in_trash' => __('ゴミ箱にバナーはありません', 'kashiwazaki-seo-staypop'),
            'all_items'          => __('バナー一覧', 'kashiwazaki-seo-staypop'),
            'menu_name'          => __('Kashiwazaki SEO StayPop', 'kashiwazaki-seo-staypop'),
        ];
        register_post_type(self::POST_TYPE, [
            'labels'          => $labels,
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => false, // 独自メニュー（タブの画面）から開く
            'supports'        => ['title'],
            'rewrite'         => false,
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'has_archive'     => false,
        ]);
    }

    /**
     * バナーの設定を取得（既定値をマージし、古い形式の値を今の形に読み替える）
     *
     * @param int $post_id 投稿ID
     * @return array
     */
    public function get_config(int $post_id): array {
        $saved = get_post_meta($post_id, self::META_KEY, true);
        $saved = is_array($saved) ? $saved : [];
        return $this->normalize_config($this->merge_config(Kashiwazaki_SEO_StayPop_Templates::get_default_config(), $saved));
    }

    /**
     * 古い形式の値を今の形に読み替える（保存済みのデータを壊さないための互換処理）
     *
     * - スクロール率（現在位置）と読了率（最大到達）は同じ意味なので、読了率に寄せる
     * - 「PC用の画像・幅」は「共通の画像・幅」と同じ意味なので、共通に寄せる
     *
     * @param array $c 設定
     * @return array
     */
    private function normalize_config(array $c): array {
        if (is_array($c['triggers'] ?? null)) {
            $read   = intval($c['triggers']['read_rate'] ?? 0);
            $scroll = intval($c['triggers']['scroll_pct'] ?? 0);
            if ($read <= 0 && $scroll > 0) {
                $c['triggers']['read_rate'] = $scroll;
            }
            $c['triggers']['scroll_pct']      = 0;
            $c['triggers']['min_mouse_moves'] = 0;
        }
        if (is_array($c['device']['pc'] ?? null) && is_array($c['content'] ?? null)) {
            $pc = $c['device']['pc'];
            if (($c['content']['image_url'] ?? '') === '' && intval($c['content']['image_id'] ?? 0) === 0
                && (($pc['image_url'] ?? '') !== '' || intval($pc['image_id'] ?? 0) > 0)) {
                $c['content']['image_id']  = intval($pc['image_id'] ?? 0);
                $c['content']['image_url'] = (string) ($pc['image_url'] ?? '');
            }
            if (intval($pc['width'] ?? 0) > 0 && is_array($c['design'] ?? null)) {
                $c['design']['width'] = intval($pc['width']);
            }
            $c['device']['pc'] = ['image_id' => 0, 'image_url' => '', 'width' => 0];
        }
        return $c;
    }

    /**
     * 既定値に保存値を再帰的にマージする
     *
     * @param array $default 既定値
     * @param array $saved   保存値
     * @return array
     */
    private function merge_config(array $default, array $saved): array {
        foreach ($saved as $key => $value) {
            if (is_array($value) && isset($default[$key]) && is_array($default[$key])) {
                // 添字配列（variants など）は保存値を優先
                if ($this->is_list($value) || $this->is_list($default[$key])) {
                    $default[$key] = $value;
                } else {
                    $default[$key] = $this->merge_config($default[$key], $value);
                }
            } elseif (isset($default[$key]) && is_array($default[$key]) && !is_array($value)) {
                // 壊れた値（配列のはずがスカラー）は既定値のままにする
                continue;
            } else {
                $default[$key] = $value;
            }
        }
        return $default;
    }

    /**
     * 添字配列か判定
     *
     * @param array $array 配列
     * @return bool
     */
    private function is_list(array $array): bool {
        return array_keys($array) === range(0, count($array) - 1) && count($array) > 0;
    }

    /**
     * 公開中かつ有効なバナーを取得
     *
     * @return int[] 投稿IDの配列
     */
    public function get_active_banner_ids(): array {
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $out = [];
        foreach ($posts as $id) {
            $config = $this->get_config($id);
            if (!empty($config['enabled'])) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /* ---- 保存 ---- */

    /**
     * 設定を保存
     *
     * @param int     $post_id 投稿ID
     * @param WP_Post $post    投稿
     */
    public function save_meta(int $post_id, $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!isset($_POST['kashiwazaki_staypop_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kashiwazaki_staypop_nonce'])), 'kashiwazaki_staypop_save_' . $post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $raw = isset($_POST[self::FIELD]) ? wp_unslash($_POST[self::FIELD]) : [];
        if (!is_array($raw)) {
            return;
        }
        $config = $this->sanitize_config($raw);
        // 画像URLの欄を送ってこない呼び出し元（古い画面など）では、URLだけで指定された画像を
        // 再保存で失わないよう引き継ぐ。いまの編集画面は URL の欄を送るので、「外す」で消せる。
        $existing = $this->get_config($post_id);
        $keep     = static function ($raw_part, $saved_part): bool {
            return is_array($raw_part) && !array_key_exists('image_url', $raw_part)
                && intval($raw_part['image_id'] ?? 0) === 0
                && is_array($saved_part) && intval($saved_part['image_id'] ?? 0) === 0
                && (string) ($saved_part['image_url'] ?? '') !== '';
        };
        if ($keep($raw['content'] ?? null, $existing['content'] ?? null)) {
            $config['content']['image_url'] = $existing['content']['image_url'];
        }
        if ($keep($raw['device']['mobile'] ?? null, $existing['device']['mobile'] ?? null)) {
            $config['device']['mobile']['image_url'] = $existing['device']['mobile']['image_url'];
        }
        foreach ([0, 1] as $i) {
            if ($keep($raw['ab_test']['variants'][$i] ?? null, $existing['ab_test']['variants'][$i] ?? null)) {
                $config['ab_test']['variants'][$i]['image_url'] = $existing['ab_test']['variants'][$i]['image_url'];
            }
        }
        // update_post_meta は保存時に wp_unslash するため、改行・バックスラッシュを保つよう wp_slash する
        update_post_meta($post_id, self::META_KEY, wp_slash($config));
    }

    /**
     * 入力値をサニタイズして設定配列を組み立てる
     *
     * 編集画面の入力（チェックボックス + 数値、秒・％の単位）と、
     * エクスポートした JSON（保存形式そのもの）の両方を受け付ける。
     *
     * @param array $raw 生の入力
     * @return array
     */
    public function sanitize_config(array $raw): array {
        $d   = Kashiwazaki_SEO_StayPop_Templates::get_default_config();
        $arr = static function ($v): array {
            return is_array($v) ? $v : [];
        };
        $rc  = $arr($raw['content'] ?? null);
        $rd  = $arr($raw['design'] ?? null);
        $rv  = $arr($raw['device'] ?? null);
        $rt  = $arr($raw['triggers'] ?? null);
        $rf  = $arr($raw['frequency'] ?? null);
        $rg  = $arr($raw['targeting'] ?? null);
        $re  = $arr($raw['exclusion'] ?? null);
        $rs  = $arr($raw['schedule'] ?? null);
        $ra  = $arr($raw['audience'] ?? null);
        $rcv = $arr($raw['conversion'] ?? null);
        $rab = $arr($raw['ab_test'] ?? null);

        $content = [
            'image_id'       => intval($rc['image_id'] ?? 0),
            'image_url'      => $this->resolve_image_url($rc['image_id'] ?? 0, $rc['image_url'] ?? ''),
            'heading'        => sanitize_text_field((string) ($rc['heading'] ?? '')),
            'description'    => sanitize_textarea_field((string) ($rc['description'] ?? '')),
            'button_text'    => sanitize_text_field((string) ($rc['button_text'] ?? '')),
            'button_url'     => esc_url_raw((string) ($rc['button_url'] ?? '')),
            'button_new_tab' => !empty($rc['button_new_tab']),
            'secondary_text' => sanitize_text_field((string) ($rc['secondary_text'] ?? '')),
            'secondary_url'  => esc_url_raw((string) ($rc['secondary_url'] ?? '')),
        ];

        // 背景の暗さ: 編集画面は％、保存形式は 0〜1
        if (array_key_exists('backdrop_pct', $rd)) {
            $backdrop = $this->clamp_int($rd['backdrop_pct'], 0, 100) / 100;
        } else {
            $backdrop = $this->clamp_float($rd['backdrop_opacity'] ?? $d['design']['backdrop_opacity'], 0, 1);
        }
        $design = [
            'width'            => $this->clamp_int($rd['width'] ?? $d['design']['width'], 200, 1200),
            'bg_color'         => $this->sanitize_hex($rd['bg_color'] ?? '', $d['design']['bg_color']),
            'text_color'       => $this->sanitize_hex($rd['text_color'] ?? '', $d['design']['text_color']),
            'button_bg'        => $this->sanitize_hex($rd['button_bg'] ?? '', $d['design']['button_bg']),
            'button_color'     => $this->sanitize_hex($rd['button_color'] ?? '', $d['design']['button_color']),
            'radius'           => $this->clamp_int($rd['radius'] ?? $d['design']['radius'], 0, 60),
            'padding'          => $this->clamp_int($rd['padding'] ?? $d['design']['padding'], 0, 80),
            'font_size'        => $this->clamp_int($rd['font_size'] ?? $d['design']['font_size'], 10, 32),
            'backdrop_opacity' => round($backdrop, 2),
        ];

        $rpc = $arr($rv['pc'] ?? null);
        $rmo = $arr($rv['mobile'] ?? null);
        $device = [
            'pc'     => [
                'image_id'  => intval($rpc['image_id'] ?? 0),
                'image_url' => $this->resolve_image_url($rpc['image_id'] ?? 0, $rpc['image_url'] ?? ''),
                'width'     => $this->clamp_int($rpc['width'] ?? 0, 0, 1200),
            ],
            'mobile' => [
                'image_id'  => intval($rmo['image_id'] ?? 0),
                'image_url' => $this->resolve_image_url($rmo['image_id'] ?? 0, $rmo['image_url'] ?? ''),
                'width'     => $this->clamp_int($rmo['width'] ?? 0, 0, 1200),
            ],
        ];

        // 表示のきっかけ。編集画面ではチェックボックスで ON/OFF、数値は残しておく。
        $dwell = $this->clamp_int($rt['dwell_seconds'] ?? 0, 0, 3600);
        if (array_key_exists('dwell_enabled', $rt) && empty($rt['dwell_enabled'])) {
            $dwell = 0;
        }
        $read   = $this->clamp_int($rt['read_rate'] ?? 0, 0, 100);
        $scroll = $this->clamp_int($rt['scroll_pct'] ?? 0, 0, 100);
        if ($read === 0 && $scroll > 0) {
            $read = $scroll; // 旧「スクロール率」は読了率に寄せる
        }
        if (array_key_exists('read_enabled', $rt) && empty($rt['read_enabled'])) {
            $read = 0;
        }
        if (array_key_exists('exit_delay_sec', $rt)) {
            $delay_ms = (int) round(floatval(is_scalar($rt['exit_delay_sec']) ? $rt['exit_delay_sec'] : 0) * 1000);
        } else {
            $delay_ms = intval($rt['exit_delay_ms'] ?? 0);
        }
        $triggers = [
            'exit_intent'       => !empty($rt['exit_intent']),
            'exit_sensitivity'  => $this->clamp_int($rt['exit_sensitivity'] ?? $d['triggers']['exit_sensitivity'], 0, 300),
            'exit_delay_ms'     => max(0, min(5000, $delay_ms)),
            'dwell_seconds'     => $dwell,
            'scroll_pct'        => 0,
            'upward_scroll'     => !empty($rt['upward_scroll']),
            'read_rate'         => $read,
            'min_dwell_seconds' => $this->clamp_int($rt['min_dwell_seconds'] ?? 0, 0, 3600),
            'min_mouse_moves'   => 0,
            'condition_logic'   => $this->enum_key($rt['condition_logic'] ?? '', ['any' => '', 'all' => ''], 'any'),
        ];

        $frequency = [
            'mode'        => $this->enum_key($rf['mode'] ?? '', Kashiwazaki_SEO_StayPop_Templates::list_frequency_modes(), 'once_session'),
            'snooze_days' => $this->clamp_int($rf['snooze_days'] ?? 7, 0, 365),
        ];
        $targeting = [
            'pages'      => $this->enum_key($rg['pages'] ?? '', ['all' => '', 'include' => ''], 'all'),
            'urls'       => $this->sanitize_lines($rg['urls'] ?? ''),
            'post_ids'   => $this->sanitize_ids($rg['post_ids'] ?? []),
            'page_ids'   => $this->sanitize_ids($rg['page_ids'] ?? []),
            'categories' => $this->sanitize_ids($rg['categories'] ?? []),
        ];
        $exclusion = [
            'urls'                  => $this->sanitize_lines($re['urls'] ?? ''),
            'post_ids'              => $this->sanitize_ids($re['post_ids'] ?? []),
            'page_ids'              => $this->sanitize_ids($re['page_ids'] ?? []),
            'stop_after_conversion' => !empty($re['stop_after_conversion']),
        ];
        $schedule = [
            'start' => $this->sanitize_datetime($rs['start'] ?? ''),
            'end'   => $this->sanitize_datetime($rs['end'] ?? ''),
        ];
        $valid_roles = array_keys(wp_roles()->get_names());
        $login_state = $this->enum_key($ra['login_state'] ?? '', ['any' => '', 'logged_in' => '', 'logged_out' => ''], 'any');
        $audience = [
            'login_state' => $login_state,
            // 「未ログインのみ」に権限の指定は意味がない（誰も表示されなくなる）ので外す
            'roles'       => $login_state === 'logged_out' ? [] : array_values(array_intersect($this->sanitize_keys($ra['roles'] ?? []), $valid_roles)),
            'utm_sources' => $this->sanitize_lines($ra['utm_sources'] ?? ''),
            'referrers'   => $this->sanitize_lines($ra['referrers'] ?? ''),
        ];
        $conversion = [
            'conversion_urls' => $this->sanitize_lines($rcv['conversion_urls'] ?? ''),
        ];

        // A/Bテスト: 編集画面は「A の割合（％）」だけを入れ、B は残り
        $raw_variants = $arr($rab['variants'] ?? null);
        $weights      = [];
        if (array_key_exists('weight_a', $rab)) {
            $wa      = $this->clamp_int($rab['weight_a'], 0, 100);
            $weights = [$wa, 100 - $wa];
        }
        $ab_variants = [];
        foreach ([0, 1] as $i) {
            $v = $arr($raw_variants[$i] ?? null);
            $ab_variants[] = [
                'label'       => $i === 0 ? 'A' : 'B',
                'weight'      => $weights ? $weights[$i] : $this->clamp_int($v['weight'] ?? 50, 0, 100),
                'heading'     => sanitize_text_field((string) ($v['heading'] ?? '')),
                'image_id'    => intval($v['image_id'] ?? 0),
                'image_url'   => $this->resolve_image_url($v['image_id'] ?? 0, $v['image_url'] ?? ''),
                'button_text' => sanitize_text_field((string) ($v['button_text'] ?? '')),
            ];
        }

        return $this->normalize_config([
            'enabled'         => !empty($raw['enabled']),
            'template'        => $this->enum_key($raw['template'] ?? '', Kashiwazaki_SEO_StayPop_Templates::list_templates(), 'custom'),
            'format'          => $this->enum_key($raw['format'] ?? '', Kashiwazaki_SEO_StayPop_Templates::list_formats(), 'center'),
            'corner_position' => $this->enum_key($raw['corner_position'] ?? '', Kashiwazaki_SEO_StayPop_Templates::list_corner_positions(), 'bottom-right'),
            'priority'        => $this->clamp_int($raw['priority'] ?? 10, 0, 100),
            'content'         => $content,
            'design'          => $design,
            'device'          => $device,
            'triggers'        => $triggers,
            'frequency'       => $frequency,
            'targeting'       => $targeting,
            'exclusion'       => $exclusion,
            'schedule'        => $schedule,
            'audience'        => $audience,
            'conversion'      => $conversion,
            'ab_test'         => ['enabled' => !empty($rab['enabled']), 'variants' => $ab_variants],
        ]);
    }

    /**
     * 整数を範囲内に丸める
     *
     * @param mixed $v   値
     * @param int   $min 最小
     * @param int   $max 最大
     * @return int
     */
    private function clamp_int($v, int $min, int $max): int {
        return max($min, min($max, intval(is_scalar($v) ? $v : 0)));
    }

    /**
     * 浮動小数を範囲内に丸める
     *
     * @param mixed $v   値
     * @param float $min 最小
     * @param float $max 最大
     * @return float
     */
    private function clamp_float($v, float $min, float $max): float {
        return max($min, min($max, floatval(is_scalar($v) ? $v : 0)));
    }

    /**
     * 16進カラーを検証
     *
     * @param mixed  $v       値
     * @param string $default 既定
     * @return string
     */
    private function sanitize_hex($v, string $default): string {
        $v = sanitize_hex_color(is_scalar($v) ? (string) $v : '');
        return $v ? $v : $default;
    }

    /**
     * 改行区切りの行をサニタイズ（タグ除去・空行除去）
     *
     * @param mixed $raw 生
     * @return string
     */
    private function sanitize_lines($raw): string {
        $raw   = is_scalar($raw) ? (string) $raw : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $clean = [];
        foreach ((array) $lines as $line) {
            $line = (string) $line;
            // タグのみ除去する。%XX（パーセントエンコード）は URL の一部なので保持する
            // （sanitize_textarea_field は %xx を「オクテット」として削除するため使わない）。
            if (function_exists('wp_strip_all_tags')) {
                $line = wp_strip_all_tags($line, false);
            }
            $line = preg_replace('/[\x00-\x1F\x7F]/', '', $line);
            $line = trim($line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }
        return implode("\n", array_unique($clean));
    }

    /**
     * 画像URLを決める。明示的なURL（別CDN等）があればそれを優先し、
     * 無ければ添付IDから解決する。IDが無効（別サイトからのインポート等）で
     * URLも無い場合は空文字。
     *
     * @param mixed  $image_id 添付ID
     * @param mixed  $url      入力のURL
     * @return string
     */
    private function resolve_image_url($image_id, $url): string {
        $url = esc_url_raw(is_scalar($url) ? (string) $url : '');
        if ($url !== '') {
            return $url;
        }
        $id = intval(is_scalar($image_id) ? $image_id : 0);
        if ($id > 0) {
            $resolved = wp_get_attachment_image_url($id, 'large');
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }
        return '';
    }

    /**
     * 列挙値を安全に取り出す（配列などスカラー以外は既定値にする）
     *
     * @param mixed  $value   入力値
     * @param array  $allowed 許可キー（key=>label）
     * @param string $default 既定キー
     * @return string
     */
    private function enum_key($value, array $allowed, string $default): string {
        if (!is_scalar($value)) {
            return $default;
        }
        $value = (string) $value;
        return array_key_exists($value, $allowed) ? $value : $default;
    }

    /**
     * ID配列をサニタイズ
     *
     * @param mixed $raw 生
     * @return int[]
     */
    private function sanitize_ids($raw): array {
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw), static function ($v) {
            return $v > 0;
        })));
    }

    /**
     * キーの配列をサニタイズ
     *
     * @param mixed $raw 生
     * @return string[]
     */
    private function sanitize_keys($raw): array {
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_map('sanitize_key', array_filter($raw, 'is_scalar'))));
    }

    /**
     * datetime-local の値を検証
     *
     * @param mixed $raw 生
     * @return string
     */
    private function sanitize_datetime($raw): string {
        $raw = trim(is_scalar($raw) ? (string) $raw : '');
        if ($raw === '') {
            return '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw)) {
            return '';
        }
        // カレンダー上ありえない値（例: 2026-13-99T99:99）を弾く
        $dt  = DateTime::createFromFormat('Y-m-d\TH:i', $raw);
        $err = DateTime::getLastErrors();
        if ($dt === false || (is_array($err) && ($err['warning_count'] > 0 || $err['error_count'] > 0))) {
            return '';
        }
        return $raw;
    }

    /* ---- 一覧からの操作 ---- */

    /**
     * 操作の前提を確かめて投稿を返す（だめなら止める）
     *
     * @param string $action nonce のアクション名（投稿IDを後ろに付ける）
     * @return WP_Post
     */
    private function require_banner(string $action): WP_Post {
        $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('この操作は行えません。', 'kashiwazaki-seo-staypop'), '', ['response' => 403]);
        }
        check_admin_referer($action . $post_id);
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_die(esc_html__('この操作は行えません。', 'kashiwazaki-seo-staypop'), '', ['response' => 404]);
        }
        return $post;
    }

    /**
     * 複製（複製したバナーは「停止中」の下書きにする。同じバナーが2つ表示されないように）
     */
    public function handle_duplicate(): void {
        $post   = $this->require_banner('kashiwazaki_staypop_duplicate_');
        $new_id = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'draft',
            'post_title'  => sprintf(__('%s のコピー', 'kashiwazaki-seo-staypop'), $post->post_title),
        ]);
        if (!$new_id || is_wp_error($new_id)) {
            wp_die(esc_html__('複製できませんでした。', 'kashiwazaki-seo-staypop'));
        }
        $config            = $this->get_config($post->ID);
        $config['enabled'] = false;
        // update_post_meta は保存時に wp_unslash するため wp_slash で保護する
        update_post_meta($new_id, self::META_KEY, wp_slash($config));
        wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_id));
        exit;
    }

    /**
     * 表示する／停止するを切り替える
     */
    public function handle_toggle(): void {
        $post              = $this->require_banner('kashiwazaki_staypop_toggle_');
        $config            = $this->get_config($post->ID);
        $config['enabled'] = empty($config['enabled']);
        update_post_meta($post->ID, self::META_KEY, wp_slash($config));
        Kashiwazaki_SEO_StayPop_Admin::queue_notice(
            $config['enabled']
                ? sprintf(__('「%s」を表示する設定にしました。', 'kashiwazaki-seo-staypop'), $post->post_title)
                : sprintf(__('「%s」を停止しました。', 'kashiwazaki-seo-staypop'), $post->post_title)
        );
        wp_safe_redirect(admin_url('admin.php?page=' . Kashiwazaki_SEO_StayPop_Admin::MENU_SLUG . '&tab=banners'));
        exit;
    }
}
