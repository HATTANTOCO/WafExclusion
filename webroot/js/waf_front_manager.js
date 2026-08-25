/**
 * waf_front_manager.js
 *
 * 管理画面の裏側で自律的に動作し、WAF除外の生存維持、ログイン・ログアウト状態に応じた
 * 自動IP登録および安全な一括パージを制御するフロントマネージャーJavaScript
 *
 * @package    WafExclusion
 * @author     HATTA <https://hattantoco.com>
 * @license    MIT License
 * @link       https://github.com/hattantoco
 */
(function($, window, undefined) {
    'use strict';

    /**
     * WafFrontManager コンストラクタ
     *
     * @param {Object} options 設定オプション
     * @constructor
     */
    var WafFrontManager = function(options) {
        this.initialize(options);
    };

    WafFrontManager.prototype = {
        settings: {
            apiUrl: ''
        },

        /**
         * 初期化処理
         * 
         * 現在のURLパスからプレフィックスを自動抽出し、
         * 適切な通信先URLを設定した上で各種イベントと起動処理を開始します。
         *
         * @param {Object} options 設定オプション
         */
        initialize: function(options) {
            var pathParts = window.location.pathname.split('/');
            var currentPrefix = (pathParts && pathParts[1]) ? pathParts[1] : 'admin';
            
            this.settings.apiUrl = '/' + currentPrefix + '/waf_exclusion/waf_exclusion_api/beacon';
            
            this.bindEvents();
            this.start();
        },

        /**
         * 軽量な設定確認（ダミー）通信の実行
         * 
         * ログイン画面が表示されている場合は、未ログイン状態であるため
         * 自動復活処理への遷移をスキップして終了します。
         */
        fetchInterval: function() {
            var self = this;
            $.ajax({
                url: self.settings.apiUrl,
                type: 'POST',
                data: { 'waf_get_interval': 1 },
                dataType: 'json',
                success: function(res) {
                    console.log('[WafExclusion Debug] Interval Fetch SUCCESS:', res);
                    
                    if (res && res.status === 'missing') {
                        var currentPath = window.location.pathname;
                        if (currentPath.indexOf('/users/login') !== -1 || currentPath.indexOf('login') !== -1) {
                            console.log('[WafExclusion Debug] Login page detected. Beacon request skipped.');
                            return;
                        }
                        
                        self.sendBeacon();
                        return;
                    }
                }
            });
        },

        /**
         * WAF除外IPアドレスの再登録・リカバリー通信の実行
         */
        sendBeacon: function() {
            var self = this;
            $.ajax({
                url: self.settings.apiUrl,
                type: 'POST',
                data: { 'waf_action_beacon': 1 },
                dataType: 'json',
                success: function(res) {
                    console.log('[WafExclusion Debug] Beacon Registration SUCCESS:', res);
                }
            });
        },

        /**
         * イベントハンドラの設定
         * 
         * ログアウトボタンが押された瞬間、現在のURLからプレフィックスを動的に抽出して
         * 同期Ajax通信（async: false）により、.htaccessの登録IPを安全に全消去します。
         */
        bindEvents: function() {
            var self = this;
            
            $(document).on('click', 'a[href*="logout"], a[href*="Logout"]', function(e) {
                var logoutUrl = $(this).attr('href');
                e.preventDefault();

                var pathParts = window.location.pathname.split('/');
                var currentPrefix = (pathParts && pathParts[1]) ? pathParts[1] : 'admin';
                var resetUrl = '/' + currentPrefix + '/waf_exclusion/waf_exclusion/reset_all';

                $.ajax({
                    url: resetUrl,
                    type: 'POST',
                    data: { 'waf_delete_beacon': 1 },
                    async: false,
                    dataType: 'json'
                });
                
                window.location.href = logoutUrl;
            });

            // タブ復帰時の再確認イベント
            if (typeof document.hidden !== "undefined") {
                $(document).on('visibilitychange', function() {
                    if (!document.hidden) {
                        self.fetchInterval(); 
                    }
                });
            }
        },

        /**
         * 起動スイッチ
         */
        start: function() {
            if ($('#waf-just-saved').length > 0) {
                this.sendBeacon();
                return;
            }
            this.fetchInterval();
        }
    };

    $(document).ready(function() {
        window.BcWafFrontManager = new WafFrontManager();
    });

})(jQuery, window);
