<?php
/**
 * WafExclusionApiController.php
 *
 * フロントJavaScriptからの軽量確認（Ajax）およびIP自動登録ビーコンを受け止めるAPI専用コントローラークラス
 *
 * @package    WafExclusion
 * @subpackage Controller
 * @author     HATTA <https://hattantoco.com>
 * @license    MIT License
 * @link       https://github.com/hattantoco
 */
App::uses('AppController', 'Controller');

class WafExclusionApiController extends AppController {

    /**
     * 使用するモデル
     *
     * @var array
     */
    public $uses = array('WafExclusion.WafExclusion');

    /**
     * プラグイン設定ファイルの物理パス
     *
     * @var string
     */
    private $settingPath = APP . 'Plugin' . DS . 'WafExclusion' . DS . 'Config' . DS . 'setting.php';

    /**
     * .htaccess ファイルの物理パス
     *
     * @var string
     */
    private $htaccessPath = WWW_ROOT . '.htaccess';

    /**
     * 認証ガード
     * 
     * 管理画面用プレフィックスの有無およびAjaxリクエストの安全性を確認します。
     *
     * @return void
     */
    public function beforeFilter() {
        parent::beforeFilter();
        if (isset($this->RequestHandler) && !$this->RequestHandler->isAjax() && !isset($this->request->params['prefix'])) {
            $this->redirect('/');
        }
    }

    /**
     * フロントからの非同期通信（Ajax）を受け止めるエンドポイントアクション
     * 
     * 軽量ダミー確認（状態チェック）および本番の登録・自動復活ビーコンを処理します。
     *
     * @return void
     */
    public function admin_beacon() {
        App::uses('BcUtil', 'Utility');
        $loginUser = BcUtil::loginUser('admin');
        
        // 未ログイン時のアクセス遮断
        if (!$loginUser) {
            $this->autoRender = false;
            $this->response->statusCode(403);
            $this->response->send();
            exit();
        }

        // 許可されたグループIDの検証
        $targetGroupIds = array(1);
        if (file_exists($this->settingPath)) {
            include $this->settingPath;
            if (isset($config['WafExclusion']['target_group_ids'])) {
                $targetGroupIds = array_map('intval', (array)$config['WafExclusion']['target_group_ids']);
            }
        }

        // 対象グループ外のアクセス遮断
        if (!in_array((int)$loginUser['user_group_id'], $targetGroupIds, true)) {
            $this->autoRender = false;
            $this->response->statusCode(403);
            $this->response->send();
            exit();
        }

        $status = 'idle';

        if (!empty($this->request->data['waf_action_beacon'])) {
            $ip = env('REMOTE_ADDR');
            if ($ip) {
                if (method_exists($this, 'runGarbageCollection')) {
                    $this->runGarbageCollection($ip);
                }
                $this->updateWafTimestamp($ip);
            }
            $status = 'registered';
        } elseif (!empty($this->request->data['waf_get_interval'])) {
            $clientIp = env('REMOTE_ADDR');
            $status = 'missing';

            if (file_exists($this->htaccessPath)) {
                $content = file_get_contents($this->htaccessPath);
                if (strpos($content, "SiteGuard_User_ExcludeSig ip({$clientIp})") !== false) {
                    $status = 'verified';
                }
            }
        }

        Configure::write('debug', 0);
        $this->autoRender = false;
        $this->response->type('json');
        $this->response->body(json_encode(array(
            'status' => $status
        )));
        $this->response->send();
        exit();
    }

    /**
     * .htaccessに書き込むWAF除外ディレクティブブロックを生成
     *
     * @param string $ip 対象 of IPアドレス
     * @return string 生成されたディレクティブ文字列
     */
    private function generateWafDirective($ip) {
        return "# --- WAF_EXCLUSION_START ---\n<IfModule mod_siteguard.c>\n  SiteGuard_User_ExcludeSig ip({$ip})\n</IfModule>\n# --- WAF_EXCLUSION_END ---\n";
    }

    /**
     * 指定されたIPアドレスのブロックを.htaccessへ書き込み・更新
     *
     * @param string $ip 対象 of IPアドレス
     * @return void
     */
    private function updateWafTimestamp($ip) {
        if (file_exists($this->htaccessPath) && is_writable($this->htaccessPath)) {
            $content = file_get_contents($this->htaccessPath);
            
            $pattern = '/\R*# --- WAF_EXCLUSION_START ---\s*<IfModule mod_siteguard\.c>\s*SiteGuard_User_ExcludeSig ip\(' . preg_quote($ip, '/') . '\)\s*<\/IfModule>\s*# --- WAF_EXCLUSION_END ---\R*/s';
            $content = preg_replace($pattern, "\n", $content);

            $directive = $this->generateWafDirective($ip);
            
            $marker = '# [WAF_EXCLUSION_MARKER]';
            if (strpos($content, $marker) !== false) {
                $content = str_replace($marker, $marker . "\n\n" . $directive, $content);
            } else {
                $content = $directive . $content;
            }
            
            // 改行クレンジング
            $content = preg_replace('/(?:\r\n|\r|\n){3,}/', "\n\n", $content);

            file_put_contents($this->htaccessPath, $content, LOCK_EX);
            clearstatcache(true, $this->htaccessPath);
        }
    }

    /**
     * 一括全消去型ガベージコレクション
     * 
     * .htaccess 内に登録されている自動登録ブロックを一括でパージします。
     *
     * @return void
     */
    private function runGarbageCollection($ip) {
        if (!file_exists($this->htaccessPath) || !is_writable($this->htaccessPath)) {
            return;
        }

        $content = file_get_contents($this->htaccessPath);
        
        $pattern = '/\s*# --- WAF_EXCLUSION_START ---\s*<IfModule mod_siteguard\.c>\s*SiteGuard_User_ExcludeSig ip\(' . preg_quote($ip, '/') . '\)\s*<\/IfModule>\s*# --- WAF_EXCLUSION_END ---\s*/s';
        $content = preg_replace($pattern, '', $content);

        $content = preg_replace('/(?:\r\n|\r|\n){3,}/', "\n\n", $content);

        file_put_contents($this->htaccessPath, $content, LOCK_EX);
        clearstatcache(true, $this->htaccessPath);
    }
}
