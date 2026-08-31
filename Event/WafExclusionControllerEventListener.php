<?php
/**
 * WafExclusionControllerEventListener.php
 *
 * 管理画面へのアクセスおよび各種ライフサイクルをフックし、フロントJSのロードやプラグイン無効化時のクリーンアップを実行するイベントリスナークラス
 *
 * @package    WafExclusion
 * @subpackage Event
 * @author     HATTA <https://hattantoco.com>
 * @license    MIT License
 * @link       https://github.com/hattantoco
 */
App::uses('BcControllerEventListener', 'Event');

class WafExclusionControllerEventListener extends BcControllerEventListener {

    /**
     * 登録するイベント
     *
     * @var array
     */
    public $events = array(
        'Controller.startup' => 'onStartup',
        'View.beforeRender'  => 'onBeforeRender', 
        'Controller.shutdown' => 'shutdown',
    );

    /**
     * .htaccess ファイルの物理パス
     *
     * @var string
     */
    private $htaccessPath = WWW_ROOT . '.htaccess';

    /**
     * フレームワークへイベントを登録
     *
     * @return array
     */
    public function implementedEvents() {
        return array(
            'Controller.startup' => 'onStartup',
            'View.beforeRender'  => 'onBeforeRender',
            'Controller.shutdown' => 'shutdown',
        );
    }

    /**
     * プラグイン一覧画面の描画終了時に割り込み、無効化時のクリーンアップJSを注入
     * 
     * CKEditorのテンプレート読み込み等の非HTMLパケットを安全に除外した上で処理を実行します。
     * 
     * @param CakeEvent $event
     * @return void
     */
    public function shutdown(CakeEvent $event) {
        $controller = $event->subject();

        // 1. レスポンスが空の場合は何も処理しない
        $responseBody = $controller->response->body();
        if (empty($responseBody)) {
            return;
        }
        // 2. Content-Type が明示的に html ではない場合は終了
        $contentType = $controller->response->type();
        if (strpos($contentType, 'html') === false) {
            return; 
        }
        // 3. ヘッダーの誤認を中身の文字列で二重ガード
        $trimmedBody = ltrim($responseBody);
        if (strpos($trimmedBody, '<') !== 0 || strpos($trimmedBody, 'CKEDITOR') === 0) {
            return;
        }

        // 管理画面の通常アクセス（Ajax以外）の時だけ実行
        if (BcUtil::isAdminSystem() && !$controller->request->is('ajax')) {
            
            // 対象ページ判定
            $isPluginPage = ($controller->name === 'Plugins') ? 'true' : 'false';
            
            // 管理者権限チェック
            $loginUser = BcUtil::loginUser('admin');
            if (!$loginUser) {
                return;
            }

            $adminPrefix = Configure::read('Routing.prefixes.0');
            $baseUrl = $controller->request->base;
            $resetUrl = $baseUrl . '/' . $adminPrefix . '/waf_exclusion/waf_exclusion/reset_all';

            $jsBlock = "";
            $jsBlock .= "\n<script type=\"text/javascript\">\n";
            $jsBlock .= "document.addEventListener('DOMContentLoaded', function() {\n";
            $jsBlock .= "    if ({$isPluginPage}) {\n";
            $jsBlock .= "        // 1. WafExclusionの無効化ボタンを正確に狙い撃ち\n";
            $jsBlock .= "        var deleteBtn = document.querySelector('a.btn-delete[href*=\"WafExclusion\"], a.btn-delete[href*=\"waf_exclusion\"]');\n";
            $jsBlock .= "        \n";
            $jsBlock .= "        if (deleteBtn) {\n";
            $jsBlock .= "            // 2. 第3引数を true（イベントキャプチャ）に指定し、コアの処理より「絶対に先」に割り込む\n";
            $jsBlock .= "            deleteBtn.addEventListener('click', function(e) {\n";
            $jsBlock .= "                var currentElement = this;\n";
            $jsBlock .= "                \n";
            $jsBlock .= "                // 💡 既に先行処理が通過したマーク（二重実行防止）があればスルー\n";
            $jsBlock .= "                if (currentElement.dataset.wafResetPassed) return;\n";
            $jsBlock .= "                \n";
            $jsBlock .= "                // 後続のイベント（コアのアラートなど）を一旦ストップさせる\n";
            $jsBlock .= "                e.stopImmediatePropagation();\n";
            $jsBlock .= "                e.preventDefault();\n";
            $jsBlock .= "                \n";
            $jsBlock .= "                var isConfirm = confirm('WafExclusion プラグインを無効化（削除）する準備を行います。\\n\\nよろしいですか？');\n";
            $jsBlock .= "                \n";
            $jsBlock .= "                if (isConfirm) {\n";
            $jsBlock .= "                    // POST配信用にFormDataを作成（jQueryと同じデータをセット）\n";
            $jsBlock .= "                    var formData = new FormData();\n";
            $jsBlock .= "                    formData.append('waf_delete_beacon', '1');\n";
            $jsBlock .= "                    \n";
            $jsBlock .= "                    // 💡 async:false の代わりに、安全な非同期 fetch を実行\n";
            $jsBlock .= "                    fetch('" . $resetUrl . "', {\n";
            $jsBlock .= "                        method: 'POST',\n";
            $jsBlock .= "                        credentials: 'same-origin', // ログインセッションを維持\n";
            $jsBlock .= "                        body: formData\n";
            $jsBlock .= "                    })\n";
            $jsBlock .= "                    .then(function() {\n";
            $jsBlock .= "                        // 登録IPの削除が正常完了したら、通過マークを付与してボタンを再クリック\n";
            $jsBlock .= "                        currentElement.dataset.wafResetPassed = 'true';\n";
            $jsBlock .= "                        currentElement.click();\n";
            $jsBlock .= "                    })\n";
            $jsBlock .= "                    .catch(function(error) {\n";
            $jsBlock .= "                        console.error('[WafExclusion] Reset Error:', error);\n";
            $jsBlock .= "                        currentElement.dataset.wafResetPassed = 'true';\n";
            $jsBlock .= "                        currentElement.click();\n";
            $jsBlock .= "                    });\n";
            $jsBlock .= "                }\n";
            $jsBlock .= "            }, true); // 💡 これで $._data を使わずに最優先実行されます\n";
            $jsBlock .= "        }\n";
            $jsBlock .= "    }\n";
            $jsBlock .= "});\n";
            $jsBlock .= "</script>\n";

            // 生成されたJSブロックを HTML の </body> 手前に注入
            $responseBody = $controller->response->body();
            if (!empty($responseBody)) {
                if (strpos($responseBody, '</body>') !== false) {
                    $newBody = str_replace('</body>', $jsBlock . '</body>', $responseBody);
                    $controller->response->body($newBody);
                } else {
                    $controller->response->body($responseBody . $jsBlock);
                }
            }
        }
    }

    /**
     * フロントからの通信や全管理画面アクセスをフックするルーティング監視
     * 
     * @param CakeEvent $event
     * @return void
     */
    public function onStartup($event) {
        $controller = $event->subject();

        if (isset($controller->request->params['prefix']) && $controller->request->params['prefix'] === 'admin') {
            
            // 設定画面自体のときはファイルI/O負荷を与えないためスキップ
            $currentController = Inflector::camelize($controller->request->params['controller']);
            if ($currentController === 'WafExclusion') {
                return;
            }

            // A. ダミー通信（設定値の取得）が届いたとき
            if (!empty($controller->request->data['waf_get_interval'])) {
                // 未ログインの不正リクエストを遮断
                $loginUser = BcUtil::loginUser('admin');
                if (!$loginUser) {
                    $controller->response->statusCode(403);
                    $controller->response->send();
                    exit();
                }
                $clientIp = env('REMOTE_ADDR');
                $hash = md5($clientIp);
                $status = 'verified';

                if (file_exists($this->htaccessPath)) {
                    $content = file_get_contents($this->htaccessPath);
                    if (strpos($content, "SiteGuard_User_ExcludeSig ip({$clientIp})") === false) {
                        $status = 'missing';
                    }
                }

                $controller->response->type('json');
                $controller->response->body(json_encode(array(
                    'status' => $status
                )));
                $controller->response->send();
                exit();
            }

            // B. 本番の寿命延長ビーコンが届いたとき
            if (!empty($controller->request->data['waf_action_beacon'])) {
                $loginUser = BcUtil::loginUser('admin');
                
                if (!$loginUser) {
                    $controller->response->statusCode(403);
                    $controller->response->send();
                    exit();
                }

                $ip = env('REMOTE_ADDR');
                if ($ip) {
                    if (method_exists($this, 'runGarbageCollection')) {
                        $this->runGarbageCollection($ip);
                    }
                    $this->updateWafTimestamp($ip);
                }

                $controller->response->type('json');
                $controller->response->body(json_encode(array(
                    'status' => 'registered'
                )));
                $controller->response->send();
                exit();
            }
        }
    }

    /**
     * 管理画面の全ページ描画時にフロントマネージャーJSを安全にロード
     * 
     * @param CakeEvent $event
     * @return void
     */
    public function onBeforeRender($event) {
        $view = $event->subject();
        
        if (isset($view->request->params['prefix']) && $view->request->params['prefix'] === 'admin') {
            if (isset($view->BcBaser)) {
                $view->BcBaser->js('WafExclusion.waf_front_manager', false);
            } else {
                $jsUrl = Router::url('/waf_exclusion/js/waf_front_manager.js');
                $view->addScript("<script type=\"text/javascript\" src=\"{$jsUrl}\"></script>\n");
            }
        }
    }

    /**
     * .htaccessに書き込むWAF除外ディレクティブブロックを生成
     *
     * @param string $ip 対象のIPアドレス
     * @return string 生成されたディレクティブ文字列
     */
    private function generateWafDirective($ip) {
        return "# --- WAF_EXCLUSION_START ---\n<IfModule mod_siteguard.c>\n  SiteGuard_User_ExcludeSig ip({$ip})\n</IfModule>\n# --- WAF_EXCLUSION_END ---\n";
    }

    /**
     * 指定されたIPアドレスのブロックを.htaccessへ書き込み・更新
     *
     * @param string $ip 対象のIPアドレス
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

            $content = preg_replace('/(?:\r\n|\r|\n){3,}/', "\n\n", $content);

            file_put_contents($this->htaccessPath, $content, LOCK_EX);
            clearstatcache(true, $this->htaccessPath);
        }
    }

    /**
     * 一括全消去型ガベージコレクション
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

        // 改行クレンジング
        $content = preg_replace('/(?:\r\n|\r|\n){3,}/', "\n\n", $content);

        file_put_contents($this->htaccessPath, $content, LOCK_EX);
        clearstatcache(true, $this->htaccessPath);
    }
}
