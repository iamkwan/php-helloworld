<?php

define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
define('API_FILE_URL', 'https://api.telegram.org/file/bot'.BOT_TOKEN.'/');
define('ROOT_URL', 'https://upload.cc/tg/');
define('UPLOAD_FOLDER', 'i/');

function apiRequestWebhook($method, $parameters) {
  if (!is_string($method)) {
    //error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    //error_log("Parameters must be an array\n");
    return false;
  }

  $parameters["method"] = $method;

  $payload = json_encode($parameters);
  header('Content-Type: application/json');
  header('Content-Length: '.strlen($payload));
  echo $payload;

  return true;
}

function exec_curl_request($handle) {
  $response = curl_exec($handle);

  if ($response === false) {
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    //error_log("Curl returned error $errno: $error\n");
    curl_close($handle);
    return false;
  }

  $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
  curl_close($handle);

  if ($http_code >= 500) {
    // do not wat to DDOS server if something goes wrong
    sleep(10);
    return false;
  } else if ($http_code != 200) {
    $response = json_decode($response, true);
    //error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
    if ($http_code == 401) {
      throw new Exception('Invalid access token provided');
    }
    return false;
  } else {
    $response = json_decode($response, true);
    if (isset($response['description'])) {
      //error_log("Request was successful: {$response['description']}\n");
    }
    $response = $response['result'];
  }

  return $response;
}

function apiRequest($method, $parameters) {
  if (!is_string($method)) {
    //error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    //error_log("Parameters must be an array\n");
    return false;
  }

  foreach ($parameters as $key => &$val) {
    // encoding to JSON array parameters, for example reply_markup
    if (!is_numeric($val) && !is_string($val)) {
      $val = json_encode($val);
    }
  }
  $url = API_URL.$method.'?'.http_build_query($parameters);

  $handle = curl_init($url);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($handle, CURLOPT_TIMEOUT, 60);

  return exec_curl_request($handle);
}

function apiRequestJson($method, $parameters) {
  if (!is_string($method)) {
    //error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    //error_log("Parameters must be an array\n");
    return false;
  }

  $parameters["method"] = $method;

  $handle = curl_init(API_URL);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($handle, CURLOPT_TIMEOUT, 60);
  curl_setopt($handle, CURLOPT_POST, true);
  curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
  curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

  return exec_curl_request($handle);
}

function processMessage($message) {
  $message_id = $message['message_id'];
  $chat_id = $message['chat']['id'];
  if (isset($message['photo'])) {
    $process_message = apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => '上傳中...'));
    $process_photo = processPhoto($chat_id, $message['photo']);
    if ($process_photo) {
        apiRequestWebhook("editMessageText", array('chat_id' => $chat_id, "message_id" => $process_message['message_id'], "text" => $process_photo, "disable_web_page_preview" => true));
    } else {
        apiRequestWebhook("editMessageText", array('chat_id' => $chat_id, "message_id" => $process_message['message_id'], "text" => '上傳失敗'));
    }
  } else if (isset($message['document'])) {
    if (checkFileType($message['document']['mime_type'])) {
      $process_message = apiRequest("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => '上傳中...'));
      $process_document = processDocument($chat_id, $message['document']);
      if ($process_document) {
        apiRequestWebhook("editMessageText", array('chat_id' => $chat_id, "message_id" => $process_message['message_id'], "text" => $process_document, "disable_web_page_preview" => true));
      } else {
        apiRequestWebhook("editMessageText", array('chat_id' => $chat_id, "message_id" => $process_message['message_id'], "text" => '上傳失敗'));
      }
    } else {
      apiRequestWebhook("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => '只接受 JPG, JPEG, GIF, PNG, BMP'));
    }
  } else {
    $text = <<<EOF
*歡迎使用 Upload.cc Bot\n*
*使用條款*
• https://upload.cc/terms\n
*上傳限制 (<10 MB)*
• JPG, JPEG, GIF, PNG, BMP\n
*傳送圖片方法*
• Send as Photo (會壓縮圖片)
• Send as File (不會壓縮圖片)\n
*指令*
• /my - 上傳記錄
• /help - 顯示此訊息
EOF;
  $buttons = [
    [
      ['text' => "上傳記錄", 'callback_data' => "history"],
      ['text' => "贊助我們", 'url' => "https://www.buymeacoffee.com/uploadcc"]
    ]
  ];
  apiRequestWebhook("sendMessage", array('chat_id' => $chat_id, "reply_to_message_id" => $message_id, "text" => $text, "reply_markup" => make_inline_keyboard($buttons), "parse_mode" => "Markdown"));
  }
}

function processPhoto($chat_id, $photo) {
  $file_id = $photo[sizeof($photo)-1]['file_id'];
  $result = apiRequestJson("getFile", array('file_id' => $file_id));
  $extension = strtolower(substr($result['file_path'], strrpos($result['file_path'], '.')+1));
  $file_name = generateRandomString();
  $full_file_path = UPLOAD_FOLDER.$file_name.'.'.$extension;
  $put_file = file_put_contents('/'.$file_name.'.'.$extension, file_get_contents(API_FILE_URL.$result['file_path']));
  return ROOT_URL.$full_file_path;
}

function processDocument($chat_id, $document) {
  $file_id = $document['file_id'];
  $result = apiRequestJson("getFile", array('file_id' => $file_id));
  $extension = strtolower(substr($result['file_path'], strrpos($result['file_path'], '.')+1));
  $file_name = generateRandomString();
  $full_file_path = UPLOAD_FOLDER.$file_name.'.'.$extension;
  $put_file = file_put_contents('/'.$file_name.'.'.$extension, file_get_contents(API_FILE_URL.$result['file_path']));
  return ROOT_URL.$full_file_path;
}

function checkFileType($mime_type) {
    $image_type = array('image/jpeg', 'image/png', 'image/gif', 'image/bmp');
    return in_array($mime_type, $image_type);
}

function make_inline_keyboard($buttons) {
  $keyboard = [
    'inline_keyboard' => $buttons
  ];
  return $keyboard;
}

function generateRandomString($length = 6) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
  exit;
}

if (isset($update["message"])) {
  processMessage($update["message"]);
}

?>
