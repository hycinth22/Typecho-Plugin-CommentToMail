Typecho 评论邮件提醒插件
=============
访客评论后，将会发送评论内容到您指定的邮箱。

相比原版本，使用消息队列取代内部http调用，提升访客评论流畅度体验。

遵循GPL LICENSE v2，衍生于[CommentToMail Byends版本 v2.0.0](http://typecho.byends.com/post/CommentToMail-v2-0-0.html)

### 实验性警告
发布仓促，许多地方尚待完善。如果发现问题请及时与我联系，也欢迎各种issue、PR。

### 使用说明
1. 下载插件
2. 将插件上传到 `/usr/plugins/` 目录下
3. 修改主题模板`comments.php`文件，在评论form表单的适当位置添加name为`receiveMail`的选择框（checkbox）。[示例代码](#评论参考代码)
4. 登陆后台，在“控制台”下拉菜单中进入“插件管理”
5. 启用相关插件
6. 设置smtp服务器地址、邮箱地址、密码等信息
7. 设置cron定时执行发送队列。(注意替换链接中的key为所自己设置的)

## 评论参考代码
*该代码必须在适当位置加入，如未加入该代码，则插件将默认按照不发送提醒邮件处理所有评论。*
+ 正常显示选择框： `<input type="checkbox" name="receiveMail" id="receiveMail" value="yes" checked /> <label for="receiveMail" style="padding-left:8px;">当有人回复时接收邮件提醒</label>` 
+ 隐藏选择框（默认接受邮件）： `<input type="hidden" name="receiveMail" id="receiveMail" value="yes" />` 

### 升级日志

##### 3.0.0 Upgrade at 2016-07-30

版本要求：需要 Typecho `0.9 (13.12.12)`

注意：由于此版本改动较大，请先在 插件管理 中心禁用该插件的低级版本，然后再上传插件并重新激活插件，配置设置
