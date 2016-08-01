<?php
/**
 * 评论邮件提醒插件
 *
 * @package CommentToMail
 * @author aprikyblue
 * @version 3.0
 * @link https://github.com/aprikyblue/Typecho-Plugin-CommentToMail
 * @oriAuthor Byends Upd.(http://www.byends.com/) / DEFE (http://defe.me)
 * 
 */
class CommentToMail_Plugin implements Typecho_Plugin_Interface
{
    /** @var string 提交路由前缀 */
    public static $action = 'comment-to-mail';

    /** @var bool 内部请求User-Agent */
    public static $ua = 'MailMessageBrid';

    /** @var string 控制菜单链接 */
    public static $panel  = 'CommentToMail/page/console.php';

    /** @var bool 是否记录日志 */
    private static $_isMailLog  = false;
    
    /** @var bool 请求适配器 */
    private static $_adapter    = false;

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        if (false == self::isAvailable()) {
            throw new Typecho_Plugin_Exception(_t('对不起, 您的主机没有打开 allow_url_fopen 功能而且不支持 php-curl 扩展, 无法正常使用此功能'));
        }
        
        $cacheDir = sys_get_temp_dir();
        if ( !( file_exists($cacheDir) || mkdir($cacheDir, 0644) ) )
        {
            throw new Typecho_Plugin_Exception(_t('对不起，创建缓存目录失败，无法正常使用此功能'));
        }
        if (false == self::isWritable($cacheDir)) {
            throw new Typecho_Plugin_Exception(_t('对不起，缓存目录不可写，无法正常使用此功能'));
        }

        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array('CommentToMail_Plugin', 'parseComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = array('CommentToMail_Plugin', 'parseComment');
        Helper::addAction(self::$action, 'CommentToMail_Action');
        Helper::addRoute('commentToMailProcessQueue', '/commentToMailProcessQueue/', 'CommentToMail_Action', 'processQueue');
        Helper::addPanel(1, self::$panel, '评论邮件提醒', '评论邮件提醒控制台', 'administrator');

        return _t('请设置邮箱信息，以使插件正常使用！');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        Helper::removeAction(self::$action);
        Helper::removeRoute('commentToMailProcessQueue');
        Helper::removePanel(1, self::$panel);
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $options = Typecho_Widget::widget('Widget_Options');

        $mode= new Typecho_Widget_Helper_Form_Element_Radio('mode',
                array( 'smtp' => 'smtp',
                       'mail' => 'mail()',
                       'sendmail' => 'sendmail()'),
                'smtp', '发信方式');
        $form->addInput($mode);

        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, 'smtp.',
                _t('SMTP地址'), _t('请填写 SMTP 服务器地址'));
        $form->addInput($host->addRule('required', _t('必须填写一个SMTP服务器地址')));

        $port = new Typecho_Widget_Helper_Form_Element_Text('port', NULL, '25',
                _t('SMTP端口'), _t('SMTP服务端口,一般为25。'));
        $port->input->setAttribute('class', 'mini');
        $form->addInput($port->addRule('required', _t('必须填写SMTP服务端口'))
                ->addRule('isInteger', _t('端口号必须是纯数字')));

        $user = new Typecho_Widget_Helper_Form_Element_Text('user', NULL, NULL,
                _t('SMTP用户'),_t('SMTP服务验证用户名,一般为邮箱名如：youname@domain.com'));
        $form->addInput($user->addRule('required', _t('SMTP服务验证用户名')));

        $pass = new Typecho_Widget_Helper_Form_Element_Password('pass', NULL, NULL,
                _t('SMTP密码'));
        $form->addInput($pass->addRule('required', _t('SMTP服务验证密码')));

        $validate = new Typecho_Widget_Helper_Form_Element_Checkbox('validate',
                array('validate'=>'服务器需要验证',
                    'ssl'=>'ssl加密'),
                array('validate'),'SMTP验证');
        $form->addInput($validate);
        
        $fromName = new Typecho_Widget_Helper_Form_Element_Text('fromName', NULL, NULL,
                _t('发件人名称'),_t('发件人名称，留空则使用博客标题'));
        $form->addInput($fromName);

        $mail = new Typecho_Widget_Helper_Form_Element_Text('mail', NULL, NULL,
                _t('接收邮件的地址'),_t('接收邮件的地址,如为空则使用文章作者个人设置中的邮件地址！'));
        $form->addInput($mail->addRule('email', _t('请填写正确的邮件地址！')));

        $contactme = new Typecho_Widget_Helper_Form_Element_Text('contactme', NULL, NULL,
                _t('模板中“联系我”的邮件地址'),_t('联系我用的邮件地址,如为空则使用文章作者个人设置中的邮件地址！'));
        $form->addInput($contactme->addRule('email', _t('请填写正确的邮件地址！')));

        $status = new Typecho_Widget_Helper_Form_Element_Checkbox('status',
                array('approved' => '提醒已通过评论',
                        'waiting' => '提醒待审核评论',
                        'spam' => '提醒垃圾评论'),
                array('approved', 'waiting'), '提醒设置',_t('该选项仅针对博主，访客只发送已通过的评论。'));
        $form->addInput($status);

        $other = new Typecho_Widget_Helper_Form_Element_Checkbox('other',
                array('to_owner' => '有评论及回复时，发邮件通知博主。',
                    'to_guest' => '评论被回复时，发邮件通知评论者。',
                    'to_me'=>'自己回复自己的评论时，发邮件通知。(同时针对博主和访客)',
                    'to_log' => '记录邮件发送日志。'),
                array('to_owner','to_guest'), '其他设置',_t('选中该选项插件会在log/mailer_log.txt 文件中记录发送日志。'));
        $form->addInput($other->multiMode());

        $titleForOwner = new Typecho_Widget_Helper_Form_Element_Text('titleForOwner',null,"[{title}] 一文有新的评论",
                _t('博主接收邮件标题'));
        $form->addInput($titleForOwner->addRule('required', _t('博主接收邮件标题 不能为空')));

        $titleForGuest = new Typecho_Widget_Helper_Form_Element_Text('titleForGuest',null,"您在 [{title}] 的评论有了回复",
                _t('访客接收邮件标题'));
        $form->addInput($titleForGuest->addRule('required', _t('访客接收邮件标题 不能为空')));

        
        $entryUrl = ($options->rewrite) ? $options->siteUrl : $options->siteUrl . 'index.php';

        $deliverMailUrl = rtrim($entryUrl, '/') . '/action/' . self::$action . '?do=deliverMail&key=[yourKey]';
        $key = new Typecho_Widget_Helper_Form_Element_Text('key',null, Typecho_Common::randString(16),
                _t('key'), _t('执行发送任务地址为'.$deliverMailUrl) );
        $form->addInput($key->addRule('required', _t('key 不能为空.')));

        $nonAuthUrl = rtrim($entryUrl, '/') . '/commentToMailProcessQueue/';
        $nonAuth = new Typecho_Widget_Helper_Form_Element_Checkbox('verify',
                array('nonAuth'=>'开启不验证key(特殊环境可使用) '.$nonAuthUrl),
                array(),'执行验证');
        $form->addInput($nonAuth);

    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {}

	public static function linksInstall()
	{
		$installDb = Typecho_Db::get();
		$type = explode('_', $installDb->getAdapterName());
		$type = array_pop($type);
		$prefix = $installDb->getPrefix();
		$scripts = file_get_contents('usr/plugins/CommentToMail/'.$type.'.sql');
		$scripts = str_replace('typecho_', $prefix, $scripts);
		$scripts = str_replace('%charset%', 'utf8', $scripts);
		$scripts = explode(';', $scripts);
		try {
			foreach ($scripts as $script) {
				$script = trim($script);
				if ($script) {
					$installDb->query($script, Typecho_Db::WRITE);
				}
			}
			return '建立邮件队列数据表，插件启用成功';
		} catch (Typecho_Db_Exception $e) {
			$code = $e->getCode();
			if(('Mysql' == $type && 1050 == $code) ||
					('SQLite' == $type && ('HY000' == $code || 1 == $code))) {
				try {
					$script = 'SELECT `id`, `content`, `sent` FROM `' . $prefix . 'mail`';
					$installDb->query($script, Typecho_Db::READ);
					return '检测到邮件队列数据表，插件启用成功';					
				} catch (Typecho_Db_Exception $e) {
					$code = $e->getCode();
					if(('Mysql' == $type && 1054 == $code) ||
							('SQLite' == $type && ('HY000' == $code || 1 == $code))) {
						return Links_Plugin::linksUpdate($installDb, $type, $prefix);
					}
					throw new Typecho_Plugin_Exception('数据表检测失败，插件启用失败。错误号：'.$code);
				}
			} else {
				throw new Typecho_Plugin_Exception('数据表建立失败，插件启用失败。错误号：'.$code);
			}
		}
	}

    /**
     * 获取邮件内容
     *
     * @access public
     * @param $comment 调用参数
     * @return void
     */
    public static function parseComment($comment)
    {        
        $options           = Typecho_Widget::widget('Widget_Options');
        $cfg = array(
            'siteTitle' => $options->title,
            'timezone'  => $options->timezone,
            'cid'       => $comment->cid,
            'coid'      => $comment->coid,
            'created'   => $comment->created,
            'author'    => $comment->author,
            'authorId'  => $comment->authorId,
            'ownerId'   => $comment->ownerId,
            'mail'      => $comment->mail,
            'ip'        => $comment->ip,
            'title'     => $comment->title,
            'text'      => $comment->text,
            'permalink' => $comment->permalink,
            'status'    => $comment->status,
            'parent'    => $comment->parent,
            'manage'    => $options->siteUrl . __TYPECHO_ADMIN_DIR__ . "manage-comments.php"
        );

        self::$_isMailLog = in_array('to_log', Helper::options()->plugin('CommentToMail')->other) ? true : false;

        //是否接收邮件
        if (isset($_POST['receiveMail']) && 'yes' == $_POST['receiveMail']) {
            $cfg['banMail'] = 0;
        } else {
            $cfg['banMail'] = 1;
        }

        // 添加至队列
        $cfg      = (object)$cfg;
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $id = $db->query(
            $db->insert($prefix.'mail')->rows(array(
                'content' => serialize($cfg),
                'sent' => false
            ))
        );

        $date = new Typecho_Date(Typecho_Date::gmtTime());
        $time = $date->format('Y-m-d H:i:s');
    }
}
