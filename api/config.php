<?php
/**
 * スマートダッシュボード 設定ファイル
 */

// ==========================================
// 1. デバッグ & モックモード設定
// ==========================================
// trueの場合、実際のAPIを叩かずに高品質なダッシュボード用のモックデータを返します。
// APIキーを設定した後は false にしてください。
define('USE_MOCK', false);

// ==========================================
// 2. 天気 API (OpenWeatherMap) 設定
// ==========================================
// ユーザー登録してAPIキーを取得してください: https://openweathermap.org/
define('OPENWEATHERMAP_API_KEY', '');

// 取得したい地域（緯度・経度、または都市名）
// デフォルト: 東京
define('WEATHER_LAT', '35.6895');
define('WEATHER_LON', '139.6917');
define('WEATHER_CITY', 'Tokyo');

// ==========================================
// 3. ニュース (RSS) 設定
// ==========================================
// Yahooニュースの主要ニュースRSS（APIキー不要）
define('NEWS_RSS_URL', 'https://news.yahoo.co.jp/rss/topics/top-picks.xml');

// ==========================================
// 4. エアコン (SwitchBot) 設定
// ==========================================
// SwitchBot アプリから取得してください: Developer Token, Client Secret
define('SWITCHBOT_TOKEN', '');
define('SWITCHBOT_SECRET', '');

// エアコンのデバイスID
define('SWITCHBOT_AC_DEVICE_ID', '');

