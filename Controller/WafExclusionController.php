<?php
/**
 * WafExclusionController.php
 *
 * WAF除外の対象ユーザーグループ設定、手動メンテナンスクリア、およびログアウト時の自動パージを制御するメインコントローラークラス
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
     * .htaccess ファイルの物理パス
     *
     * @var string
     */
    private $htaccessPath = WWW_ROOT . '.htaccess';

    /**
     * [管理画面] WAF除外設定 メイン画面
     *
     * @return void
     */
    public function admin_index() {
        $this->pageTitle = 'WAF除外コントローラ設定';

        // baserCMSコアの UserGroup テーブルから、画面表示名（title）のリストを取得
        $userGroups = array();
        App::uses('UserGroup', 'Model');
        $userGroupModel = ClassRegistry::init(array('class' => 'UserGroup', 'table' => 'user_groups', 'ds' => 'default'));
        if ($userGroupModel) {
            $userGroups = $userGroupModel->find('list', array(
                'fields' => array('id', 'title'), 
                'order' => 'id'
            ));
        }
        // 画面（ビュー）へ引き渡す
        $this->set('userGroups', $userGroups);

        // 設定の保存処理
        if ($this->request->is('post') || $this->request->is('put')) {
            if (isset($this->request->data['WafExclusion'])) {
                $formData = $this->request->data['WafExclusion'];
                
                $newGroups = isset($formData['target_group_ids']) ? (array)$formData['target_group_ids'] : array(1);
                $newGroups = array_map('intval', $newGroups);

                // 最高管理者（ID:1）が含まれているかチェックし、無ければ先頭に強制追加して一意化
                if (!in_array(1, $newGroups, true)) {
                    array_unshift($newGroups, 1);
                }
                $newGroups = array_values(array_unique($newGroups));

                if ($this->saveSettingFile($newGroups)) {
                    $this->BcMessage->setSuccess('WAF除外の設定を保存しました。');
                    $this->Session->write('WafExclusion.just_saved', true);
                    
                    return $this->redirect(array(
                        'plugin' => 'waf_exclusion',
                        'action' => 'index',
                        'admin' => true
                    ));
                } else {
                    $this->BcMessage->setError('設定ファイルの保存に失敗しました。');
                }
            }
        }

        // 現在の設定値を読み込んでフォームにセット
        if (file_exists($this->settingPath)) {
            include $this->settingPath;
            if (isset($config['WafExclusion'])) {
                $this->request->data['WafExclusion'] = $config['WafExclusion'];
            }
        }

        // ビューに .htaccess の中身を引き渡す処理
        $htaccessContent = '';
        if (file_exists($this->htaccessPath)) {
            $htaccessContent = file_get_contents($this->htaccessPath);
        }
        $this->set('htaccessContent', $htaccessContent);
    }

    /**
     * [管理画面] 手動メンテナンスおよびログアウト自動連動：登録されているすべての除外IPを一括クリアする
     *
     * @return void
     */
    public function admin_reset_all() {
        // ログアウト連動：フロントJavaScriptからの同期通信（Ajax）を受け止めた場合の処理
        if (isset($_POST['waf_delete_beacon']) && $_POST['waf_delete_beacon'] === '1') {
            if (file_exists($this->htaccessPath) && is_writable($this->htaccessPath)) {
                $content = file_get_contents($this->htaccessPath);
                
                // .htaccess 内の WAF_EXCLUSION ブロックを一括全消去
                $pattern = '/\R*# --- WAF_EXCLUSION_START ---.*?# --- WAF_EXCLUSION_END ---\R*/s';
                $content = preg_replace($pattern, "\n", $content);

                // 改行クレンジング
                $content = preg_replace('/(?:\r\n|\r|\n){3,}/', "\n\n", $content);

                file_put_contents($this->htaccessPath, $content, LOCK_EX);
                clearstatcache(true, $this->htaccessPath);
            }
            
            // JSONの空データを最速で返却して exit() ブラウザ側のホールドを即座に解除
            Configure::write('debug', 0);
            $this->autoRender = false;
            $this->response->type('json');
            $this->response->body(json_encode(array('status' => 'success')));
            $this->response->send();
            exit();
        }

        // 通常時：設定画面で「一括クリアボタンを押した時」の正規ルート
        if (file_exists($this->htaccessPath) && is_writable($this->htaccessPath)) {
            $content = file_get_contents($this->htaccessPath);
            
            $pattern = '/\R*# --- WAF_EXCLUSION_START ---.*?# --- WAF_EXCLUSION_END ---\R*/s';
            $content = preg_replace($pattern, "\n", $content);

            $content = preg_replace('/(?:\r\n|\r|\n){3,}/', "\n\n", $content);

            if (file_put_contents($this->htaccessPath, $content, LOCK_EX) !== false) {
                clearstatcache(true, $this->htaccessPath);
                $this->Session->write('WafExclusion.just_saved', true);
                
                // 手動でボタンを押したときだけ、成功メッセージを画面に通知
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
     * @param array $groupIds 対象のユーザーグループIDリスト
     * @return bool 書き込み成否
     */
    private function saveSettingFile($groupIds) {
        $content = "<?php\n";
        $content .= "\$config['WafExclusion'] = array(\n";
        $content .= "    'target_group_ids' => array(" . implode(', ', $groupIds) . ")\n";
        $content .= ");\n";
        
        return file_put_contents($this->settingPath, $content, LOCK_EX) !== false;
    }
}
