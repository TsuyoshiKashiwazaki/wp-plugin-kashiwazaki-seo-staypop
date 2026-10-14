<?php
/**
 * 表示条件の判定（サーバー側）
 *
 * @package Kashiwazaki_SEO_StayPop
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 条件判定クラス
 */
class Kashiwazaki_SEO_StayPop_Conditions {

    /**
     * 全体設定
     *
     * @var Kashiwazaki_SEO_StayPop_Settings
     */
    private Kashiwazaki_SEO_StayPop_Settings $settings;

    /**
     * コンストラクタ
     *
     * @param Kashiwazaki_SEO_StayPop_Settings $settings 設定
     */
    public function __construct(Kashiwazaki_SEO_StayPop_Settings $settings) {
        $this->settings = $settings;
    }

    /**
     * 現在のページパスを取得
     *
     * @return string
     */
    public function current_path(): string {
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = wp_parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * 現在のURLがパターンに一致するか
     *
     * @param string $path     現在のパス
     * @param string $patterns 改行区切りパターン
     * @return bool
     */
    public function match_patterns(string $path, string $patterns): bool {
        // リクエストのパスはパーセントエンコードされていることがある（例: /%E6%97%A5%E6%9C%AC%E8%AA%9E/）。
        // パターン側の非ASCII文字をパーセントエンコードした形でも照合する（デコードはしない:
        // %2F などの予約文字をデータとして扱うルーティングを壊さないため）。16進数字の大小は同一視する。
        $norm = static function ($s) {
            return preg_replace_callback('/%[0-9A-Fa-f]{2}/', static function ($m) {
                return strtolower($m[0]);
            }, $s);
        };
        $path_c = $norm($path);
        $lines  = preg_split('/\r\n|\r|\n/', $patterns);
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            // 完全なURLで指定された場合はパス部分だけにして判定する
            // （パス成分が無い「https://example.com」はホーム「/」として扱う）
            if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $line)) {
                $parsed = wp_parse_url($line, PHP_URL_PATH);
                $line = (is_string($parsed) && $parsed !== '') ? $parsed : '/';
                // JS の new URL はドット区間（. / ..）を解決するため、PHP 側も合わせる
                $line = $this->normalize_dot_segments($line);
            }
            // 非ASCII文字をパーセントエンコードした形も候補にする
            $variants = [$line];
            $encoded  = preg_replace_callback('/[^\x00-\x7F]/', static function ($m) {
                return rawurlencode($m[0]);
            }, $line);
            if (is_string($encoded) && $encoded !== $line) {
                $variants[] = $encoded;
            }
            foreach ($variants as $v) {
                $v_c = $norm($v);
                if (strpos($v, '*') !== false) {
                    $regex = '#^' . str_replace('\*', '.*', preg_quote($v_c, '#')) . '$#i';
                    if (preg_match($regex, $path_c)) {
                        return true;
                    }
                } else {
                    // 完全一致、または前方一致（"/" はホームのみに一致させる）
                    if ($path_c === $v_c) {
                        return true;
                    }
                    if ($v_c !== '/' && strpos($path_c, rtrim($v_c, '/') . '/') === 0) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * パスのドット区間（. と ..）を解決する（JS の new URL().pathname に合わせる）
     *
     * @param string $path パス
     * @return string
     */
    private function normalize_dot_segments(string $path): string {
        // リテラルのドット区間（. と ..）のみを RFC 3986 §5.2.4 に従って解決する。
        // 早期 return はせず常に通す（入出力が同じなら実質コストは軽微で、末尾の '..' 等を取りこぼさない）。
        // （%2e 表記のドット区間は対象外。JS の new URL との既知の差。）
        $input = $path;
        $out   = '';
        while ($input !== '') {
            if (substr($input, 0, 3) === '../') {
                $input = substr($input, 3);
            } elseif (substr($input, 0, 2) === './') {
                $input = substr($input, 2);
            } elseif (substr($input, 0, 3) === '/./') {
                $input = '/' . substr($input, 3);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (substr($input, 0, 4) === '/../') {
                $input = '/' . substr($input, 4);
                $out   = preg_replace('#/[^/]*$#', '', $out);
            } elseif ($input === '/..') {
                $input = '/';
                $out   = preg_replace('#/[^/]*$#', '', $out);
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                // 先頭の '/'（あれば）と次の '/' までの区間を出力へ移す
                if (preg_match('#^(/?[^/]*)#', $input, $m)) {
                    $out  .= $m[1];
                    $input = substr($input, strlen($m[1]));
                } else {
                    $out  .= $input;
                    $input = '';
                }
            }
        }
        return $out === '' ? '/' : $out;
    }

    /**
     * 参照元（HTTP_REFERER）がパターンに一致するか
     *
     * パターンはドメイン（例: google.com）またはフルURLで指定する。
     * ドメインは完全一致またはサフィックス一致（www.google.com は google.com に一致）で判定し、
     * 部分文字列の誤爆を避ける。`*` でワイルドカードも使える。
     *
     * @param string $ref      参照元URL
     * @param string $patterns 改行区切りパターン
     * @return bool
     */
    public function match_referrer(string $ref, string $patterns): bool {
        $host = wp_parse_url($ref, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        if ($host === '') {
            return false;
        }
        $lines = preg_split('/\r\n|\r|\n/', $patterns);
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            // スキームを外し、ホスト部分（最初の / まで）だけを見る
            $line  = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $line);
            $parts = explode('/', (string) $line);
            $ph    = ltrim(strtolower(trim($parts[0])), '.');
            if ($ph === '') {
                continue;
            }
            if (strpos($ph, '*') !== false) {
                $regex = '#^' . str_replace('\*', '.*', preg_quote($ph, '#')) . '$#i';
                if (preg_match($regex, $host)) {
                    return true;
                }
            } elseif ($host === $ph || substr($host, -strlen('.' . $ph)) === '.' . $ph) {
                return true;
            }
        }
        return false;
    }

    /**
     * UTM（utm_source 等の値）がパターンに一致するか
     *
     * 値の完全一致（大小文字を無視）で判定する。カンマ区切りの値はトークン単位でも照合する。
     *
     * @param string $value    判定する値
     * @param string $patterns 改行区切りパターン
     * @return bool
     */
    public function match_utm(string $value, string $patterns): bool {
        if ($value === '') {
            return false;
        }
        $tokens = array_map('trim', explode(',', $value));
        $lines  = preg_split('/\r\n|\r|\n/', $patterns);
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (strpos($line, '*') !== false) {
                $regex = '#^' . str_replace('\*', '.*', preg_quote($line, '#')) . '$#i';
                if (preg_match($regex, $value)) {
                    return true;
                }
                continue;
            }
            if (strcasecmp($value, $line) === 0) {
                return true;
            }
            foreach ($tokens as $token) {
                if ($token !== '' && strcasecmp($token, $line) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 現在の投稿ID（単一投稿・固定ページ）
     *
     * @return int
     */
    public function current_post_id(): int {
        if (is_singular()) {
            return (int) get_queried_object_id();
        }
        return 0;
    }
    /**
     * 現在のカテゴリーID一覧
     *
     * @return int[]
     */
    public function current_category_ids(): array {
        $ids = [];
        if (is_category()) {
            $ids[] = (int) get_queried_object_id();
        }
        if (is_singular()) {
            $terms = get_the_category($this->current_post_id());
            foreach ($terms as $term) {
                $ids[] = (int) $term->term_id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * 対象ページに一致するか
     *
     * @param array  $config 設定
     * @param string $path   現在のパス
     * @return bool
     */
    public function matches_targeting(array $config, string $path): bool {
        $t = $config['targeting'];
        if (($t['pages'] ?? 'all') === 'all') {
            return true;
        }
        if (($t['urls'] ?? '') !== '' && $this->match_patterns($path, (string) $t['urls'])) {
            return true;
        }
        $post_id = $this->current_post_id();
        if ($post_id && in_array($post_id, array_map('intval', (array) $t['post_ids']), true)) {
            return true;
        }
        if ($post_id && in_array($post_id, array_map('intval', (array) $t['page_ids']), true)) {
            return true;
        }
        $cats = $this->current_category_ids();
        if (!empty($cats) && array_intersect($cats, array_map('intval', (array) $t['categories']))) {
            return true;
        }
        return false;
    }

    /**
     * 除外ページに一致するか
     *
     * @param array  $config 設定
     * @param string $path   現在のパス
     * @return bool
     */
    public function matches_exclusion(array $config, string $path): bool {
        $e = $config['exclusion'];
        if (($e['urls'] ?? '') !== '' && $this->match_patterns($path, (string) $e['urls'])) {
            return true;
        }
        $post_id = $this->current_post_id();
        if ($post_id && in_array($post_id, array_map('intval', (array) $e['post_ids']), true)) {
            return true;
        }
        if ($post_id && in_array($post_id, array_map('intval', (array) $e['page_ids']), true)) {
            return true;
        }
        return false;
    }

    /**
     * 掲載期間内か
     *
     * @param array $config 設定
     * @return bool
     */
    public function in_schedule(array $config): bool {
        $s  = $config['schedule'];
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now = new DateTime('now', $tz);
        if (!empty($s['start'])) {
            $start = DateTime::createFromFormat('Y-m-d\TH:i', $s['start'], $tz);
            $err   = DateTime::getLastErrors();
            if ($start && (!is_array($err) || ($err['warning_count'] === 0 && $err['error_count'] === 0)) && $now < $start) {
                return false;
            }
        }
        if (!empty($s['end'])) {
            $end = DateTime::createFromFormat('Y-m-d\TH:i', $s['end'], $tz);
            $err = DateTime::getLastErrors();
            if ($end && (!is_array($err) || ($err['warning_count'] === 0 && $err['error_count'] === 0)) && $now > $end) {
                return false;
            }
        }
        return true;
    }

    /**
     * 訪問者属性（ログイン・権限・流入元）に一致するか
     *
     * @param array $config 設定
     * @return bool
     */
    public function matches_audience(array $config): bool {
        $a = $config['audience'];
        $state = $a['login_state'] ?? 'any';
        if ($state === 'logged_in' && !is_user_logged_in()) {
            return false;
        }
        if ($state === 'logged_out' && is_user_logged_in()) {
            return false;
        }
        $roles = (array) ($a['roles'] ?? []);
        if (!empty($roles)) {
            if (!is_user_logged_in()) {
                return false;
            }
            $user = wp_get_current_user();
            if (!array_intersect($roles, (array) $user->roles)) {
                return false;
            }
        }
        // UTM（utm_source 等の値）
        if (($a['utm_sources'] ?? '') !== '') {
            $utm_raw = isset($_GET['utm_source']) ? sanitize_text_field(wp_unslash($_GET['utm_source'])) : '';
            if ($utm_raw === '') {
                return false;
            }
            if (!$this->match_utm($utm_raw, (string) $a['utm_sources'])) {
                return false;
            }
        }
        // 参照元
        if (($a['referrers'] ?? '') !== '') {
            $ref = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '';
            if ($ref === '') {
                return false;
            }
            if (!$this->match_referrer($ref, (string) $a['referrers'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * 現在のページで表示候補になるバナー（優先度の高い順）
     *
     * @param Kashiwazaki_SEO_StayPop_Banners $banners バナー管理
     * @return int[]
     */
    public function eligible_banner_ids(Kashiwazaki_SEO_StayPop_Banners $banners): array {
        $path = $this->current_path();
        $eligible = [];
        foreach ($banners->get_active_banner_ids() as $id) {
            $config = $banners->get_config($id);
            if (!$this->matches_targeting($config, $path)) {
                continue;
            }
            if ($this->matches_exclusion($config, $path)) {
                continue;
            }
            if (!$this->in_schedule($config)) {
                continue;
            }
            if (!$this->matches_audience($config)) {
                continue;
            }
            $eligible[] = $id;
        }
        // 優先度の降順（同点はID昇順）
        usort($eligible, function ($a, $b) use ($banners) {
            $pa = (int) $banners->get_config($a)['priority'];
            $pb = (int) $banners->get_config($b)['priority'];
            return $pa === $pb ? $a <=> $b : $pb <=> $pa;
        });
        return $eligible;
    }

    /**
     * 計測を送信してよいか（同意設定・Do Not Track）
     *
     * @return bool
     */
    public function analytics_allowed(): bool {
        if (!$this->settings->is_analytics_enabled()) {
            return false;
        }
        // Does Not Track
        if (!empty($this->settings->get('respect_dnt'))) {
            $dnt = isset($_SERVER['HTTP_DNT']) ? (string) $_SERVER['HTTP_DNT'] : '';
            if ($dnt === '1') {
                return false;
            }
        }
        // 同意 Cookie（同意が必須なら、Cookie 名が未設定/未同意の場合は計測しない）
        // フェイルクローズ: 同意を要求する設定なのに判定できないときは「同意なし」として扱う。
        $consent_mode = (string) $this->settings->get('consent_mode');
        $cookie_name  = (string) $this->settings->get('consent_cookie_name');
        if ($consent_mode === 'require') {
            if ($cookie_name === '') {
                return false;
            }
            $value = isset($_COOKIE[$cookie_name]) ? sanitize_text_field(wp_unslash((string) $_COOKIE[$cookie_name])) : '';
            if (!self::is_consent_value($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 同意 Cookie の値が「同意した」を表すか。
     * 拒否のときも Cookie を書く同意ツールがある（例: Cookie Notice は拒否で false を書く）ため、
     * 空・否定を表す値は同意とみなさない。
     *
     * @param string $value Cookie の値
     * @return bool
     */
    public static function is_consent_value(string $value): bool {
        $v = strtolower(trim($value));
        $negative = ['', '0', 'false', 'no', 'off', 'n', 'deny', 'denied', 'reject', 'rejected', 'decline', 'declined', 'null', 'undefined'];
        return !in_array($v, $negative, true);
    }
}
