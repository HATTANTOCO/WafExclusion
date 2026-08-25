<?php
/**
 * config.php
 *
 * プラグインの名称、説明文、インストールメッセージ、および管理画面へのリンクを定義するプラグイン基本設定ファイル
 *
 * @package    WafExclusion
 * @subpackage Config
 * @author     HATTA <https://hattantoco.com>
 * @license    MIT License
 * @link       https://github.com/hattantoco
 */

$title = 'WafExclusion';
$description = 'レンタルサーバ等に導入されているWAF（SiteGuard Lite）の403誤検知を解決するため、管理画面操作中の管理者のIPアドレスのみを一時的に動的バイパスするプラグインです。';
$author = 'HATTA';
$url = 'https://hattantoco.com';
$installMessage = 'サーバー側のWAF設定は「有効（オン）」のままで、管理画面操作中の管理者のみを一時的にバイパスするように.htaccessを動的に書き換えます。';
$adminLink = array(
    'admin' => true, 
    'plugin' => 'waf_exclusion', 
    'controller' => 'waf_exclusion', 
    'action' => 'index'
);
