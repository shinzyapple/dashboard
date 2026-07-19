/**
 * スマートダッシュボード フロントエンドスクリプト
 */

// ==========================================
// 常数の定義 & 設定
// ==========================================
const API_URL = 'api/api.php';
const SCREEN_SAVER_TIMEOUT = 300000; // 5分間無操作でスクリーンセーバー起動
const AIRCON_RETURN_TIMEOUT = 60000;  // エアコン画面で1分間無操作なら待機画面に戻る

// ==========================================
// 状態管理
// ==========================================
let state = {
    currentScreen: null,
    weatherData: null,
    newsData: null,
    aircon: {
        power: 'off',
        temp: 26,
        mode: 'cool',
        fan: 'auto'
    },
    isScreensaverActive: false
};

// タイマーIDの保持
let timers = {
    clock: null,
    weather: null,
    news: null,
    screensaverReset: null,
    airconReturn: null,
    screensaverMove: null
};

// ==========================================
// ローカル環境用モックデータ (API接続エラー時のフォールバック用)
// ==========================================
const MOCK_WEATHER = {
    today: {
        date: "7/19",
        weather_type: "sunny",
        temp_max: 31,
        temp_min: 26,
        current_temp: 30,
        current_humidity: 50,
        advice: "ローカル環境で動作しています。天気情報はモックデータです。"
    },
    forecast_3days: [
        { date: "7/20", weather_type: "sunny", temp_max: 31, temp_min: 26, pop: 50 },
        { date: "7/21", weather_type: "cloudy", temp_max: 29, temp_min: 25, pop: 40 },
        { date: "7/22", weather_type: "rainy", temp_max: 28, temp_min: 24, pop: 80 }
    ],
    forecast_hourly: [
        { time: "12:00", date: "7/19", weather_type: "sunny", temp: 30, humidity: 50, pop: 10 },
        { time: "20:00", date: "7/19", weather_type: "cloudy", temp: 28, humidity: 60, pop: 20 },
        { time: "04:00", date: "7/20", weather_type: "rainy", temp: 25, humidity: 80, pop: 80 },
        { time: "12:00", date: "7/20", weather_type: "sunny", temp: 31, humidity: 55, pop: 30 },
        { time: "20:00", date: "7/20", weather_type: "sunny", temp: 29, humidity: 50, pop: 10 },
        { time: "04:00", date: "7/21", weather_type: "cloudy", temp: 26, humidity: 70, pop: 40 },
        { time: "12:00", date: "7/21", weather_type: "sunny", temp: 30, humidity: 55, pop: 20 },
        { time: "20:00", date: "7/21", weather_type: "sunny", temp: 29, humidity: 50, pop: 10 }
    ]
};

const MOCK_NEWS = [
    { date: "7/19 12:00", genre: "お知らせ", title: "ローカル環境でダッシュボードを起動しました", source: "Smart Dashboard", link: "#" },
    { date: "7/19 10:00", genre: "天気", title: "インターネット未接続の場合でも、以前のキャッシュまたはモックデータを表示します", source: "Smart Dashboard", link: "#" },
    { date: "7/19 09:00", genre: "IT", title: "スマートホーム機能：SwitchBot APIとの連携が設定されています", source: "Smart Dashboard", link: "#" }
];

const MOCK_AIRCON = {
    power: "off",
    temp: 26,
    mode: "cool",
    fan: "auto"
};

// ==========================================
// 初期化処理
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    initClock();
    setupEventListeners();
    
    // 設定ファイルのチェック
    if (!checkConfig()) {
        switchScreen('setup');
    } else {
        switchScreen('standby');
        fetchWeatherData();
        fetchNewsData();
    }
    
    // 最初のアクティビティ感知タイマーをスタート
    resetActivityTimer();
});

function checkConfig() {
    const config = localStorage.getItem('dashboard_config');
    if (!config) return false;
    try {
        const parsed = JSON.parse(config);
        // 必須項目（OpenWeatherMapのキーとSwitchBotのキー）の存在チェック
        return !!(parsed && parsed.OPENWEATHERMAP_API_KEY && parsed.SWITCHBOT_TOKEN);
    } catch (e) {
        return false;
    }
}

function handleConfigFile(file) {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const content = e.target.result;
            const config = JSON.parse(content);
            
            // 簡単なバリデーション
            if (!config.OPENWEATHERMAP_API_KEY || !config.SWITCHBOT_TOKEN) {
                showToast('無効な設定ファイルだよ。必須項目を確認してね。');
                return;
            }
            
            localStorage.setItem('dashboard_config', JSON.stringify(config));
            showToast('設定ファイルを読み込んだよ！起動するね。');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } catch (err) {
            showToast('JSONファイルのパースに失敗したよ。フォーマットを確認してね。');
        }
    };
    reader.readAsText(file);
}

// ==========================================
// 時計・日付の制御
// ==========================================
function initClock() {
    updateClock();
    timers.clock = setInterval(updateClock, 1000);
}

function updateClock() {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth() + 1;
    const date = now.getDate();
    const dayIndex = now.getDay();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    
    const dayNames = ['日', '月', '火', '水', '木', '金', '土'];
    const dayName = dayNames[dayIndex];
    
    const dateString = `${month}月${date}日(${dayName})`;
    const timeString = `${hours}:${minutes}`;
    
    // 待機画面の時計更新
    const dateEl = document.getElementById('current-date');
    const timeEl = document.getElementById('current-time');
    if (dateEl) dateEl.textContent = dateString;
    if (timeEl) timeEl.textContent = timeString;
    
    // スクリーンセーバーの時計更新
    const ssDateEl = document.getElementById('screensaver-date');
    const ssTimeEl = document.getElementById('screensaver-time');
    if (ssDateEl) ssDateEl.textContent = dateString;
    if (ssTimeEl) ssTimeEl.textContent = timeString;

    // 毎分、スクリーンセーバー表示位置のピクセルシフトをトリガー（アクティブな場合のみ）
    if (state.isScreensaverActive && String(now.getSeconds()) === '00') {
        moveScreensaverClock();
    }
}

// ==========================================
// イベントリスナー設定
// ==========================================
function setupEventListeners() {
    // 待機画面タップでエアコン操作画面へ遷移
    const tapTrigger = document.getElementById('tap-trigger');
    if (tapTrigger) {
        tapTrigger.addEventListener('click', () => {
            switchScreen('aircon');
        });
    }

    // 戻るボタンで待機画面へ遷移
    const btnBack = document.getElementById('btn-back');
    if (btnBack) {
        btnBack.addEventListener('click', () => {
            switchScreen('standby');
        });
    }

    // 天気予報のタブ切り替え
    const tab3Days = document.getElementById('tab-3days');
    const tabHourly = document.getElementById('tab-hourly');
    
    if (tab3Days && tabHourly) {
        tab3Days.addEventListener('click', (e) => {
            e.stopPropagation();
            switchWeatherTab('3days');
        });
        tabHourly.addEventListener('click', (e) => {
            e.stopPropagation();
            switchWeatherTab('hourly');
        });
    }

    // エアコン操作: 電源ボタン
    const btnPower = document.getElementById('btn-power');
    if (btnPower) {
        btnPower.addEventListener('click', () => {
            const nextPower = state.aircon.power === 'on' ? 'off' : 'on';
            sendAirconCommand({ power: nextPower });
        });
    }

    // エアコン操作: 温度 上げる
    const btnTempUp = document.getElementById('btn-temp-up');
    if (btnTempUp) {
        btnTempUp.addEventListener('click', () => {
            if (state.aircon.temp < 30) {
                sendAirconCommand({ temp: state.aircon.temp + 1 });
            } else {
                showToast('設定温度の上限は30°Cです');
            }
        });
    }

    // エアコン操作: 温度 下げる
    const btnTempDown = document.getElementById('btn-temp-down');
    if (btnTempDown) {
        btnTempDown.addEventListener('click', () => {
            if (state.aircon.temp > 16) {
                sendAirconCommand({ temp: state.aircon.temp - 1 });
            } else {
                showToast('設定温度の下限は16°Cです');
            }
        });
    }

    // エアコン操作: モード・風量のポップアップトグル
    setupPopupSelection('btn-mode', 'mode-options', (value) => {
        sendAirconCommand({ mode: value });
    });
    setupPopupSelection('btn-fan', 'fan-options', (value) => {
        sendAirconCommand({ fan: value });
    });

    // ポップアップを閉じるためのバックドロップ
    const backdrop = document.getElementById('popup-backdrop');
    if (backdrop) {
        backdrop.addEventListener('click', closeAllPopups);
    }

    // スクリーンセーバー解除イベント (画面のどこをタップしても解除)
    const ssOverlay = document.getElementById('screensaver-overlay');
    if (ssOverlay) {
        ssOverlay.addEventListener('click', () => {
            deactivateScreensaver();
        });
    }

    // グローバルな無操作検知 (スクリーンセーバー用)
    const resetEvents = ['mousemove', 'mousedown', 'keypress', 'touchstart', 'scroll'];
    resetEvents.forEach(event => {
        document.addEventListener(event, () => {
            resetActivityTimer();
        }, { passive: true });
    });

    // === 設定ファイルドラッグ＆ドロップおよびファイル選択のイベントリスナー ===
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('config-file-input');
    const btnSelectFile = document.getElementById('btn-select-file');
    const btnCopyTemplate = document.getElementById('btn-copy-template');

    if (dropZone && fileInput && btnSelectFile) {
        btnSelectFile.addEventListener('click', (e) => {
            e.stopPropagation();
            fileInput.click();
        });

        fileInput.addEventListener('change', (e) => {
            handleConfigFile(e.target.files[0]);
        });

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) {
                handleConfigFile(e.dataTransfer.files[0]);
            }
        });
    }

    if (btnCopyTemplate) {
        btnCopyTemplate.addEventListener('click', (e) => {
            e.stopPropagation();
            const templateText = `{
  "GEMINI_API_KEY": "YOUR_GEMINI_KEY",
  "OPENWEATHERMAP_API_KEY": "YOUR_OWM_KEY",
  "WEATHER_LAT": "35.6895",
  "WEATHER_LON": "139.6917",
  "WEATHER_CITY": "Tokyo",
  "SWITCHBOT_TOKEN": "YOUR_SWITCHBOT_TOKEN",
  "SWITCHBOT_SECRET": "YOUR_SWITCHBOT_SECRET",
  "SWITCHBOT_AC_DEVICE_ID": "YOUR_AC_DEVICE_ID"
}`;
            navigator.clipboard.writeText(templateText).then(() => {
                showToast('テンプレートをコピーしたよ');
            }).catch(err => {
                console.error('Failed to copy text: ', err);
            });
        });
    }

    // === 設定クリアモーダルと設定クリアボタンのイベントリスナー ===
    const btnSettingsTrigger = document.getElementById('btn-settings-trigger');
    const settingsModal = document.getElementById('settings-modal');
    const btnResetConfig = document.getElementById('btn-reset-config');

    if (btnSettingsTrigger && settingsModal && btnResetConfig) {
        btnSettingsTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const isActive = settingsModal.classList.contains('active');
            closeAllPopups();
            if (!isActive) {
                settingsModal.classList.add('active');
                backdrop.classList.add('active');
            }
        });

        btnResetConfig.addEventListener('click', () => {
            if (confirm('設定をクリアして初期セットアップに戻る？')) {
                localStorage.removeItem('dashboard_config');
                window.location.reload();
            }
        });
    }
}

// ==========================================
// 画面切り替え制御 (SPA)
// ==========================================
function switchScreen(screenName) {
    if (screenName === state.currentScreen) return;
    
    closeAllPopups();
    
    const standbyScreen = document.getElementById('standby-screen');
    const airconScreen = document.getElementById('aircon-screen');
    const setupScreen = document.getElementById('setup-screen');
    
    if (standbyScreen) standbyScreen.classList.remove('active');
    if (airconScreen) airconScreen.classList.remove('active');
    if (setupScreen) setupScreen.classList.remove('active');
    
    if (screenName === 'aircon') {
        if (airconScreen) airconScreen.classList.add('active');
        state.currentScreen = 'aircon';
        
        fetchAirconStatus();
        resetAirconReturnTimer();
    } else if (screenName === 'setup') {
        if (setupScreen) setupScreen.classList.add('active');
        state.currentScreen = 'setup';
    } else {
        if (standbyScreen) standbyScreen.classList.add('active');
        state.currentScreen = 'standby';
        
        if (timers.airconReturn) {
            clearTimeout(timers.airconReturn);
            timers.airconReturn = null;
        }
    }
}

// 天気タブ切り替え
function switchWeatherTab(tab) {
    const tab3Days = document.getElementById('tab-3days');
    const tabHourly = document.getElementById('tab-hourly');
    const panel3Days = document.getElementById('forecast-3days-container');
    const panelHourly = document.getElementById('forecast-hourly-container');

    if (tab === '3days') {
        tab3Days.classList.add('active');
        tabHourly.classList.remove('active');
        panel3Days.classList.add('active');
        panelHourly.classList.remove('active');
    } else {
        tab3Days.classList.remove('active');
        tabHourly.classList.add('active');
        panel3Days.classList.remove('active');
        panelHourly.classList.add('active');
    }
}

// ポップアップ選択の共通セットアップ
function setupPopupSelection(btnId, popupId, onSelectCallback) {
    const btn = document.getElementById(btnId);
    const popup = document.getElementById(popupId);
    const backdrop = document.getElementById('popup-backdrop');
    
    if (!btn || !popup) return;

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isActive = popup.classList.contains('active');
        closeAllPopups();
        
        if (!isActive) {
            popup.classList.add('active');
            backdrop.classList.add('active');
        }
    });

    const options = popup.querySelectorAll('.option-item');
    options.forEach(option => {
        option.addEventListener('click', (e) => {
            e.stopPropagation();
            const val = option.getAttribute('data-value');
            
            // UI上のアクティブ切り替え
            options.forEach(o => o.classList.remove('selected'));
            option.classList.add('selected');
            
            closeAllPopups();
            onSelectCallback(val);
        });
    });
}

function closeAllPopups() {
    const popups = document.querySelectorAll('.options-popup');
    popups.forEach(p => p.classList.remove('active'));
    
    const backdrop = document.getElementById('popup-backdrop');
    if (backdrop) backdrop.classList.remove('active');
}

// ==========================================
// APIデータ取得 & レンダリング (天気・ニュース)
// ==========================================

// 天気情報の取得
function fetchWeatherData() {
    fetchAPI(`${API_URL}?action=get_weather`)
        .then(data => {
            state.weatherData = data;
            renderWeather(data);
            updateBackgroundGrad(); // 背景色の更新
        })
        .catch(err => {
            console.error('Failed to fetch weather, using local mock:', err);
            state.weatherData = MOCK_WEATHER;
            renderWeather(MOCK_WEATHER);
            updateBackgroundGrad();
        });

    // 30分ごとに定期取得
    if (timers.weather) clearInterval(timers.weather);
    timers.weather = setInterval(fetchWeatherData, 1800000);
}

// ニュース情報の取得
function fetchNewsData() {
    fetchAPI(`${API_URL}?action=get_news`)
        .then(data => {
            state.newsData = data;
            renderNews(data);
        })
        .catch(err => {
            console.error('Failed to fetch news, using local mock:', err);
            state.newsData = MOCK_NEWS;
            renderNews(MOCK_NEWS);
        });

    // 10分ごとに定期取得
    if (timers.news) clearInterval(timers.news);
    timers.news = setInterval(fetchNewsData, 600000);
}

// 天気UIのレンダリング
function renderWeather(data) {
    const today = data.today;
    
    // 今日の天気
    document.getElementById('weather-today-date').textContent = today.date;
    document.getElementById('weather-today-icon').innerHTML = getWeatherIconHTML(today.weather_type, true);
    document.getElementById('weather-today-temp-max').textContent = today.temp_max;
    document.getElementById('weather-today-temp-min').textContent = today.temp_min;
    document.getElementById('weather-current-temp').textContent = today.current_temp;
    document.getElementById('weather-current-humidity').textContent = today.current_humidity;
    document.getElementById('weather-advice-text').textContent = today.advice;

    // 3日間の天気予報
    const forecast3DaysContainer = document.getElementById('forecast-3days-container');
    forecast3DaysContainer.innerHTML = '';
    data.forecast_3days.forEach(day => {
        const row = document.createElement('div');
        row.className = 'forecast-row';
        row.innerHTML = `
            <span class="forecast-row-date">${day.date}</span>
            <div class="forecast-row-icon">${getWeatherIconHTML(day.weather_type)}</div>
            <span class="forecast-row-max">${day.temp_max}</span>
            <span class="forecast-row-min">${day.temp_min}</span>
            <span class="forecast-row-pop">${day.pop}%</span>
        `;
        forecast3DaysContainer.appendChild(row);
    });

    // 8時間ごとの予報
    const forecastHourlyContainer = document.getElementById('forecast-hourly-container');
    forecastHourlyContainer.innerHTML = '';
    data.forecast_hourly.forEach(hour => {
        const row = document.createElement('div');
        row.className = 'forecast-row hourly-row';
        row.innerHTML = `
            <span class="hourly-time">${hour.time}</span>
            <div class="forecast-row-icon">${getWeatherIconHTML(hour.weather_type)}</div>
            <span class="hourly-temp">${hour.temp}°C</span>
            <span class="hourly-humidity">${hour.humidity}%</span>
            <span class="forecast-row-pop">${hour.pop}%</span>
        `;
        forecastHourlyContainer.appendChild(row);
    });
}

// 天気タイプからフォントアローアイコンHTMLを取得する
function getWeatherIconHTML(type, isMain = false) {
    const sizeClass = isMain ? 'weather-icon sunny-color' : '';
    switch (type) {
        case 'sunny':
            return `<i class="fa-solid fa-sun sunny-color"></i>`;
        case 'cloudy':
            return `<i class="fa-solid fa-cloud cloudy-color"></i>`;
        case 'rainy':
            return `<i class="fa-solid fa-cloud-showers-heavy rainy-color"></i>`;
        case 'snowy':
            return `<i class="fa-solid fa-snowflake snowy-color"></i>`;
        default:
            return `<i class="fa-solid fa-cloud cloudy-color"></i>`;
    }
}

// ニュースUIのレンダリング
function renderNews(data) {
    const newsContainer = document.getElementById('news-container');
    newsContainer.innerHTML = '';
    
    data.forEach(item => {
        const newsLink = document.createElement('a');
        newsLink.className = 'news-item';
        newsLink.href = item.link;
        newsLink.target = '_blank';
        newsLink.addEventListener('click', (e) => {
            // 常設ダッシュボードなので、リンク遷移で外部に飛ばずにプレビューしたい場合や
            // 誤動作を防ぐために href が '#' の場合は遷移させない
            if (item.link === '#') {
                e.preventDefault();
            }
            e.stopPropagation(); // 待機画面のタップイベント（エアコン遷移）をキャンセル
        });
        
        newsLink.innerHTML = `
            <span class="news-date">${item.date}</span>
            <span class="news-genre">[${item.genre}]</span>
            <span class="news-title">${item.title}</span>
            <span class="news-source">(${item.source})</span>
        `;
        newsContainer.appendChild(newsLink);
    });
}

// ==========================================
// エアコン API連携 ＆ 制御
// ==========================================

// エアコン状態の取得
function fetchAirconStatus() {
    fetchAPI(`${API_URL}?action=get_aircon`)
        .then(data => {
            state.aircon = data;
            renderAirconUI();
        })
        .catch(err => {
            console.error('Failed to fetch aircon status, using local mock:', err);
            state.aircon = MOCK_AIRCON;
            renderAirconUI();
        });
}

// エアコンUIのレンダリング
function renderAirconUI() {
    const powerBtn = document.getElementById('btn-power');
    const powerLabel = document.getElementById('power-status-label');
    const tempVal = document.getElementById('aircon-temp-val');
    const statusText = document.getElementById('aircon-status-text');
    const modeVal = document.getElementById('mode-val');
    const modeBtn = document.getElementById('btn-mode');
    const fanVal = document.getElementById('fan-val');

    // 電源状態の反映
    if (state.aircon.power === 'on') {
        powerBtn.classList.add('active');
        powerLabel.textContent = 'ON';
        
        const modeJP = mapModeToJP(state.aircon.mode);
        const fanJP = mapFanToJP(state.aircon.fan);
        statusText.textContent = `${modeJP}運転中 (${fanJP})`;
        statusText.classList.add('on');
    } else {
        powerBtn.classList.remove('active');
        powerLabel.textContent = 'OFF';
        statusText.textContent = 'エアコン停止中';
        statusText.classList.remove('on');
    }

    // 設定温度の反映
    tempVal.textContent = state.aircon.temp;

    // 運転モードの反映
    modeVal.textContent = mapModeToJP(state.aircon.mode);
    // モードごとのアイコン変更
    const modeIcon = modeBtn.querySelector('.btn-icon i');
    modeIcon.className = getModeIconClass(state.aircon.mode);

    // 風量の反映
    fanVal.textContent = mapFanToJP(state.aircon.fan);

    // ポップアップ内の選択状態を更新
    updatePopupSelectedItems();
}

function updatePopupSelectedItems() {
    // モード
    const modeOptions = document.querySelectorAll('#mode-options .option-item');
    modeOptions.forEach(opt => {
        if (opt.getAttribute('data-value') === state.aircon.mode) {
            opt.classList.add('selected');
        } else {
            opt.classList.remove('selected');
        }
    });

    // 風量
    const fanOptions = document.querySelectorAll('#fan-options .option-item');
    fanOptions.forEach(opt => {
        if (opt.getAttribute('data-value') === state.aircon.fan) {
            opt.classList.add('selected');
        } else {
            opt.classList.remove('selected');
        }
    });
}

function mapModeToJP(mode) {
    const map = {
        'cool': '冷房',
        'heat': '暖房',
        'dry': '除湿',
        'auto': '自動',
        'fan': '送風'
    };
    return map[mode] || mode;
}

function mapFanToJP(fan) {
    if (fan === 'auto') return '風量自動';
    return `風量 ${fan}`;
}

function getModeIconClass(mode) {
    const map = {
        'cool': 'fa-solid fa-snowflake',
        'heat': 'fa-solid fa-fire',
        'dry': 'fa-solid fa-droplet-slash',
        'auto': 'fa-solid fa-wand-magic-sparkles',
        'fan': 'fa-solid fa-wind'
    };
    return map[mode] || 'fa-solid fa-snowflake';
}

// エアコンコマンドの送信
function sendAirconCommand(updates) {
    // 一時的にローカルのステータスに適用（UIを高速に更新するため）
    const prevState = { ...state.aircon };
    state.aircon = { ...state.aircon, ...updates };
    renderAirconUI();

    // 送信中スピナー表示
    const loadingOverlay = document.getElementById('loading-overlay');
    loadingOverlay.classList.add('active');

    // バックエンドへ送信
    fetchAPI(`${API_URL}?action=control_aircon`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(state.aircon)
    })
    .then(data => {
        loadingOverlay.classList.remove('active');
        if (data.success) {
            showToast('エアコン設定を更新しました');
        } else {
            showToast('エアコンの操作に失敗しました');
            // 失敗時は元の状態に戻す
            state.aircon = prevState;
            renderAirconUI();
        }
    })
    .catch(err => {
        loadingOverlay.classList.remove('active');
        console.error('Failed to send aircon command:', err);
        showToast('通信エラーが発生しました');
        state.aircon = prevState;
        renderAirconUI();
    });
}

// ==========================================
// 背景グラデーションの動的制御
// ==========================================
function updateBackgroundGrad() {
    const container = document.getElementById('app-container');
    const now = new Date();
    const hour = now.getHours();
    
    // 時間帯の決定 (朝・昼・夕・夜)
    let timeOfDay = 'day';
    if (hour >= 5 && hour < 9) {
        timeOfDay = 'morning';
    } else if (hour >= 9 && hour < 17) {
        timeOfDay = 'day';
    } else if (hour >= 17 && hour < 19) {
        timeOfDay = 'evening';
    } else {
        timeOfDay = 'night';
    }

    // 天気状態の決定 (晴れ・その他)
    let isSunny = true;
    if (state.weatherData && state.weatherData.today) {
        isSunny = state.weatherData.today.weather_type === 'sunny';
    }

    // すべての背景クラスを削除
    container.classList.remove(
        'bg-grad-morning',
        'bg-grad-day-sunny',
        'bg-grad-day-cloudy',
        'bg-grad-evening',
        'bg-grad-night'
    );

    // 条件に応じて背景をセット
    if (timeOfDay === 'morning') {
        container.classList.add('bg-grad-morning');
    } else if (timeOfDay === 'evening') {
        container.classList.add('bg-grad-evening');
    } else if (timeOfDay === 'night') {
        container.classList.add('bg-grad-night');
    } else { // 昼 (day) の場合
        if (isSunny) {
            container.classList.add('bg-grad-day-sunny');
        } else {
            container.classList.add('bg-grad-day-cloudy');
        }
    }
}

// ==========================================
// 無操作監視 ＆ 画面焼け防止（スクリーンセーバー）
// ==========================================

// 無操作タイマーのリセット
function resetActivityTimer() {
    // スクリーンセーバーがアクティブなら解除しない（タップのみで解除するため）
    if (state.isScreensaverActive) return;

    // 1. スクリーンセーバー用タイマーの再設定
    if (timers.screensaverReset) clearTimeout(timers.screensaverReset);
    timers.screensaverReset = setTimeout(activateScreensaver, SCREEN_SAVER_TIMEOUT);

    // 2. エアコン画面から待機画面に戻るタイマーの再設定
    if (state.currentScreen === 'aircon') {
        resetAirconReturnTimer();
    }
}

// エアコン画面の戻りタイマーリセット
function resetAirconReturnTimer() {
    if (timers.airconReturn) clearTimeout(timers.airconReturn);
    timers.airconReturn = setTimeout(() => {
        switchScreen('standby');
        showToast('待機画面に戻りました');
    }, AIRCON_RETURN_TIMEOUT);
}

// スクリーンセーバー起動
function activateScreensaver() {
    if (state.isScreensaverActive) return;
    
    state.isScreensaverActive = true;
    
    // UIを非アクティブ化し、スクリーンセーバーを表示
    const ssOverlay = document.getElementById('screensaver-overlay');
    ssOverlay.classList.add('active');
    
    // 表示座標をランダム配置して時計を表示
    moveScreensaverClock();
    document.getElementById('screensaver-clock').classList.add('visible');
    
    // 各種バックグラウンド自動フェッチや時計更新はそのまま動かす
}

// スクリーンセーバー解除
function deactivateScreensaver() {
    if (!state.isScreensaverActive) return;
    
    state.isScreensaverActive = false;
    
    const ssOverlay = document.getElementById('screensaver-overlay');
    const ssClock = document.getElementById('screensaver-clock');
    
    ssClock.classList.remove('visible');
    ssOverlay.classList.remove('active');
    
    // 無操作タイマーを再始動
    resetActivityTimer();
}

// スクリーンセーバーの時計位置移動 (ピクセルシフト/焼き付き防止)
function moveScreensaverClock() {
    const clock = document.getElementById('screensaver-clock');
    if (!clock) return;

    // 一旦フェードアウトさせて移動し、フェードインさせることで高級感を出す
    clock.style.opacity = '0';
    
    setTimeout(() => {
        // 画面サイズに対する時計の表示可能範囲を算出 (約20%〜70%の範囲にランダム配置)
        // 完全に端に寄ると見づらいため、マージンをとる
        const randomTop = Math.floor(Math.random() * 60) + 15; // 15% - 75%
        const randomLeft = Math.floor(Math.random() * 50) + 15; // 15% - 65%
        
        clock.style.top = `${randomTop}%`;
        clock.style.left = `${randomLeft}%`;
        
        // 移動した後にフェードイン
        clock.style.opacity = '1';
    }, 1000); // 1秒かけてフェードアウトした後に座標切り替え
}

// ==========================================
// ユーティリティ (トースト表示)
// ==========================================
function showToast(message) {
    const toast = document.getElementById('toast');
    if (!toast) return;

    toast.textContent = message;
    toast.classList.add('show');
    
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// ==========================================
// 4. API共通関数
// ==========================================

// API呼び出しの共通関数
function fetchAPI(endpoint, options = {}) {
    const config = localStorage.getItem('dashboard_config');
    if (config) {
        options.headers = {
            ...options.headers,
            'X-App-Config': config
        };
    }
    return fetch(endpoint, options)
        .then(res => {
            return res.json();
        });
}
