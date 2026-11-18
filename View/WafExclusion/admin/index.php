<?php
/**
 * index.php
 *
 * WAF除外（SiteGuard Lite / ModSecurity）の設定・環境診断・.htaccessプレビューを行う管理画面ビュー
 *
 * @package    WafExclusion
 * @subpackage View
 * @author     HATTA <https://hattantoco.com>
 * @license    MIT License
 * @link       https://github.com/hattantoco
 */
$wafType = isset($wafConfig['waf_type']) ? $wafConfig['waf_type'] : 'siteguard';
$selectedGroups = isset($wafConfig['target_group_ids']) ? $wafConfig['target_group_ids'] : array(1);
$forceWafValue = !empty($wafConfig['force_waf']) ? '1' : '0';
$isModSec = ($wafType === 'modsec');
?>

<?php if (!empty($justSaved)): ?>
<span id="waf-just-saved" style="display:none;"></span>
<?php endif; ?>

<div class="bc-heading">
    <h2>WAF除外コントローラ設定</h2>
</div>

<?php echo $this->BcForm->create('WafExclusion') ?>

<!-- 1. 対象ユーザーグループ -->
<table class="form-table section bca-form-table">
    <tr>
        <th class="col-head bca-form-table__label">
            <?php echo $this->BcForm->label('WafExclusion.target_group_ids', '対象ユーザーグループ') ?>
        </th>
        <td class="col-input bca-form-table__input">
            <?php echo $this->BcForm->input('target_group_ids', array(
                'type' => 'select',
                'multiple' => 'checkbox',
                'options' => $userGroups,
                'value' => $selectedGroups,
                'div' => false,
                'onclick' => "if(this.value=='1'){return false;}"
            )) ?>
            <br>
            <small>WAF自動バイパスの対象（IPアドレスを自動登録するグループ）を選択してください。</small>
        </td>
    </tr>
</table>

<!-- 2. サーバー環境診断ステータス（グレーのボックス枠） -->
<?php
$hasAppWebroot = is_dir(ROOT . DS . 'app' . DS . 'webroot');
$isInWebroot = ($hasAppWebroot && strpos((string)$diagnostics['htaccessPath'], WWW_ROOT) === 0);

$targetLabel = $isInWebroot
    ? 'app/webroot/.htaccess（baserCMSのインストールルート配下の app/webroot 内）'
    : '.htaccess（baserCMSのインストールルート直下）';
?>
<div style="background-color: #f8f9fa; border: 1px solid #dee2e6; padding: 15px 20px; border-radius: 4px; margin: 20px 0;">
    <h3 style="margin-top: 0; margin-bottom: 12px; font-size: 1.05em;">サーバー環境診断ステータス</h3>
    <ul style="padding-left: 20px; line-height: 1.7; margin-bottom: 12px;">
        <li><strong>現在の接続元IPアドレス:</strong> <code><?php echo h($clientIp); ?></code></li>
        <li>
            <strong>書き込み対象ファイル:</strong> <code><?php echo h($targetLabel); ?></code>
            <?php if (!empty($diagnostics['isHtaccessWritable'])): ?>
                <span style="color: #28a745; font-weight: bold;">（書き込み可能）</span>
            <?php else: ?>
                <span style="color: #dc3545; font-weight: bold;">（書き込み不可・パーミッションを確認してください）</span>
            <?php endif; ?>
        </li>
        <li class="js-modsec-only" style="<?php echo $isModSec ? '' : 'display: none;'; ?>">
            <strong>検出されたApacheバージョン:</strong>
            <?php echo h($diagnostics['apacheVersion']); ?>
            <?php if (!empty($diagnostics['isApacheOk'])): ?>
                <span style="color: #28a745; font-weight: bold;">（2.4.26以上: 条件クリア）</span>
            <?php else: ?>
                <span style="color: #dc3545; font-weight: bold;">（2.4.26未満、またはApacheバージョン非公開環境）</span>
            <?php endif; ?>
        </li>
        <li class="js-modsec-only" style="<?php echo $isModSec ? '' : 'display: none;'; ?>">
            <strong>ModSecurity（mod_security2）検出:</strong>
            <?php if (!empty($diagnostics['hasModSecurity'])): ?>
                <span style="color: #28a745; font-weight: bold;">検出済み</span>
            <?php else: ?>
                <span style="color: #6c757d;">未検出（または非公開構成）</span>
            <?php endif; ?>
        </li>
    </ul>
    <hr style="border: 0; border-top: 1px solid #dee2e6; margin: 12px 0;">
    <p style="margin-bottom: 0;">
        <strong>現在の動作ステータス:</strong>
        <?php if (empty($diagnostics['isHtaccessWritable'])): ?>
            <span style="color: #dc3545; font-weight: bold;">停止中（.htaccess に書き込み権限がありません）</span>
        <?php elseif (!empty($diagnostics['isWafUpdatable'])): ?>
            <span style="color: #28a745; font-weight: bold;">有効（<?php echo $isModSec ? 'ModSecurity' : 'SiteGuard Lite'; ?> 除外ディレクティブを自動書き込みします）</span>
        <?php else: ?>
            <span style="color: #dc3545; font-weight: bold;">待機中（自動判定条件を満たしていないため .htaccess への書き込みを行いません）</span>
        <?php endif; ?>
    </p>
</div>

<!-- 3. 使用するWAF種別・動作モード -->
<table class="form-table section bca-form-table">
    <tr id="waf-type-row">
        <th class="col-head bca-form-table__label" style="<?php echo $isModSec ? '' : 'border-bottom: none;'; ?>">
            <?php echo $this->BcForm->label('WafExclusion.waf_type', '使用するWAF種別') ?>
        </th>
        <td class="col-input bca-form-table__input" style="<?php echo $isModSec ? '' : 'border-bottom: none;'; ?>">
            <?php echo $this->BcForm->input('waf_type', array(
                'type' => 'radio',
                'options' => array(
                    'siteguard' => 'SiteGuard Lite（ロリポップ！・エックスサーバー・さくらのレンタルサーバ等）',
                    'modsec' => 'ModSecurity（Apache 2.4.26以上 + mod_security2 環境）',
                ),
                'value' => $wafType,
                'legend' => false,
                'div' => false,
                'separator' => '&nbsp;&nbsp;'
            )) ?>
            <br>
            <small>ご利用中のサーバーに導入されているWAFの種類を選択してください。</small>
        </td>
    </tr>
    <tr class="js-modsec-only" style="<?php echo $isModSec ? '' : 'display: none;'; ?>">
        <th class="col-head bca-form-table__label">
            <?php echo $this->BcForm->label('WafExclusion.force_waf', '動作モード（ModSecurity強制有効化）') ?>
        </th>
        <td class="col-input bca-form-table__input">
            <?php echo $this->BcForm->input('force_waf', array(
                'type' => 'select',
                'options' => array(
                    '0' => '自動判定（Apache 2.4.26以上 かつ mod_security2 検出時のみ出力）',
                    '1' => '強制有効（バージョンやモジュールが非公開のサーバーでも強制出力）',
                ),
                'value' => $forceWafValue,
                'div' => false,
                'label' => false,
            )) ?>
            <br>
            <small>セキュリティ対策等でサーバー情報（ServerTokens等）が隠蔽されており自動判定で「待機中」になる場合は、「強制有効」を選択してください。<br>※Apache 2.2系や .htaccess 内での SecRuleEngine 記述が禁止されたサーバーで強制有効にすると500エラーになる場合があります。</small>
        </td>
    </tr>
</table>

<!-- 4. 保　存 ボタン -->
<div class="submit bca-actions" style="margin: 20px 0 20px;">
    <div class="bca-actions__main">
        <?php echo $this->BcForm->submit('保　存', array(
            'div' => false,
            'class' => 'button bca-btn bca-actions__item',
            'data-bca-btn-size' => 'lg',
            'data-bca-btn-width' => 'lg',
            'data-bca-btn-type' => 'save'
        )) ?>
    </div>
</div>

<?php echo $this->BcForm->end() ?>

<div class="section" style="margin-top: 40px; border-top: 1px dashed #ccc; padding-top: 20px;">
    <h2>手動メンテナンス</h2>

    <div style="margin-bottom: 20px;">
        <p><small>現在の .htaccess の記述内容（確認用プレビュー）:</small></p>
        <textarea readonly rows="12" style="width: 100%; font-family: Menlo, Courier, monospace; font-size: 1em; line-height: 1.4; padding: 10px; background-color: #f5f5f5; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; resize: vertical;"><?php echo h($htaccessContent); ?></textarea>
    </div>

    <p><small>現在 .htaccess に登録されているすべてのWAF除外IPを消去（クリア）します。<br>※クリアを実行後、現在ログイン中のあなたのIPアドレスのみ自動的に再登録されます。</small></p>

    <?php echo $this->BcForm->create('WafExclusion', array(
        'url' => array(
            'admin' => true,
            'plugin' => 'waf_exclusion',
            'controller' => 'waf_exclusion',
            'action' => 'reset_all'
        ),
        'id' => 'WafExclusionResetForm'
    )) ?>
    <div class="submit bca-actions">
        <div class="bca-actions__main">
            <?php echo $this->BcForm->submit('WAF除外IPを全てクリア', array(
                'div' => false,
                'class' => 'button bca-btn bca-actions__item',
                'data-bca-btn-size' => 'lg',
                'onclick' => "return confirm('現在登録されているすべてのWAF除外IPを削除します。よろしいですか？');"
            )) ?>
        </div>
    </div>
    <?php echo $this->BcForm->end() ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var radios = document.querySelectorAll('input[name="data[WafExclusion][waf_type]"]');
    var modsecElements = document.querySelectorAll('.js-modsec-only');

    function toggleModSecFields() {
        var selected = document.querySelector('input[name="data[WafExclusion][waf_type]"]:checked');
        var isModSec = (selected && selected.value === 'modsec');

        modsecElements.forEach(function(el) {
            el.style.display = isModSec ? '' : 'none';
        });

        var wafTypeCells = document.querySelectorAll('#waf-type-row th, #waf-type-row td');
        wafTypeCells.forEach(function(cell) {
            cell.style.borderBottom = isModSec ? '' : 'none';
        });
    }

    radios.forEach(function(radio) {
        radio.addEventListener('change', toggleModSecFields);
    });
});
</script>
