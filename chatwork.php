<?php
/**
 * Created by PhpStorm.
 * User: kimura
 * Date: 10/1/16
 * Time: 19:35
 */

// argvのindex定義
define('ARGI_PHP', 0); // 実行したPHP
define('ARGI_CHECK_DOMAIN', 1); // 調べたいドメイン
define('ARGI_CHATWORK_API_TOKEN', 2); // チャットワークに送る為のAPI_TOKEN
define('ARGI_CHATWORK_ROOM_ID', 3); // チャットワークに送る為のAPI_TOKEN
define('ARGI_CHATWORK_TO_ID_CSV', 4); // TOに入れる相手のIDをcsvにして指定する
define('ARGI_MAX', 5);

// whosinで取得する値
define('WHOIS_KEY_ADMIN',   '登録者名');
define('WHOIS_KEY_EXPIRE',  '有効期限');
define('WHOIS_KEY_STATUS',  '状態');
define('WHOIS_KEY_DAYS',    '残り日数');

// 有効期限日までのアラートLEVEL
define('EXPIRE_DAYS_CRIT', 14); // 何日前から致命的通知にするか
define('EXPIRE_DAYS_WARN', 30); // 何日前から警告通知にするか

// EXPIRE_DAYS_CRITの時にメッセージを送る追加回数
define('SEND_ADD_NUM_EXPIRE_DAYS_CRIT', 2);

main($argv);
exit;


/**
 * array(10,30)で残り日数を調整できるarray(0) なら調整なし
 * @return int[]
 */
function getAryDebugExpireDays() {
    return array(0);
}

/**
 * whoisで取得してきたなかから必要なデータを取り出す為のキーの定義
 * @return array
 */
function getKeywordWhois()
{
    static $key = array(
        'admin' => WHOIS_KEY_ADMIN,
        'expire' => WHOIS_KEY_EXPIRE,
        'status' => WHOIS_KEY_STATUS,
    );

    return $key;
}

/**
 * whois情報をチャットワークに流す
 * @param $argv
 */
function main($argv)
{
    try {
        $domain = '';
        $cwApiToken = '';
        $cwApiRoomId = '';
        $cwApiToIdCsv = '';

        // 起動引数から必要なデータを取得する
        if (count($argv) == ARGI_MAX) {
            $domain = $argv[ARGI_CHECK_DOMAIN];
            $cwApiToken = $argv[ARGI_CHATWORK_API_TOKEN];
            $cwApiRoomId = $argv[ARGI_CHATWORK_ROOM_ID];
            $cwApiToIdCsv = $argv[ARGI_CHATWORK_TO_ID_CSV];
        } else {
            // 起動の仕方に失敗
            throw new Exception('arg error: php chatwork.php $CHATWORK_API_TOKEN $CHATWORK_ROOM_ID [$CHATWORK_TO_ID_CSV,$CHATWORK_TO_ID_CSV,...]');
        }

        $aryPushWhois = array(); // 出力する用のwhoisデータ

        try {
            // whoisコマンドで取得
            $aryResWhois = whois($domain);

            // 必要なデータだけに切り分ける
            foreach ($aryResWhois as $resLine) {
                // 必要なキーワードか調べる
                $aryKey = getKeywordWhois();
                foreach ($aryKey as $key => $value) {
                    if (mb_strpos($resLine, $value) !== false) {
                        // key と valueで分けて代入
                        preg_match('/ +[^ ]+/', $resLine, $m);
                        $aryPushWhois[$value] = (empty($m[0]) == false) ? trim($m[0]) : '';
                        break;
                    }
                }
            }

            // データが取得できているか確認して残り日数を算出する
            if (count($aryPushWhois) == count(getKeywordWhois())
                && isset($aryPushWhois[WHOIS_KEY_EXPIRE])
            ) {
                $expire = strtotime($aryPushWhois[WHOIS_KEY_EXPIRE]);
                $now = strtotime('yesterday noon');
                $aryPushWhois[WHOIS_KEY_DAYS] = (int)ceil(($expire - $now) / (60 * 60 * 24));
            } else {
                // 取得できていない場合はエラー
                throw new Exception('whois -h whois.jp $DOMAIN の解析に失敗しました');
            }

        } catch (Exception $e) {
            // フォーマットが変わったなどでエラーの場合表示できるように代入する
            $aryPushWhois['Exception'] = $e;
        }

        // 一度に全レベル送れるようにしておく
        $aryDebugExpireDays = getAryDebugExpireDays();
        foreach ($aryDebugExpireDays as $expireDays) {

            // DEBUG用に上掛けるようにしておく
            if (0 < $expireDays) {
                $aryPushWhois[WHOIS_KEY_DAYS] = $expireDays;
            }

            // メッセージの作成
            $data = getCreateMessage($domain, $aryPushWhois, $cwApiToIdCsv);

            for ($i = 0; $i < $data->num; $i++) {
                // チャットワークに出力
                $cwRes = pushChatworkRoom($cwApiToken, $cwApiRoomId, $data->mess);
            }
        }

        // var_dump($cwRes);

    } catch (Exception $mainE) {
        var_dump($mainE);
    }
}


/**
 * $dataの内容により本文を作成する
 * @param string $domain
 * @param array $data
 * @param string $toIdCsv
 * @return stdClass
 */
function getCreateMessage($domain, $data, $toIdCsv = '')
{
    $result = new stdClass();

    $body = '';
    $toIdMess = '';
    $sendNum = 1;
    $expireDays = $data[WHOIS_KEY_DAYS];

    // TOに入れる人を設定する
    if (empty($toIdCsv) == false) {
        $aryToId = explode(',', $toIdCsv);
        foreach ($aryToId as $toId) {
            $toIdMess .= sprintf('[To:%d]', $toId);
        }
    }

    // 有効期限により内容を変える
    if ($expireDays < EXPIRE_DAYS_CRIT) {
        // 気がつくように何度か追加して送る
        $sendNum += SEND_ADD_NUM_EXPIRE_DAYS_CRIT;

        $body = ''
            . $domain . ' is ' . $data[WHOIS_KEY_STATUS]
            . '：有効期限の' . $data[WHOIS_KEY_EXPIRE] . 'まで残り' . $data[WHOIS_KEY_DAYS] . '日です。' . PHP_EOL
            . 'これを見た方は至急' . $data[WHOIS_KEY_ADMIN] . 'に連絡してください。';

    } else if ($expireDays < EXPIRE_DAYS_WARN) {
        $body = ''
            . $domain . ' is ' . $data[WHOIS_KEY_STATUS]
            . '：有効期限の' . $data[WHOIS_KEY_EXPIRE] . 'まで残り' . $data[WHOIS_KEY_DAYS] . '日です。' . PHP_EOL
            . $data[WHOIS_KEY_ADMIN] . 'は更新準備をしてください。';

    } else {
        // TOをつけない
        $toIdMess = '';
        $body = ''
            . $domain . ' is ' . $data[WHOIS_KEY_STATUS]
            . '：有効期限の' . $data[WHOIS_KEY_EXPIRE] . 'まで残り' . $data[WHOIS_KEY_DAYS] . '日です。'
            . '';
    }

    $result->num = $sendNum;
    $result->mess = $toIdMess . PHP_EOL . '[info][title]ドメイン更新を確認してください[/title]' . $body . '[/info]';

    return $result;
}

/**
 */
/**
 * Chatworkにメッセージをプッシュする：参考サイトそのまま
 * @see http://www.sukicomi.net/2015/10/chatworkapi-postmessage.html
 *
 * @param string $apiToken chatworkにメッセージを送る為のTOKEN
 * @param int $roomId chatworkに送る部屋ID
 * @param string $body 送る本文
 * @return stdClass APIから返ってくるレスポンスをデコードしたもの
 */
function pushChatworkRoom($apiToken, $roomId, $body)
{
    // ヘッダ
    header("Content-type: text/html; charset=utf-8");

    // POST送信データ
    $params = array(
        'body' => $body
    );

    // cURLに渡すオプションを設定
    $options = array(
        CURLOPT_URL => "https://api.chatwork.com/v1/rooms/{$roomId}/messages", // URL
        CURLOPT_HTTPHEADER => array('X-ChatWorkToken: ' . $apiToken), // APIトークン
        CURLOPT_RETURNTRANSFER => true, // 結果を文字列で返す
        CURLOPT_SSL_VERIFYPEER => false, // サーバー証明書の検証を行わない
        CURLOPT_POST => true, // HTTP POSTを実行
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&'), // POST送信データ
    );

    // cURLセッションを初期化
    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    curl_close($ch);

    // 結果のJSON文字列をデコード
    $result = json_decode($response);

    return $result;
}


/**
 * whois情報を返す
 * @param string $domain
 * @return string[]
 */
function whois($domain)
{
    $result = array();

    // ドメインであることを正規表現で確認して実行
    if (preg_match('/([A-Za-z0-9][A-Za-z0-9\-]{1,61}[A-Za-z0-9]\.)+[A-Za-z]+/', $domain) == 1) {
        $result = myexec('whois -h whois.jp ' . $domain);
    }

    return $result;

}


/**
 * execの実行結果を返す
 * @param string $cmd 実行するコマンド
 * @return string[] 実行結果
 */
function myexec($cmd)
{
    $output = array();
    $status = '';

    exec($cmd, $output, $status);

    return $output;
}

