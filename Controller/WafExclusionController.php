<?php
/**
 * WafExclusionController.php
 *
 * WAF除外（SiteGuard Lite / ModSecurity）の設定保存、環境診断データ取得、
 * 手動メンテナンスクリア、およびログアウト時の自動パージを制御するメインコントローラークラス
 *
 * @package    WafExclusion
 * @subpackage Controller
 * @author     HATTA <https://hattantoco.com>
 * @license    MIT License
 * @link       https://github.com/hattantoco
 */
App::uses('AppController', 'Controller');

class WafExclusionController extends AppController {

    /**
     * 使用するモデル
     *
     * @var array
     */
    public $uses = array('WafExclusion.WafExclusion');

    /**
     * コンポーネントのロード
     *
     * @var array
     */
    public $components = array('BcAuth', 'Cookie', 'BcAuthConfigure', 'BcMessage');

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
     * beforeFilter
     *
     * @return void
     */
    public function beforeFilter() {
        parent::beforeFilter();
        $this->htaccessPath = $this->WafExclusion->getTargetHtaccessPath();
    }

    /**
     * [管理画面] WAF除外設定 メイン画面
     *
     * @return void
     */
    public function admin_index() {
        $this->pageTitle = 'WAF除外コントローラ設定';

        // baserCMSコアの UserGroup テーブルから画面表示名（title）のリストを取得
        $userGroups = array();
        App::uses('UserGroup', 'Model');
        $userGroupModel = ClassRegistry::init(array('class' => 'UserGroup', 'table' => 'user_groups', 'ds' => 'default'));
        if ($userGroupModel) {
            $userGroups = $userGroupModel->find('list', array(
                'fields' => array('id', 'title'),
                'order' => 'id'
            ));
        }
        $this->set('userGroups', $userGroups);

        // 現在の設定値を読み込み
        $currentSetting = $this->WafExclusion->loadSetting($this->settingPath);

        // 設定の保存処理
        if ($this->request->is('post') || $this->request->is('put')) {
            if (isset($this->request->data['WafExclusion'])) {
                $formData = $this->request->data['WafExclusion'];

                $newGroups = isset($formData['target_group_ids']) ? (array)$formData['target_group_ids'] : array(1);
                $newGroups = array_map('intval', $newGroups);
                if (!in_array(1, $newGroups, true)) {
                    array_unshift($newGroups, 1);
                }
                $newGroups = array_values(array_unique($newGroups));

                $newWafType = (isset($formData['waf_type']) && $formData['waf_type'] === 'modsec') ? 'modsec' : 'siteguard';
                $newForceWaf = !empty($formData['force_waf']);

                // WAF種別または動作モードが変更された場合は、既存の .htaccess 記述を一旦パージする
                if ($currentSetting['waf_type'] !== $newWafType || $currentSetting['force_waf'] !== $newForceWaf) {
                    $this->WafExclusion->purgeAll($this->htaccessPath);
                }

                $saveData = array(
                    'target_group_ids' => $newGroups,
                    'waf_type' => $newWafType,
                    'force_waf' => $newForceWaf,
                );

                if ($this->saveSettingFile($saveData)) {
                    $this->BcMessage->setSuccess('WAF除外の設定を保存しました。');
                    $this->Session->write('WafExclusion.just_saved', true);

                    return $this->redirect(array(
                        'plugin' => 'waf_exclusion',
                        'action' => 'index',
                        'admin' => true
                    ));
                } else {
                    $this->BcMessage->setError('設定ファイルの保存に失敗しました。パーミッションを確認してください。');
                }
            }
        }

        // フォーム表示用の初期値をセット
        $this->request->data['WafExclusion'] = $currentSetting;

        // 環境診断データを取得してビューへ渡す
        $diagnostics = $this->WafExclusion->getServerDiagnostics(
            $this->htaccessPath,
            $currentSetting['waf_type'],
            $currentSetting['force_waf']
        );
        $this->set('wafConfig', $currentSetting);
        $this->set('diagnostics', $diagnostics);
        $this->set('clientIp', (string)$this->request->clientIp());

        // .htaccess の中身をビューへ引き渡す
        $htaccessContent = '';
        if (file_exists($this->htaccessPath)) {
            $htaccessContent = (string)file_get_contents($this->htaccessPath);
        }
        $this->set('htaccessContent', $htaccessContent);

        // 保存・クリア直後のプレビュー自動リフレッシュ用フラグ
        $justSaved = (bool)$this->Session->read('WafExclusion.just_saved');
        $this->Session->delete('WafExclusion.just_saved');
        $this->set('justSaved', $justSaved);
    }

    /**
     * [管理画面] 手動メンテナンスおよびログアウト自動連動：登録されているすべての除外IPを一括クリアする
     *
     * @return void
     */
    public function admin_reset_all() {
        // ログアウト・無効化連動：フロントJavaScriptからの同期通信（Ajax）を受け止めた場合の処理
        if (isset($_POST['waf_delete_beacon']) && $_POST['waf_delete_beacon'] === '1') {
            $this->WafExclusion->purgeAll($this->htaccessPath);

            Configure::write('debug', 0);
            $this->autoRender = false;
            $this->response->type('json');
            $this->response->body(json_encode(array('status' => 'success')));
            $this->response->send();
            exit();
        }

        // 通常時：設定画面で「一括クリアボタンを押した時」の正規ルート
        if (file_exists($this->htaccessPath) && is_writable($this->htaccessPath)) {
            if ($this->WafExclusion->purgeAll($this->htaccessPath)) {
                $this->Session->write('WafExclusion.just_saved', true);
                $this->BcMessage->setSuccess('すべてのWAF除外IPアドレスの登録を一括クリアしました。');
            } else {
                $this->BcMessage->setError('.htaccess の一括クリア処理に失敗しました。');
            }
        } else {
            $this->BcMessage->setError('.htaccess ファイルが書き込み不可、または存在しません。');
        }

        return $this->redirect(array(
            'plugin' => 'waf_exclusion',
            'action' => 'index',
            'admin' => true
        ));
    }

    /**
     * 設定ファイル（setting.php）への書き出し処理
     *
     * @param array $data 設定配列
     * @return bool 書き込み成否
     */
    private function saveSettingFile($data) {
        $forceWafStr = !empty($data['force_waf']) ? 'true' : 'false';
        $wafTypeStr = ($data['waf_type'] === 'modsec') ? 'modsec' : 'siteguard';

        $content = "<?php\n";
        $content .= "\$config['WafExclusion'] = array(\n";
        $content .= "    'target_group_ids' => array(" . implode(', ', $data['target_group_ids']) . "),\n";
        $content .= "    'waf_type' => '{$wafTypeStr}',\n";
        $content .= "    'force_waf' => {$forceWafStr},\n";
        $content .= ");\n";

        return file_put_contents($this->settingPath, $content, LOCK_EX) !== false;
    }
}
