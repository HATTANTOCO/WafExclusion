<?php
/**
 * WafExclusion.php
 *
 * データベーステーブルを使用せず、プラグインのネームスペースとコントローラーへのコンテキストを供給するダミー基本モデルクラス
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
     * データベースのテーブルを一切使用しないことをフレームワークに明示します。
     *
     * @var bool
     */
    public $useTable = false;

}
