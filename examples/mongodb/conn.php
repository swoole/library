<?php
require 'vendor/autoload.php';

use MongoDB\Client;
use MongoDB\Exception\Exception;

try {
    // 连接 MongoDB
    $client = new Client("mongodb://localhost:27017");
    
    // 选择数据库和集合
    $database = $client->myDatabase;
    $collection = $database->users;
    
    echo "连接成功！\n";
} catch (Exception $e) {
    echo "连接失败: " . $e->getMessage() . "\n";
}


