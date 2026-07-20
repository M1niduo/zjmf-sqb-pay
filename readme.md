# 收钱吧码牌聚合收款插件

> 魔方财务 微信、支付宝、云闪付收款插件。魔方财务码支付

## 免费开通

开通联系微信 `shouqianba764` 备注来源 trexk

![alt text](6B9E7A0C-B854-4360-8E74-606E66DA23CB.png)

## 安装教程

1. 把 `sqb_pay` 目录放到 `public/plugins/gateways` 下

2. `command.php` 放到 app 目录下

3. 开启定时任务和配置财务的定时任务一样，推荐5~10秒执行一次

```shell
php /www/wwwroot/127.0.0.1/think sqb_polling
```


## 配置说明

1. password 登录收钱吧官网登录并获取登录密码
2. username 你的手机号
3. qr_code_image 码牌内容
4. terminal_sn 码牌终端号
