<?php
/**
 * スマートダッシュボード APIプロキシ & データ処理
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-App-Config');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';

// ローカル環境等のSSLエラー等でJSONが壊れるのを防ぐため、エラー警告表示を無効にする
ini_set('display_errors', 0);
error_reporting(0);

// リクエストヘッダーから設定を取得
$appConfig = [];
if (isset($_SERVER['HTTP_X_APP_CONFIG'])) {
    $appConfig = json_decode($_SERVER['HTTP_X_APP_CONFIG'], true);
}

/**
 * 設定値を取得する（リクエストヘッダーの値を優先、なければ定数をフォールバック）
 */
function getConfigValue($key, $default = '') {
    global $appConfig;
    if (is_array($appConfig) && isset($appConfig[$key]) && $appConfig[$key] !== '') {
        return $appConfig[$key];
    }
    if (defined($key)) {
        return constant($key);
    }
    return $default;
}

// アクションの取得
$action = isset($_GET['action']) ? $_GET['action'] : '';


switch ($action) {
    Case 'get_weather':
        getWeather();
        Break;
    Case 'get_news':
        getNews();
        Break;
    Case 'get_aircon':
        getAirconStatus();
        Break;
    Case 'control_aircon':
        controlAircon();
        Break;
    Default:
        echo json_encode(['error' => 'Invalid action']);
        Break;
}

/**
 * 天気情報の取得
 */
function getWeather() {
    $apiKey = getConfigValue('OPENWEATHERMAP_API_KEY');
    if (USE_MOCK || empty($apiKey) || $apiKey === 'bf5abb6cf9cd13aa50939c6b44e53fd5') {
        echo json_encode(getMockWeather());
        exit;
    }

    $cacheFile = sys_get_temp_dir() . '/weather_cache.json';
    $cacheTime = 900; // 15分キャッシュ

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
        $cachedData = file_get_contents($cacheFile);
        if ($cachedData) {
            echo $cachedData;
            exit;
        }
    }

    $lat = getConfigValue('WEATHER_LAT', '35.6895');
    $lon = getConfigValue('WEATHER_LON', '139.6917');
    
    // 5日分/3時間ごとの予報API
    $url = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$apiKey}&units=metric&lang=ja";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        // APIエラー時はキャッシュがあれば返し、なければモックを返す
        if (file_exists($cacheFile)) {
            echo file_get_contents($cacheFile);
        } else {
            echo json_encode(getMockWeather());
        }
        exit;
    }

    $data = json_decode($response, true);
        Echo json_encode(getMockWeather());
        Exit;
    }

    $formattedData = parseWeatherForecast($data);
    file_put_contents($cacheFile, json_encode($formattedData));
    
    Echo json_encode($formattedData);
}

/**
 * OpenWeatherMapのデータをダッシュボード用にパース
 */
function parseWeatherForecast($data) {
    $list = $data['list'];
    $current = $list[0];
    
    // 1. 現在の天気
    $currentTemp = round($current['main']['temp']);
    $currentHumidity = $current['main']['humidity'];
    $weatherId = $current['weather'][0]['id'];
    $weatherIcon = $current['weather'][0]['icon'];
    
    // 今日の日付文字列 (Y-m-d)
    $todayStr = date('Y-m-d');
    
    // 今日一日の気温リスト
    $todayTemps = [];
    foreach ($list as $item) {
        $itemDate = date('Y-m-d', $item['dt']);
        if ($itemDate === $todayStr) {
            $todayTemps[] = $item['main']['temp'];
        }
    }
    
    // 今日の最高・最低気温 (データ不足の場合は現在の予報から適当に算出)
    if (empty($todayTemps)) {
        $todayMax = round($current['main']['temp_max']);
        $todayMin = round($current['main']['temp_min']);
    } else {
        $todayMax = round(max($todayTemps));
        $todayMin = round(min($todayTemps));
    }
    
    // 2. 後の3日分の天気 (明日、明後日、明明後日)
    $forecast3Days = [];
    $days = [];
    
    // 明日から3日間分を特定
    for ($i = 1; $i <= 3; $i++) {
        $days[] = date('Y-m-d', strtotime("+{$i} day"));
    }
    
    foreach ($days as $day) {
        $dayTemps = [];
        $dayWeatherIds = [];
        $dayPops = [];
        
        foreach ($list as $item) {
            $itemDate = date('Y-m-d', $item['dt']);
            if ($itemDate === $day) {
                $dayTemps[] = $item['main']['temp'];
                $dayWeatherIds[] = $item['weather'][0]['id'];
                $dayPops[] = isset($item['pop']) ? $item['pop'] : 0;
            }
        }
        
        if (!empty($dayTemps)) {
            // 代表天気は12:00頃のものを使う、なければ最初のもの
            $midWeatherId = 800; // 晴れデフォルト
            foreach ($list as $item) {
                $itemDate = date('Y-m-d', $item['dt']);
                if ($itemDate === $day && date('H', $item['dt']) == '12') {
                    $midWeatherId = $item['weather'][0]['id'];
                    break;
                }
            }
            if ($midWeatherId === 800 && !empty($dayWeatherIds)) {
                $midWeatherId = $dayWeatherIds[0];
            }
            
            $forecast3Days[] = [
                'date' => date('n/j', strtotime($day)),
                'weather_type' => getWeatherTypeById($midWeatherId),
                'temp_max' => round(max($dayTemps)),
                'temp_min' => round(min($dayTemps)),
                'pop' => round(max($dayPops) * 100) // 降水確率 %
            ];
        }
    }
    
    // 3. 8時間ごとの予報 (直近8時間おきのデータを8点抽出、約3日分)
    $hourlyForecast = [];
    $startTime = time();
    $targetTimes = [];
    for ($i = 1; $i <= 8; $i++) {
        $targetTimes[] = $startTime + ($i * 8 * 3600); // 8, 16, 24, 32, 40, 48, 56, 64 時間後
    }
    
    foreach ($targetTimes as $targetTime) {
        // 最も近い時間の予報データをリストから探す
        $closestItem = null;
        $minDiff = null;
        foreach ($list as $item) {
            $diff = abs($item['dt'] - $targetTime);
            if ($minDiff === null || $diff < $minDiff) {
                $minDiff = $diff;
                $closestItem = $item;
            }
        }
        
        if ($closestItem) {
            $hourlyForecast[] = [
                'time' => date('H:i', $closestItem['dt']),
                'date' => date('n/j', $closestItem['dt']),
                'weather_type' => getWeatherTypeById($closestItem['weather'][0]['id']),
                'temp' => round($closestItem['main']['temp']),
                'humidity' => $closestItem['main']['humidity'],
                'pop' => round((isset($closestItem['pop']) ? $closestItem['pop'] : 0) * 100)
            ];
        }
    }

    // 4. 生活アドバイスの自動生成
    $advice = generateAdvice($weatherId, $currentTemp, $currentHumidity, $todayMax, $todayMin);

    Return [
        'today' => [
            'date' => date('n/j'),
            'weather_type' => getWeatherTypeById($weatherId),
            'temp_max' => $todayMax,
            'temp_min' => $todayMin,
            'current_temp' => $currentTemp,
            'current_humidity' => $currentHumidity,
            'advice' => $advice
        ],
        'forecast_3days' => $forecast3Days,
        'forecast_hourly' => $hourlyForecast
    ];
}

/**
 * Weather IDから簡易的な天気タイプ (sunny, cloudy, rainy, snowy) を取得
 */
function getWeatherTypeById($id) {
    if ($id >= 200 && $id < 600) {
        return 'rainy'; // 雷雨・霧雨・雨
    } elseif ($id >= 600 && $id < 700) {
        return 'snowy'; // 雪
    } elseif ($id === 800) {
        return 'sunny'; // 快晴
    } elseif ($id > 800 && $id <= 804) {
        return 'cloudy'; // 曇り
    } else {
        return 'cloudy'; // その他（霧など）は曇りに分類
    }
}

/**
 * 天気・気温・湿度から生活アドバイスを生成 (Gemini API を使用、フォールバックあり)
 */
function generateAdvice($weatherId, $temp, $humidity, $todayMax = null, $todayMin = null) {
    $geminiApiKey = getConfigValue('GEMINI_API_KEY');
    
    if (empty($geminiApiKey)) {
        return getFallbackAdvice($weatherId, $temp, $humidity);
    }
    
    if ($todayMax === null) $todayMax = $temp + 2;
    if ($todayMin === null) $todayMin = $temp - 2;

    $weatherType = getWeatherTypeById($weatherId);
    $weatherJP = [
        'sunny' => '晴れ',
        'cloudy' => '曇り',
        'rainy' => '雨',
        'snowy' => '雪'
    ][$weatherType] ?? '曇り';
    
    // キャッシュファイルのチェック (30分間有効)
    $cacheFile = sys_get_temp_dir() . '/gemini_advice_cache.json';
    $cacheTime = 1800; // 30分
    
    // 天気条件のハッシュを作成し、天気が変わったらキャッシュを破棄
    $currentConditionsHash = md5("{$weatherId}_{$temp}_{$humidity}_{$todayMax}_{$todayMin}");
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        if ($cachedData && isset($cachedData['hash']) && $cachedData['hash'] === $currentConditionsHash && isset($cachedData['advice'])) {
            return $cachedData['advice'];
        }
    }

    // Gemini API 呼び出し
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $geminiApiKey;
    
    $prompt = "今日の天気: {$weatherJP}、現在の気温: {$temp}°C、最高気温: {$todayMax}°C、最低気温: {$todayMin}°C、湿度: {$humidity}%。";
    $prompt .= "この天気を踏まえて、今日を快適に過ごす上での親しみやすいアドバイス（服装や体調管理、傘の必要性、エアコン使用など）を、簡潔に「優しいタメ口（日本語）」で2〜3文で考えてください。";
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 150,
            'temperature' => 0.7
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $resData = json_decode($response, true);
        if (isset($resData['candidates'][0]['content']['parts'][0]['text'])) {
            $advice = trim($resData['candidates'][0]['content']['parts'][0]['text']);
            // キャッシュに保存
            file_put_contents($cacheFile, json_encode([
                'hash' => $currentConditionsHash,
                'advice' => $advice
            ]));
            return $advice;
        }
    }
    
    // API呼び出しエラー時はフォールバック
    return getFallbackAdvice($weatherId, $temp, $humidity);
}

/**
 * 従来の生活アドバイス生成 (Gemini APIのフォールバック用)
 */
function getFallbackAdvice($weatherId, $temp, $humidity) {
    $advice = "";
    $weatherType = getWeatherTypeById($weatherId);

    if ($temp >= 30) {
        $advice .= "今日は日差しが強く、大変蒸し暑くなることが予想されます。外出する際には暑さ対策や、紫外線対策を心がけましょう。";
    } elseif ($temp >= 25) {
        $advice .= "やや蒸し暑い一日になりそうです。こまめな水分補給を行い、室内ではエアコンを適切に使用して快適に過ごしてください。";
    } elseif ($temp <= 10) {
        $advice .= "冷え込みが厳しくなるため、厚手の上着やマフラーでしっかり防寒対策を。温かい食事をとって体調管理に気をつけましょう。";
    } else {
        $advice .= "過ごしやすい気候ですが、朝晩の寒暖差に注意してください。脱ぎ着しやすい服装でお出かけするのがおすすめです。";
    }

    if ($weatherType === 'rainy') {
        $advice .= " 外出時は雨具を忘れずにお持ちください。傘があっても足元が濡れやすいので注意が必要です。";
    } elseif ($weatherType === 'snowy') {
        $advice .= " 雪による路面凍結や視界不良の恐れがあります。歩行や車の運転には細心の注意を払いましょう。";
    } elseif ($humidity <= 35) {
        $advice .= " 空気が乾燥しています。喉を傷めないよう加湿を心がけ、スキンケアなどの乾燥対策を行ってください。";
    }

    return trim($advice);
}

/**
 * 天気のモックデータ
 */
function getMockWeather() {
    return [
        'today' => [
            'date' => date('n/j'),
            'weather_type' => 'sunny',
            'temp_max' => 31,
            'temp_min' => 26,
            'current_temp' => 30,
            'current_humidity' => 50,
            'advice' => '今日は日差しが強く、大変蒸し暑くなることが予想されます。外出する際には暑さ対策や、紫外線対策を心がけましょう。'
        ],
        'forecast_3days' => [
            [
                'date' => date('n/j', strtotime('+1 day')),
                'weather_type' => 'sunny',
                'temp_max' => 31,
                'temp_min' => 26,
                'pop' => 50
            ],
            [
                'date' => date('n/j', strtotime('+2 day')),
                'weather_type' => 'sunny',
                'temp_max' => 31,
                'temp_min' => 26,
                'pop' => 50
            ],
            [
                'date' => date('n/j', strtotime('+3 day')),
                'weather_type' => 'sunny',
                'temp_max' => 31,
                'temp_min' => 26,
                'pop' => 50
            ]
        ],
        'forecast_hourly' => [
            [
                'time' => '12:00',
                'date' => date('n/j'),
                'weather_type' => 'sunny',
                'temp' => 30,
                'humidity' => 50,
                'pop' => 10
            ],
            [
                'time' => '20:00',
                'date' => date('n/j'),
                'weather_type' => 'cloudy',
                'temp' => 28,
                'humidity' => 60,
                'pop' => 20
            ],
            [
                'time' => '04:00',
                'date' => date('n/j', strtotime('+1 day')),
                'weather_type' => 'rainy',
                'temp' => 25,
                'humidity' => 80,
                'pop' => 80
            ],
            [
                'time' => '12:00',
                'date' => date('n/j', strtotime('+1 day')),
                'weather_type' => 'sunny',
                'temp' => 31,
                'humidity' => 55,
                'pop' => 30
            ],
            [
                'time' => '20:00',
                'date' => date('n/j', strtotime('+1 day')),
                'weather_type' => 'sunny',
                'temp' => 29,
                'humidity' => 50,
                'pop' => 10
            ],
            [
                'time' => '04:00',
                'date' => date('n/j', strtotime('+2 day')),
                'weather_type' => 'cloudy',
                'temp' => 26,
                'humidity' => 70,
                'pop' => 40
            ],
            [
                'time' => '12:00',
                'date' => date('n/j', strtotime('+2 day')),
                'weather_type' => 'sunny',
                'temp' => 30,
                'humidity' => 55,
                'pop' => 20
            ],
            [
                'time' => '20:00',
                'date' => date('n/j', strtotime('+2 day')),
                'weather_type' => 'sunny',
                'temp' => 29,
                'humidity' => 50,
                'pop' => 10
            ]
        ]
    ];
}

/**
 * ニュースの取得
 */
function getNews() {
    $feeds = [
        '社会' => 'https://news.yahoo.co.jp/rss/topics/domestic.xml',
        'スポーツ' => 'https://news.yahoo.co.jp/rss/topics/sports.xml',
        'IT' => 'https://news.yahoo.co.jp/rss/topics/it.xml',
        '地方' => 'https://news.yahoo.co.jp/rss/topics/local.xml'
    ];

    $cacheFile = sys_get_temp_dir() . '/news_cache.json';
    $cacheTime = 600; // 10分キャッシュ

    If (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
        $cachedData = file_get_contents($cacheFile);
        If ($cachedData) {
            Echo $cachedData;
            Exit;
        }
    }

    $allNews = [];

    // 各フィードから最新ニュースを取得してマージ
    foreach ($feeds as $genre => $url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $rssContent = curl_exec($ch);
        curl_close($ch);

        if ($rssContent) {
            // エラーを抑止して読み込む
            $xml = @simplexml_load_string($rssContent);
            if ($xml && isset($xml->channel->item)) {
                foreach ($xml->channel->item as $item) {
                    $title = (string)$item->title;
                    $link = (string)$item->link;
                    $pubDateStr = (string)$item->pubDate;
                    $timestamp = strtotime($pubDateStr);
                    
                    // タイトルの末尾にある "(ソース名)" を抽出する
                    $source = '';
                    if (preg_match('/^(.*?)\s*\(([^)]+)\)$/u', $title, $matches)) {
                        $title = trim($matches[1]);
                        $source = trim($matches[2]);
                    }

                    $allNews[] = [
                        'timestamp' => $timestamp,
                        'date' => date('n/j H:i', $timestamp),
                        'genre' => $genre,
                        'title' => $title,
                        'source' => $source ? $source : 'Yahoo!ニュース',
                        'link' => $link
                    ];
                }
            }
        }
    }

    if (empty($allNews)) {
        echo json_encode(getMockNews());
        exit;
    }

    // タイムスタンプ順 (降順) にソート
    usort($allNews, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });

    // 最新の4件のみスライス
    $latestNews = array_slice($allNews, 0, 4);

    file_put_contents($cacheFile, json_encode($latestNews));
    echo json_encode($latestNews);
}

/**
 * ニュースのモックデータ (画像に類似した内容)
 */
function getMockNews() {
    $today = time();
    $yesterday = $today - 86400;
    
    return [
        [
            'date' => date('n/j H:i', $yesterday - 3600),
            'genre' => '社会',
            'title' => '栃木・大雨の影響で住宅倒壊、裏手の山が土砂崩れ',
            'source' => 'nippon.com',
            'link' => '#'
        ],
        [
            'date' => date('n/j H:i', $yesterday),
            'genre' => 'スポーツ',
            'title' => '大リーグ佐々木は1失点、大谷は4打数無安打、吉田4号ソロ',
            'source' => 'nippon.com',
            'link' => '#'
        ],
        [
            'date' => date('n/j H:i', $today - 7200),
            'genre' => '天気',
            'title' => '三連休中日 北陸から沖縄の22地域に熱中症警戒アラート発表',
            'source' => 'ウェザーニュース',
            'link' => '#'
        ],
        [
            'date' => date('n/j H:i', $today - 3600),
            'genre' => '地方',
            'title' => '北海道の大雨、JR特急など計15本運休',
            'source' => 'Ceek.jp News',
            'link' => '#'
        ]
    ];
}

/**
 * SwitchBot API のシグネチャ（署名）を作成
 */
function getSwitchBotHeaders() {
    $token = getConfigValue('SWITCHBOT_TOKEN');
    $secret = getConfigValue('SWITCHBOT_SECRET');
    $t = round(microtime(true) * 1000);
    $nonce = bin2hex(random_bytes(16));
    
    $data = $token . $t . $nonce;
    $sign = hash_hmac('sha256', $data, $secret, true);
    $sign = strtoupper(base64_encode($sign));

    return [
        "Authorization: {$token}",
        "sign: {$sign}",
        "t: {$t}",
        "nonce: {$nonce}",
        "Content-Type: application/json; charset=utf8"
    ];
}

/**
 * エアコン状態の取得
 */
function getAirconStatus() {
    $token = getConfigValue('SWITCHBOT_TOKEN');
    if (USE_MOCK || empty($token) || $token === 'YOUR_SWITCHBOT_DEVELOPER_TOKEN') {
        echo json_encode(getMockAirconStatus());
        exit;
    }

    $deviceId = getConfigValue('SWITCHBOT_AC_DEVICE_ID');
    $url = "https://api.switch-bot.com/v1.1/devices/{$deviceId}/status";
    $headers = getSwitchBotHeaders();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        // エラー時はモックを返すか、エラーJSONを返す
        echo json_encode(getMockAirconStatus());
        exit;
    }

    $data = json_decode($response, true);
    if (isset($data['body'])) {
        $body = $data['body'];
        
        // SwitchBotのエアコンステータスを共通形式にマッピング
        $status = [
            'power' => isset($body['power']) ? strtolower($body['power']) : 'off', // 'on' or 'off'
            'temp' => isset($body['temperature']) ? intval($body['temperature']) : 26,
            'mode' => isset($body['mode']) ? mapSwitchBotModeToApp($body['mode']) : 'cool',
            'fan' => isset($body['fanSpeed']) ? mapSwitchBotFanToApp($body['fanSpeed']) : 'auto',
            'real_humidity' => isset($body['humidity']) ? $body['humidity'] : null
        ];
        echo json_encode($status);
    } else {
        echo json_encode(getMockAirconStatus());
    }
}

/**
 * SwitchBotのモードをダッシュボード側の文字列にマッピング
 */
function mapSwitchBotModeToApp($sbMode) {
    // SwitchBot APIではモードが数値(1-5)または文字列で返ることがある
    // 1 (auto), 2 (cool), 3 (dry), 4 (fan), 5 (heat)
    if (is_numeric($sbMode)) {
        $map = [1 => 'auto', 2 => 'cool', 3 => 'dry', 4 => 'fan', 5 => 'heat'];
        return isset($map[$sbMode]) ? $map[$sbMode] : 'cool';
    }
    return strtolower($sbMode);
}

/**
 * SwitchBotの風量をダッシュボード側の文字列にマッピング
 */
function mapSwitchBotFanToApp($sbFan) {
    // 1 (low), 2 (medium), 3 (high), 4 (auto)
    if (is_numeric($sbFan)) {
        $map = [1 => '1', 2 => '2', 3 => '3', 4 => 'auto'];
        return isset($map[$sbFan]) ? $map[$sbFan] : 'auto';
    }
    // 文字列 low/medium/high/auto
    $sbFan = strtolower($sbFan);
    if ($sbFan === 'low') return '1';
    if ($sbFan === 'medium') return '2';
    if ($sbFan === 'high') return '3';
    return 'auto';
}

/**
 * エアコン操作
 */
function controlAircon() {
    // POSTされたJSONデータを取得
    $inputData = json_decode(file_get_contents('php://input'), true);
    if (!$inputData) {
        echo json_encode(['success' => false, 'error' => 'No input data']);
        exit;
    }

    $power = isset($inputData['power']) ? $inputData['power'] : 'off'; // on, off
    $temp = isset($inputData['temp']) ? intval($inputData['temp']) : 26; // 16-30
    $mode = isset($inputData['mode']) ? $inputData['mode'] : 'cool'; // cool, heat, dry, auto, fan
    $fan = isset($inputData['fan']) ? $inputData['fan'] : 'auto'; // auto, 1, 2, 3

    $token = getConfigValue('SWITCHBOT_TOKEN');
    if (USE_MOCK || empty($token) || $token === 'YOUR_SWITCHBOT_DEVELOPER_TOKEN') {
        saveMockAirconStatus([
            'power' => $power,
            'temp' => $temp,
            'mode' => $mode,
            'fan' => $fan
        ]);
        echo json_encode(['success' => true, 'mock' => true]);
        exit;
    }

    // SwitchBot API コマンド送信
    // モードのマッピング
    // 1 (auto), 2 (cool), 3 (dry), 4 (fan), 5 (heat)
    $modeMap = ['auto' => 1, 'cool' => 2, 'dry' => 3, 'fan' => 4, 'heat' => 5];
    $sbMode = isset($modeMap[$mode]) ? $modeMap[$mode] : 2;

    // 風量のマッピング
    // 1 (low), 2 (medium), 3 (high), 4 (auto)
    $fanMap = ['1' => 1, '2' => 2, '3' => 3, 'auto' => 4];
    $sbFan = isset($fanMap[$fan]) ? $fanMap[$fan] : 4;

    // パラメータフォーマット: "${temp},${mode},${fan},${powerState}"
    $powerState = ($power === 'on') ? 'on' : 'off';
    $parameter = "{$temp},{$sbMode},{$sbFan},{$powerState}";

    $deviceId = getConfigValue('SWITCHBOT_AC_DEVICE_ID');
    $url = "https://api.switch-bot.com/v1.1/devices/{$deviceId}/commands";
    $headers = getSwitchBotHeaders();

    $postData = json_encode([
        'command' => 'setAll',
        'parameter' => $parameter,
        'commandType' => 'custom'
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $resData = json_decode($response, true);
        if (isset($resData['statusCode']) && $resData['statusCode'] === 100) {
            // ローカルの擬似キャッシュも更新しておく
            saveMockAirconStatus([
                'power' => $power,
                'temp' => $temp,
                'mode' => $mode,
                'fan' => $fan
            ]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => isset($resData['message']) ? $resData['message'] : 'SwitchBot API Error']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => "HTTP error code: {$httpCode}"]);
    }
}

/**
 * モックエアコンステータスをファイル保存
 */
function saveMockAirconStatus($status) {
    file_put_contents(sys_get_temp_dir() . '/aircon_mock_status.json', json_encode($status));
}

/**
 * モックエアコンステータスの読み込み
 */
function getMockAirconStatus() {
    $file = sys_get_temp_dir() . '/aircon_mock_status.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            return $data;
        }
    }
    
    // デフォルト値
    return [
        'power' => 'off',
        'temp' => 26,
        'mode' => 'cool',
        'fan' => 'auto'
    ];
}
