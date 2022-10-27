<?php

/** Config */
//$rasa_url = 'https://65a2-2001-ee0-d705-5ae0-cd08-3639-173-28e8.ap.ngrok.io';
$rasa_url = 'http://localhost:5005';
$etouch_url = 'https://ccai.epacific.net';
$etouch_bot_token = 'boJC4movb5sGJJGnVgM4chtA';
$CPS = 70;
$UPDATE_CONTACT_KEYWORD = "Cập nhật thông tin -"; // Update profile keyword
$MERGE_CONTACT_KEYWORD = "Đồng bộ thông tin -"; // Merge profile keyword
$HANDOFF_KEYWORD = "Vui lòng đợi trong giây lát..."; // Handoff keyword

/** Get data from etouch send to router */
$json = file_get_contents('php://input');
error_log("request payload: #{$json}", 0);

$data = json_decode($json);

/** Declare variables */
$message_type = $data->message_type; // message type
$message = $data->content; // message content

$conversation = $data->conversation->id; // conversation id
$status = $data->conversation->status; // conversation status

$contact = $data->sender->id; // contact id
$account = $data->account->id; // account id

$username = $data->sender->name; // chat user name

$inbox_name = $data->inbox->name; // inbox name
$inbox_realname = get_inbox_name($inbox_name); // actual inbox name from $inbox_name string

$channel = $data->conversation->channel; // inbox channel
$channel_name = join("", split_str($channel));

/** Provide necessary slot(s) to Rasa */
//send_to_bot($contact, "kênh hiện tại là $channel_name"); // Provide channel to Rasa
//send_to_bot($contact, "inbox là $inbox_realname"); // Provide channel to Rasa

// Is click on button
$clickValue = isSubmittedValues($data);
if (is_object($clickValue)) {
  $message = $clickValue->msg;
  $message_type = $clickValue->type;
}

/** Logging variables */
error_log("username: {$username}", 0);
error_log("inbox: {$inbox_realname}", 0);
error_log("channel: {$channel}", 0);

error_log("message type: {$message_type}", 0);
error_log("message send to bot: {$message}", 0);

error_log("conversation ID: {$conversation}", 0);
error_log("contact ID: {$contact}", 0);
error_log("account ID: {$account}", 0);


/** Main */
if ($message_type != "incoming") // Only incoming message can pass
  return;

if ($status != "pending") // Only reply if status is pending
  return;

$bot_response = send_to_bot($contact, $message); // Send message to Rasa and receive response(s) from it

// Send response(s) to etouch
foreach ($bot_response as $val) {
  $message_length = strlen($val->text); // Get bot response length
  $delay = $message_length / $CPS; // Generate delay time by message length
  sleep(round($delay)); // Delay

  $send_message_or_update = strpos($val->text, $UPDATE_CONTACT_KEYWORD); // Find if bot response contain UPDATE KEYWORD or not
  $send_message_or_merge = strpos($val->text, $MERGE_CONTACT_KEYWORD); // Find if bot response contain MERGE KEYWORD or not
  // If not contain UPDATE KEYWORD, send message to eTouch
  if ($send_message_or_update === false and $send_message_or_merge === false)
    $create_message = send_to_etouch($account, $conversation, $val);
  // If contain UPDATE KEYWORD, generate payload and update contact
  elseif ($send_message_or_update !== false) {
    $payload = get_update_payload($val->text); // Generate payload
    update_contact($account, $contact, $payload); // Update contact
  }
  // If contain MERGE KEYWORD, generate payload and merge contact
  elseif ($send_message_or_merge !== false) {
    $payload = get_merge_payload($val->text); // Generate payload
    merge_contact($account, $contact, $payload); // Update contact
  }
}

// If contain handoff keyword, then handover to agent
if (strpos(end($bot_response)->text, $HANDOFF_KEYWORD) !== false) {
  // $message_handover = "Em đã chuyển cho tổng đài viên rồi nha, chúc quý khách vui vẻ !";
  toggle_status($account, $conversation);
  // send_to_etouch($account, $conversation, $message_handover);
}


/** Functions */
function send_to_bot($sender, $message)
{
  global $rasa_url;
  $url = "{$rasa_url}/webhooks/rest/webhook";
  $data = array('sender' => $sender, 'message' => $message);

  $options = array(
    'http' => array(
      'method'  => 'POST',
      'content' => json_encode($data),
      'header' =>  "Content-Type: application/json\r\n" .
        "Accept: application/json\r\n"
    )
  );

  $context  = stream_context_create($options);
  $result = file_get_contents($url, false, $context);

  error_log("bot replied: {$result}", 0);

  $response = json_decode($result);
  return $response;
}

function send_to_etouch($account, $conversation, $message)
{
  global $etouch_url, $etouch_bot_token;
  $url = "{$etouch_url}/api/v1/accounts/{$account}/conversations/{$conversation}/messages";
  $data = process_data($message);

  $options = array(
    'http' => array(
      'method'  => 'POST',
      'content' => json_encode($data),
      'header' =>  "Content-Type: application/json\r\n" .
        "Accept: application/json\r\n" .
        "api_access_token: {$etouch_bot_token}"
    )
  );

  $context  = stream_context_create($options);
  $result = file_get_contents($url, false, $context);
  error_log("etouch response: {$result}", 0);
  // $response = json_decode($result);
  return $result;
}

function toggle_status($account, $conversation)
{
  global $etouch_url, $etouch_bot_token;
  $url = "{$etouch_url}/api/v1/accounts/{$account}/conversations/{$conversation}/toggle_status";
  $data = array('status' => 'open');

  $options = array(
    'http' => array(
      'method'  => 'POST',
      'status' => json_encode($data),
      'header' =>  "Content-Type: application/json\r\n" .
        "Accept: application/json\r\n" .
        "api_access_token: {$etouch_bot_token}"
    )
  );

  $context  = stream_context_create($options);
  $result = file_get_contents($url, false, $context);
  error_log("etouch response: {$result}", 0);
  $response = json_decode($result);

  $status = $response->payload->current_status;
  error_log("etouch response status: {$status}");
  return $result;
}

function update_contact($account, $contact, $payload)
{
  global $etouch_url;
  $url = "{$etouch_url}/api/v1/accounts/{$account}/contacts/{$contact}";
  $data = $payload;

  $options = array(
    'http' => array(
      'method'  => 'PATCH',
      'content' => json_encode($data),
      'header' =>  "Content-Type: application/json\r\n" .
        "Accept: application/json\r\n" .
        "api_access_token: 78ARRSX2ofwSwVJJGivJiBTP"
    )
  );

  $context  = stream_context_create($options);
  $result = file_get_contents($url, false, $context);
  error_log("updated content: {$result}", 0);
  return $result;
}

function merge_contact($account, $contact, $payload)
{
  global $etouch_url;
  $url = "{$etouch_url}/api/v1/accounts/{$account}/contacts/{$contact}";
  $payload->mergee_contact_id = $contact;
  $data = $payload;

  $options = array(
    'http' => array(
      'method'  => 'POST',
      'content' => json_encode($data),
      'header' =>  "Content-Type: application/json\r\n" .
        "Accept: application/json\r\n" .
        "api_access_token: 78ARRSX2ofwSwVJJGivJiBTP"
    )
  );

  $context  = stream_context_create($options);
  $result = file_get_contents($url, false, $context);
  error_log("merged content: {$result}", 0);
  return $result;
}

function process_data($data)
{
  if (is_string($data))
    return array('content' => $data);

  if (!is_object($data))
    return "Not stdClass";

  if (property_exists($data, "custom"))
    return $data->custom;

  return array('content' => $data->text);
}

function isSubmittedValues($data)
{
  if (!property_exists($data, "content_attributes"))
    return false;

  if (!property_exists($data->content_attributes, "submitted_values"))
    return false;

  $val = $data->content_attributes->submitted_values[0]->value;
  $ar = array("msg" => $val, "type" => "incoming");
  $object = json_decode(json_encode($ar), FALSE);
  return $object;
}

function get_inbox_name($inbox_name)
{
  $arr = split_str($inbox_name);
  if (!is_array($arr))
    return "Not Array";

  $legal_channel = array("web", "zalo");

  foreach ($arr as $val) {
    $lower_name = strtolower($val);

    if (in_array($lower_name, $legal_channel))
      return $lower_name;
  }
}

function split_str($str)
{
  if (!is_string($str))
    return "Not String";

  $pattern = "/[`!@#$%^&*()_+\-=\[\]{};':\\|,.<>\/?~]/i";
  return preg_split($pattern, $str, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
}

function get_update_payload($bot_response)
{
  $update_msg = explode("-", $bot_response);
  $update_content = explode(":", trim($update_msg[1]));
  $key = trim($update_content[0]);
  $value = trim($update_content[1]);
  $o = (object) [
    "Tên" => (object) ["name" => $value],
    "SDT" => (object) ["phone_number" => $value],
    "Email" => (object) ["email" => $value],
    "Site" => (object) ["custom_attributes" => (object) ["website" => $value]],
    "Giới tính" => (object) ["custom_attributes" => (object) ["gender" => ($value == "nam" ? 1 : 0)]]
  ];
  return $o->$key;
}

function get_merge_payload($bot_response)
{
  $merge_msg = explode("-", $bot_response);
  $merge_content = explode(":", trim($merge_msg[1]));
  $key = trim($merge_content[0]);
  $value = trim($merge_content[1]);
  $o = (object) [
    "EID" => (object) ["base_contact_id" => $value]
  ];
  return $o->$key;
}
