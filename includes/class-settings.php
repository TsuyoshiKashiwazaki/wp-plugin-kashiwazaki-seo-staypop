<?php
/**
 * 全体設定管理クラス
 *
 * @package Kashiwazaki_SEO_StayPop
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 全体設定管理クラス
 */
class Kashiwazaki_SEO_StayPop_Settings {

    /**
     * オプション名
     */
    public const OPTION_NAME = 'kashiwazaki_seo_staypop_settings';

    /**
     * 設定値のキャッシュ
     *
     * @var array|null
     */
    private ?array $settings = null;

    /**
     * デフォルト設定
     *
     * @return array
     */
    public function get_defaults(): array {
        return [
            'analytics_enabled'    => true,   // 表示・クリック・成果の計測を有効にする
            'consent_mode'         => 'always', // always | require（同意がある場合のみ計測）
            'consent_cookie_name'  => '',      // 同意判定に使う Cookie 名（任意）
            'stats_retention_days' => 180,     // ログの保持日数
            'respect_dnt'          => true,    // Do Not Track を尊重する
        ];
    }

    /**
     * 全設定を取得
     *
     * @return array
     */
    public function get_all(): array {
        if ($this->settings === null) {
            $saved          = get_option(self::OPTION_NAME, []);
            $this->settings = wp_parse_args(is_array($saved) ? $saved : [], $this->get_defaults());
        }
        return $this->settings;
    }

    /**
     * 設定値を取得
     *
     * @param string $key     キー
     * @param mixed  $default 既定値
     * @return mixed
     */
    public function get(string $key, $default = null) {
        $all = $this->get_all();
        return $all[$key] ?? $default;
    }

    /**
     * 計測が有効か
     *
     * @return bool
     */
    public function is_analytics_enabled(): bool {
        return !empty($this->get('analytics_enabled'));
    }

    /**
     * 設定を保存する（サニタイズ込み）
     *
     * @param array $input unslash 済みの入力
     * @return bool
     */
    public function save(array $input): bool {
        $defaults = $this->get_defaults();
        $sanitized = [
            'analytics_enabled'    => !empty($input['analytics_enabled']),
            'consent_mode'         => in_array(($input['consent_mode'] ?? ''), ['always', 'require'], true)
                ? $input['consent_mode'] : $defaults['consent_mode'],
            'consent_cookie_name'  => sanitize_text_field((string) ($input['consent_cookie_name'] ?? '')),
            'stats_retention_days' => max(1, min(3650, intval($input['stats_retention_days'] ?? $defaults['stats_retention_days']))),
            'respect_dnt'          => !empty($input['respect_dnt']),
        ];
        $this->settings = $sanitized;
        return update_option(self::OPTION_NAME, $sanitized);
    }

    /**
     * 設定をリセット
     *
     * @return bool
     */
    public function reset(): bool {
        $this->settings = null;
        return delete_option(self::OPTION_NAME);
    }
}
