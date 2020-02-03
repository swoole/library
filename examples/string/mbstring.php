<?php

require __DIR__ . '/../bootstrap.php';

$str = _mbstring("我是中国人");
var_dump($str->substr(1, 2));
var_dump($str->contains("中国"));
var_dump($str->contains("美国"));
var_dump($str->startsWith("我"));
var_dump($str->endsWith("不是"));
var_dump($str->length());
