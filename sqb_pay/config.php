<?php
return [
    'terminal_sn' => [
        'title' => '终端号',
        'type'  => 'text',
        'value' => '',
        'tip'   => '',
    ],
    'qr_code_image' => [
        'title' => '码牌二维码内容',
        'type'  => 'text',
        'value' => '',
        'tip'   => '收钱吧码牌静态二维码内容',
    ],
    'username' => [
        'title' => '收钱吧账号',
        'type'  => 'text',
        'value' => '',
        'tip'   => '收钱吧商户平台登录账号（手机号）',
    ],
    'password' => [
        'title' => '收钱吧密码',
        'type'  => 'text',
        'value' => '',
        'tip'   => '收钱吧商户平台登录密码',
    ],
    'polling_timeout' => [
        'title' => '支付超时(秒)',
        'type'  => 'text',
        'value' => '300',
        'tip'   => '支付超时时间，超过此时间自动取消订单，单位秒',
    ],
    'max_floating_amount' => [
        'title' => '最大上浮金额(分)',
        'type'  => 'text',
        'value' => '1000',
        'tip'   => '生成唯一金额时允许的最大上浮金额，单位分，默认1000分即10元',
    ],
];
