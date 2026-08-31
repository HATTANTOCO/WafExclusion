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
(function(window, undefined) {
    'use strict';

    var WafFrontManager = function(options) {
        this.initialize(options);
    };

    WafFrontManager.prototype = {
        settings: {
            apiUrl: ''
        },

        initialize: function(options) {
            var pathParts = window.location.pathname.split('/');
            var currentPrefix = (pathParts && pathParts[1]) ? pathParts[1] : 'admin';
            
            this.settings.apiUrl = '/' + currentPrefix + '/waf_exclusion/waf_exclusion_api/beacon';
            
            this.bindEvents();
            this.start();
        },

        /**
         * fetchを用いた設定確認通信
         */
        fetchInterval: function() {
            var self = this;
            
            var formData = new FormData();
            formData.append('waf_get_interval', '1');

            fetch(self.settings.apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) {
                // サーバーから 200 OK 以外のエラー（403など）が返って場合
                // JSONの解析をスキップして終了
                if (!response.ok) {
                    console.log('[WafExclusion Debug] Access denied or session expired (Status:', response.status, '). Stopping execution.');
                    return null; 
                }
                return response.json(); 
            })
            .then(function(res) {
                // 403で終了した（resがnull）場合
                if (!res) return;

                console.log('[WafExclusion Debug] Interval Fetch SUCCESS:', res);
                
                if (res && res.status === 'missing') {
                    var currentPath = window.location.pathname;
                    if (currentPath.indexOf('/users/login') !== -1 || currentPath.indexOf('login') !== -1) {
                        console.log('[WafExclusion Debug] Login page detected. Beacon request skipped.');
                        return;
                    }
                    
                    self.sendBeacon();
                }
            })
            .catch(function(error) {
                // 通信エラー（ネットワーク切断など）の場合
                console.error('[WafExclusion Debug] Interval Fetch ERROR:', error);
            });
        },

        /**
         * fetchを用いた再登録・リカバリー通信
         */
        sendBeacon: function() {
            var self = this;
            
            var formData = new FormData();
            formData.append('waf_action_beacon', '1');

            fetch(self.settings.apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(res) {
                console.log('[WafExclusion Debug] Beacon Registration SUCCESS:', res);
            })
            .catch(function(error) {
                console.error('[WafExclusion Debug] Beacon Registration ERROR:', error);
            });
        },

        /**
         * イベントハンドラの設定
         */
        bindEvents: function() {
            var self = this;
            
            // ログアウトボタンのクリックイベント（イベント委譲）
            document.addEventListener('click', function(e) {
                // aタグかつ、hrefに"logout"または"Logout"が含まれるかチェック
                var target = e.target.closest('a');
                if (!target) return;
                
                var href = target.getAttribute('href') || '';
                if (href.indexOf('logout') !== -1 || href.indexOf('Logout') !== -1) {
                    e.preventDefault();

                    var pathParts = window.location.pathname.split('/');
                    var currentPrefix = (pathParts && pathParts[1]) ? pathParts[1] : 'admin';
                    var resetUrl = '/' + currentPrefix + '/waf_exclusion/waf_exclusion/reset_all';

                    var formData = new FormData();
                    formData.append('waf_delete_beacon', '1');

                    fetch(resetUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    })
                    .then(function() {
                        window.location.href = href;
                    })
                    .catch(function() {
                        window.location.href = href;
                    });
                }
            });

            // タブ復帰時の再確認イベント
            if (typeof document.hidden !== "undefined") {
                document.addEventListener('visibilitychange', function() {
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
            // 要素の存在チェック
            if (document.getElementById('waf-just-saved') !== null) {
                this.sendBeacon();
                return;
            }
            this.fetchInterval();
        }
    };

    // DOMContentLoadedで実行
    document.addEventListener('DOMContentLoaded', function() {
        window.BcWafFrontManager = new WafFrontManager();
    });

})(window);
