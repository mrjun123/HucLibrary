<?php
require_once 'AipOcr.php';
        
class HucLibrary{
    
    public $redis;
    public $login_url = 'http://222.27.188.3/api.php/login';
    public $code_url = 'http://222.27.188.3/api.php/check';
    public $book_url = 'http://222.27.188.3/api.php/spaces/_ID_/book';
    public $cookie;
    public $params;
    public $app_id = ''; //百度图像识别app_id
    public $api_key = ''; //百度图像识别api_key
    public $secret_key = ''; //百度图像识别secret_key
    
    function __construct( $params = null ) {
        //初始化Rediis
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        $this->redis = $redis;
        //初始化百度云
        $client = new AipOcr($this->app_id, $this->api_key, $this->secret_key);
        $this->client = $client;
        
        if($params){
            $this->params = $params;
            //若已存在则不妨问
            if($redis->get("library-".$params['username'])){
                return;
            }
            $this->getHash();
            
        }
    }
    
    //2021-11-17
    function book($id, $segment, $date){
        //生成URL
        $url = str_replace('_ID_', $id, $this->book_url);
        
        $access_token = $this->redis->get("library-".$this->params['username']);
        if(!$access_token){
            $this->getHash();
            $access_token = $this->redis->get("library-".$this->params['username']);
        }
        
        $curl = curl_init();

        curl_setopt_array($curl, array(
          CURLOPT_URL => $url,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => http_build_query([
              'access_token' => $access_token,
              'userid' => $this->params['username'],
              'segment' => $segment,
              'operateChannel' => '2',
              'type' => '1'
            ]) ,
          CURLOPT_HTTPHEADER => array(
            'Host:  222.27.188.3',
            'Accept:  application/json, text/javascript, */*; q=0.01',
            'Accept-Encoding:  gzip, deflate',
            'Content-Type:  application/x-www-form-urlencoded; charset=UTF-8',
            'Origin:  http://222.27.188.3',
            'Referer:  http://222.27.188.3/web/seat3?area=30&segment='.$segment.'&day='.$date.'&startTime=06:00&endTime=22:00'
          ),
        ));
        
        $response = curl_exec($curl);
        
        curl_close($curl);
        echo $response;
        
        $arr = json_decode($response, true);
        if($arr['status'] == 0 && $arr['msg'] == "没有登录或登录已超时"){
            $this->getHash();
        }
        return $arr;

    }
    
    
    function getCode(){
        
        $code =  "1";
        $count = 0;
        while(strlen($code) != 4 || !is_numeric($code)){
            sleep(1);
            $image = $this->getCodeImage();
            $code =  $this->client->handwriting($image, [])['words_result'][0]['words'];
            
            //限制
            $count++;
            if($count > 5){
                exit(json_encode([
                    'status' => 0,
                    'msg' => "解析验证码失败，请稍后重试！"
                ]));
            }
        }
        
        return $code;
    }
    
    function getHashInfo(){
        
        $code = $this->getCode();
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->login_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $this->params['username'],
                'password' => $this->params['password'],
                'verify' => $code
            ]),
            CURLOPT_HTTPHEADER => array(
                'Host:  222.27.188.3',
                'Accept:  application/json, text/javascript, */*; q=0.01',
                'Accept-Encoding:  gzip, deflate',
                'Content-Type:  application/x-www-form-urlencoded; charset=UTF-8',
                'Origin:  http://222.27.188.3',
                'Referer:  http://222.27.188.3/web/seat3?area=30&segment=1298525&day=2021-11-16&startTime=06:00&endTime=22:00'
            )
        ));
        //使用cookie
        curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookie);
        
        $response = curl_exec($curl);
        curl_close($curl);
        
        $info = json_decode($response, true);
        return $info;
    }
    
    function getHash(){
        
        $info = $this->getHashInfo();
        $count = 0;
        
        while($info['status'] != 1 && $count < 4){
            $info = $this->getHashInfo();
        }
        
        if($info['status'] == 1){
            //存储hash
            $this->redis->set(
                "library-".$this->params['username'], 
                $info['data']['_hash_']['access_token']
            );
        }else{
            exit(json_encode([
                'status' => 0,
                'msg' => '模拟登陆失败，请重试！'
            ]));
        }
    }
    
    
    function getCodeImage(){
        
        $cookie = tempnam('./tmp','cookie');
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->code_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Host:  222.27.188.3',
                'Accept:  application/json, text/javascript, */*; q=0.01',
                'Accept-Encoding:  gzip, deflate',
                'Content-Type:  application/x-www-form-urlencoded; charset=UTF-8',
                'Origin:  http://222.27.188.3',
                'Referer:  http://222.27.188.3/web/seat3?area=30&segment=1298525&day=2021-11-16&startTime=06:00&endTime=22:00'
            )
        ));
        curl_setopt($curl, CURLOPT_COOKIEJAR, $cookie);
        
        $response = curl_exec($curl);
        $this->cookie = $cookie;
        curl_close($curl);
        return $response;
    }
    
}

function getBookDate(){
    // return date('Y-m-d', time() + (86400*2));
    // return date('Y-m-d', time());
    $hour = date('H');
    $mini = date('i');
    if($hour <= 1 && $mini <= 10){
        return date('Y-m-d', time() + 86400);
    }
    if($hour >= 23  && $mini >= 50){
        return date('Y-m-d', time() + (86400*2));
    }
    exit(json_encode([
        'status' => 0,
        'msg' => '未到时间！',
        'hour' => $hour,
        'mini' => $mini,
        'segment' => getBookSegment()
    ]));
}

function getBookSegment(){
    $time = time();
    $day = ($time - 1636905600)/86400;
    $day = (int)$day;
    return 1298526 + $day;
}

$date = getBookDate();
$segment = getBookSegment();

$params = [
    'username' => '', //默认账户
    'password' => '' //默认密码
];

$id = "6116"; //默认id

if(isset($argv[1]) && isset($argv[2]) && isset($argv[3])){
    $params = [
        'username' => $argv[1],
        'password' => $argv[2]
    ];
    // $segment = $argv[3];
    $id = $argv[3];
}
$library = new HucLibrary($params);

$res = $library->book($id, $segment, $date);
//重试四次
$count = 0;
while($res['status'] == 0 && $count < 4){
    $res = $library->book($segment, $date);
}


