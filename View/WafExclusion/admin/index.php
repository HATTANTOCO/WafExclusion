<?php
/**
 * index.php
 *
 * WAF除外の対象ユーザーグループ設定および現在の.htaccessの記述確認、手動一括クリアを行う管理画面設定ビューファイル
 *
 * @package    WafExclusion
 * @subpackage View
 * @author     HATTA <https://hattantoco.com>
 * @license    MIT License
 * @link       https://github.com/hattantoco
 */
$this->BcBaser->js([
    // 必要に応じて追加の管理画面用JS等
]);
?>

<div class="bc-heading">
    <h2>WAF除外コントローラ設定</h2>
</div>

<?php echo $this->BcForm->create('WafExclusion') ?>

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
                'div' => false,
                'onclick' => "if(this.value=='1'){return false;}" 
            )) ?>
            <br>
            <small>WAF自動バイパスの対象（IPアドレスを自動登録するグループ）を選択してください。</small>
        </td>
    </tr>
</table>

<div class="submit bca-actions" style="margin: 20px 0 20px;">
    <div class="bca-actions__main">
        <?php echo $this->BcForm->submit('保　存', array('div' => false, 'class' => 'button bca-btn bca-actions__item', 'data-bca-btn-size' => 'lg', 'data-bca-btn-width' => 'lg', 'data-bca-btn-type' => 'save')) ?>
    </div>
</div>

<?php echo $this->BcForm->end() ?>

<div class="section" style="margin-top: 40px; border-top: 1px dashed #ccc; padding-top: 20px;">
    <h2>手動メンテナンス</h2>

    <div style="margin-bottom: 20px;">
        <p><small>現在の .htaccess の記述内容（確認用プレビュー）:</small></p>
        <textarea readonly rows="12" style="width: 100%; font-family: Menlo, Courier, monospace; font-size: 1em; line-height: 1.4; padding: 10px; background-color: #f5f5f5; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; resize: vertical;"><?php echo h($htaccessContent); ?></textarea>
    </div>

    <p><small>現在 .htaccess に登録されているすべてのWAF除外IPを即座に消去します。（削除後も次の通信やアクセスにより作業者は自動で再登録され、そのまま作業を継続できます）</small></p>
    
    <?php echo $this->BcForm->create('WafExclusion', array('url' => array('admin' => true, 'plugin' => 'waf_exclusion', 'controller' => 'waf_exclusion', 'action' => 'reset_all'), 'id' => 'WafExclusionResetForm')) ?>
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
