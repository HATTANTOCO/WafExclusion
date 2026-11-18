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
     * 対象 .htaccess ファイルの物理パス
     *
     * @var string
     */
    private $htaccessPath;

    /**
     * 認証ガード
     *
     * @return void
     */
    public function beforeFilter() {
        parent::beforeFilter();
        $this->htaccessPath = $this->WafExclusion->getTargetHtaccessPath();
        if (isset($this->RequestHandler) && !$this->RequestHandler->isAjax() && !isset($this->request->params['prefix'])) {
            $this->redirect('/');
        }
    }

    /**
     * フロントからの非同期通信（Ajax）を受け止めるエンドポイントアクション
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

        // 設定読み込みと許可グループIDの検証
        $currentSetting = $this->WafExclusion->loadSetting($this->settingPath);
        $targetGroupIds = array_map('intval', (array)$currentSetting['target_group_ids']);
        if (!in_array(1, $targetGroupIds, true)) {
            $targetGroupIds[] = 1;
        }

        if (!in_array((int)$loginUser['user_group_id'], $targetGroupIds, true)) {
            $this->autoRender = false;
            $this->response->statusCode(403);
            $this->response->send();
            exit();
        }

        $wafType = $currentSetting['waf_type'];
        $forceWaf = $currentSetting['force_waf'];
        $status = 'idle';

        if (!empty($this->request->data['waf_action_beacon'])) {
            $ip = (string)env('REMOTE_ADDR');
            if ($ip !== '') {
                $updated = $this->WafExclusion->updateWafTimestamp(
                    $this->htaccessPath,
                    $ip,
                    $wafType,
                    $forceWaf
                );
                $status = $updated ? 'registered' : 'skipped';
            }
        } elseif (!empty($this->request->data['waf_get_interval'])) {
            $clientIp = (string)env('REMOTE_ADDR');
            $status = 'missing';

            if ($clientIp !== '' && $this->WafExclusion->isIpRegistered($this->htaccessPath, $clientIp, $wafType)) {
                $status = 'verified';
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
}
