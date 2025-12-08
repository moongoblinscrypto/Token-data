<?php
require_once __DIR__ . '/db.php';

function ml_get_setting($key, $default = ''){
    $db = moog_db();
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
    if(!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($val);
    if($stmt->fetch()){
        $stmt->close();
        return $val;
    }
    $stmt->close();
    return $default;
}
