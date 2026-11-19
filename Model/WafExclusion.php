<?php
/**
 * WafExclusion.php
 *
 * データベーステーブルを使用せず、対象 .htaccess パスの自動判定、サーバー環境診断、
 * WAF（SiteGuard Lite / ModSecurity）除外ディレクティブの生成・書き込み・パージを統合管理するモデルクラス
 *
 * @package    WafExclusion
 * @subpackage Model
 * @author     HATTA <https://hattantoco.com>
 * @license    MIT License
 * @link       https://github.com/hattantoco
 */
App::uses('AppModel', 'Model');

class WafExclusion extends AppModel {

    /**
     * データベーステーブルの使用有無
     *
     * @var bool
     */
    public $useTable = false;

    /**
     * サーバーの公開ルートおよびディレクトリ構成に応じて、WAFが最初に評価する .htaccess のパスを自動特定する
     *
     * @return string
     */
    public function getTargetHtaccessPath() {
        $rootHtaccess = ROOT . DS . '.htaccess';
        $webrootHtaccess = WWW_ROOT . '.htaccess';

        // 1. 配布パッケージ版（app/webroot が存在せず ROOT と WWW_ROOT が同一）の場合はインストールルート直下
        if (!is_dir(ROOT . DS . 'app' . DS . 'webroot')) {
            return $webrootHtaccess;
        }

        // 2. GitHub版等でドキュメントルートと WWW_ROOT が一致している場合（app/webroot 公開構成）
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)realpath($_SERVER['DOCUMENT_ROOT']), '/\\') : '';
        $webRoot = rtrim((string)realpath(WWW_ROOT), '/\\');
        if ($docRoot !== '' && $docRoot === $webRoot) {
            return $webrootHtaccess;
        }

        // 3. インストールルート直下に app/webroot/ へ転送する .htaccess がある場合はそちらを優先
        if (file_exists($rootHtaccess)) {
            $rootContent = (string)file_get_contents($rootHtaccess);
            if (strpos($rootContent, 'app/webroot/') !== false || strpos($rootContent, 'webroot/') !== false) {
                return $rootHtaccess;
            }
        }

        // 4. 上記以外は標準の WWW_ROOT/.htaccess を使用
        return $webrootHtaccess;
    }

    /**
     * 現在の設定値（setting.php）を安全に読み込む
     *
     * @param string $settingPath
     * @return array
     */
    public function loadSetting($settingPath) {
        $defaults = array(
            'target_group_ids' => array(1),
            'waf_type' => 'siteguard',
            'force_waf' => false,
        );

        if (file_exists($settingPath)) {
            $config = array();
            include $settingPath;
            if (isset($config['WafExclusion']) && is_array($config['WafExclusion'])) {
                $defaults = array_merge($defaults, $config['WafExclusion']);
            }
        }

        $defaults['waf_type'] = ($defaults['waf_type'] === 'modsec') ? 'modsec' : 'siteguard';
        $defaults['force_waf'] = !empty($defaults['force_waf']);

        return $defaults;
    }

    /**
     * サーバー環境とWAF書き込み可否を診断する
     *
     * @param string $htaccessPath
     * @param string $wafType 'siteguard' または 'modsec'
     * @param bool $forceWaf 強制有効フラグ
     * @return array
     */
    public function getServerDiagnostics($htaccessPath, $wafType = 'siteguard', $forceWaf = false) {
        $isHtaccessWritable = (file_exists($htaccessPath) && is_writable($htaccessPath));

        // Apacheバージョンの検出
        $apacheVersion = '不明（非公開）';
        $isApacheOk = false;
        $serverSoftware = '';
        if (function_exists('apache_get_version')) {
            $ver = @apache_get_version();
            if ($ver) {
                $serverSoftware = (string)$ver;
            }
        }
        if ($serverSoftware === '' && isset($_SERVER['SERVER_SOFTWARE'])) {
            $serverSoftware = (string)$_SERVER['SERVER_SOFTWARE'];
        }
        if (preg_match('/Apache\/([0-9]+\.[0-9]+\.[0-9]+)/i', $serverSoftware, $matches)) {
            $apacheVersion = $matches[1];
            $isApacheOk = version_compare($apacheVersion, '2.4.26', '>=');
        }

        // ModSecurity（mod_security2）モジュールの検出
        $hasModSecurity = false;
        if (function_exists('apache_get_modules')) {
            $modules = @apache_get_modules();
            if (is_array($modules) && in_array('mod_security2', $modules, true)) {
                $hasModSecurity = true;
            }
        }
        if (!$hasModSecurity && (isset($_SERVER['MOD_SECURITY']) || isset($_SERVER['HTTP_MOD_SECURITY']))) {
            $hasModSecurity = true;
        }

        // 書き込み実行可否の総合判定
        $isWafUpdatable = false;
        if ($isHtaccessWritable) {
            if ($wafType === 'siteguard') {
                $isWafUpdatable = true;
            } elseif ($wafType === 'modsec') {
                $isWafUpdatable = ($forceWaf || ($isApacheOk && $hasModSecurity));
            }
        }

        // パーミッションの安全性チェック（Unix系OSのみ判定：その他ユーザーに書き込み権限 0002 がある場合は危険）
        $htaccessPerms = '';
        $isPermsUnsafe = false;
        if (file_exists($htaccessPath) && DIRECTORY_SEPARATOR === '/') {
            clearstatcache(true, $htaccessPath);
            $perms = @fileperms($htaccessPath);
            if ($perms !== false) {
                $htaccessPerms = substr(sprintf('%o', $perms), -3);
                if (($perms & 0x0002) !== 0) {
                    $isPermsUnsafe = true;
                }
            }
        }

        return array(
            'htaccessPath' => $htaccessPath,
            'isHtaccessWritable' => $isHtaccessWritable,
            'apacheVersion' => $apacheVersion,
            'isApacheOk' => $isApacheOk,
            'hasModSecurity' => $hasModSecurity,
            'isWafUpdatable' => $isWafUpdatable,
            'htaccessPerms' => $htaccessPerms,
            'isPermsUnsafe' => $isPermsUnsafe,
        );
    }

    /**
     * 選択中のWAF種別に応じて、指定IPがすでに .htaccess に登録済みか確認する
     *
     * @param string $htaccessPath
     * @param string $ip
     * @param string $wafType
     * @return bool
     */
    public function isIpRegistered($htaccessPath, $ip, $wafType = 'siteguard') {
        if (!file_exists($htaccessPath)) {
            return false;
        }
        $content = (string)file_get_contents($htaccessPath);
        if ($wafType === 'modsec') {
            return (strpos($content, "REMOTE_ADDR} == '{$ip}'") !== false);
        }
        return (strpos($content, "SiteGuard_User_ExcludeSig ip({$ip})") !== false);
    }

    /**
     * .htaccess に書き込むWAF除外ディレクティブブロックを生成する
     *
     * @param string $ip
     * @param string $wafType
     * @return string
     */
    public function generateWafDirective($ip, $wafType = 'siteguard') {
        if ($wafType === 'modsec') {
            return "# --- WAF_EXCLUSION_START ---\n"
                . "<IfModule mod_security2.c>\n"
                . "  <If \"%{REMOTE_ADDR} == '{$ip}'\">\n"
                . "    SecFilterEngine Off\n"
                . "    SecRuleEngine Off\n"
                . "  </If>\n"
                . "</IfModule>\n"
                . "# --- WAF_EXCLUSION_END ---\n";
        }

        return "# --- WAF_EXCLUSION_START ---\n"
            . "<IfModule mod_siteguard.c>\n"
            . "  SiteGuard_User_ExcludeSig ip({$ip})\n"
            . "</IfModule>\n"
            . "# --- WAF_EXCLUSION_END ---\n";
    }

    /**
     * 指定されたIPアドレスの除外ブロックを .htaccess へ書き込み・更新する
     *
     * @param string $htaccessPath
     * @param string $ip
     * @param string $wafType
     * @param bool $forceWaf
     * @return bool
     */
    public function updateWafTimestamp($htaccessPath, $ip, $wafType = 'siteguard', $forceWaf = false) {
        $diagnostics = $this->getServerDiagnostics($htaccessPath, $wafType, $forceWaf);
        if (!$diagnostics['isWafUpdatable']) {
            return false;
        }

        $content = (string)file_get_contents($htaccessPath);

        // 同一IPの既存ブロック（SiteGuard / ModSecurity 両形式）を除去して重複を防ぐ
        $quotedIp = preg_quote($ip, '/');
        $patternSiteGuard = '/\R*# --- WAF_EXCLUSION_START ---\s*<IfModule mod_siteguard\.c>\s*SiteGuard_User_ExcludeSig ip\(' . $quotedIp . '\)\s*<\/IfModule>\s*# --- WAF_EXCLUSION_END ---\R*/s';
        $patternModSec = '/\R*# --- WAF_EXCLUSION_START ---\s*<IfModule mod_security2\.c>\s*<If "%\{REMOTE_ADDR\} == \'' . $quotedIp . '\'">.*?<\/If>\s*<\/IfModule>\s*# --- WAF_EXCLUSION_END ---\R*/s';

        $content = (string)preg_replace($patternSiteGuard, "\n", $content);
        $content = (string)preg_replace($patternModSec, "\n", $content);

        $directive = $this->generateWafDirective($ip, $wafType);

        $marker = '# [WAF_EXCLUSION_MARKER]';
        if (strpos($content, $marker) !== false) {
            $content = str_replace($marker, $marker . "\n\n" . $directive, $content);
        } else {
            $content = $directive . $content;
        }

        // 改行クレンジング
        $content = (string)preg_replace('/(?:\r\n|\r|\n){3,}/', "\n\n", $content);

        $result = (file_put_contents($htaccessPath, $content, LOCK_EX) !== false);
        clearstatcache(true, $htaccessPath);

        return $result;
    }

    /**
     * .htaccess 内のすべての WAF_EXCLUSION ブロックを一括消去（パージ）する
     *
     * @param string $htaccessPath
     * @return bool
     */
    public function purgeAll($htaccessPath) {
        if (!file_exists($htaccessPath) || !is_writable($htaccessPath)) {
            return false;
        }

        $content = (string)file_get_contents($htaccessPath);
        $pattern = '/\R*# --- WAF_EXCLUSION_START ---.*?# --- WAF_EXCLUSION_END ---\R*/s';
        $content = (string)preg_replace($pattern, "\n", $content);
        $content = (string)preg_replace('/(?:\r\n|\r|\n){3,}/', "\n\n", $content);

        $result = (file_put_contents($htaccessPath, $content, LOCK_EX) !== false);
        clearstatcache(true, $htaccessPath);

        return $result;
    }
}
