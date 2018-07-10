<?php
	
	/**此处用的是TP框架，只列出了几个用到的微博发布相关的方法
     * [页面显示微博回调按钮]
     */
    public function data_html()
    {
        //将生成的code_url传到页面上，没有weiboToken时进行授权回调
        import('Vendor.Libweibo.Saetv2');
        $o = new \SaeTOAuthV2( self::WB_AKEY , self::WB_SKEY );
        $code_url = $o->getAuthorizeURL( self::WB_CALLBACK_URL );

        $this->assign('code_url', $code_url);
        $this->assign('WB_AKEY', self::WB_AKEY);
        $this->assign('WB_SKEY', self::WB_SKEY);
        $this->assign('WB_CALLBACK_URL', self::WB_CALLBACK_URL);
        $redis = new \Redis();
        $redis -> connect(C('REDIS_HOST'), C('REDIS_PORT'));
        $this->assign('weiboToken', $redis->get('weiboToken'));
        $this->display();
    }
	
	/**
     * [微博回调方法callback]
     */
	public function callback()
    {
        import('Vendor.Libweibo.Saetv2');
        $o = new \SaeTOAuthV2( self::WB_AKEY , self::WB_SKEY );
        if (isset($_REQUEST['code'])) {
            $keys = array();
            $keys['code'] = $_REQUEST['code'];
            $keys['redirect_uri'] = self::WB_CALLBACK_URL;
            try {$wb_token = $o->getAccessToken( 'code', $keys ) ;}
            catch (OAuthException $e) {
                $this->display('callback_err');
            }
        }

        if ($wb_token) {
            $c = new \SaeTClientV2( WB_AKEY , WB_SKEY , $wb_token['access_token'] );
            $ms  = $c->home_timeline(); 
			//这里限制了在redis中只存固定账号获取的access_token
            $uid_get = $c->get_uid();
            $uid = $uid_get['uid'];
            $weibo_id = M('config')->where("name='WB_UID'")->getField('value');
        
            if(!strcmp($uid,$weibo_id)){
                //存到redis里面,有效期是28天
                $redis = new \Redis();
                $redis -> connect(C('REDIS_HOST'), C('REDIS_PORT'));
                $redis -> setex('weiboToken',2419200,$wb_token['access_token']);
                setcookie( 'weibojs_'.$o->client_id, http_build_query($wb_token) );
                $this->display('callback_suc');
            }else{
                unset($wb_token);
                $this->display('callback_err');
            }
           
        }else{
            $this->display('callback_err');
        } 
    }
	
	/**
     * [发布微博头条文章]
	 */
	public function publishWeibo()
    {
        import('Vendor.Libweibo.Saetv2');
        if(isset($_POST['contents'])){
            //图片
            $suffix = 'http://'.$_SERVER['HTTP_HOST'];
            if(strstr($_POST['contents'],'<img')){
                $pregRule = "/<[img|IMG].*?src=[\'|\"](.*?(?:[\.jpg|\.jpeg|\.png|\.gif|\.bmp]))[\'|\"].*?[\/]?>/";
                $_POST['contents'] = preg_replace($pregRule, '<img src="'.$suffix.'${1}">', $_POST['contents']);
            }
			
            $redis = new \Redis();
            $redis -> connect(C('REDIS_HOST'), C('REDIS_PORT'));
            $weiboToken = $redis->get('weiboToken');
            $params = array(
                    'title' => $_POST['title'],
                    'content' =>rawurlencode($_POST['contents']),
                    'text' => "发布了头条文章",
                    'cover' => $suffix.'/setup/public/images/fx_weiboIndex.jpg',
                    'access_token' => $weiboToken
                );
            $url = "https://api.weibo.com/proxy/article/publish.json";  
            $ch = curl_init ();
            curl_setopt ( $ch, CURLOPT_URL, $url );
            curl_setopt ( $ch, CURLOPT_POST, 1 );
            curl_setopt ( $ch, CURLOPT_HEADER, 0 );
            curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt ( $ch, CURLOPT_POSTFIELDS, $params);
            $ret = curl_exec ( $ch );
            curl_close ( $ch );

            $ret = json_decode($ret,true);
            if($ret['code'] == '100000'){
				createMsg('分享成功',0);
			}else if($ret['code'] == '10001'){
                createMsg('微博系统错误',1);
            } else if($ret['code'] == '10008'){
                createMsg('微博参数不符合要求',1);
            } else if($ret['code'] == '11001'){
                createMsg('微博发布过于频繁',1);
            } else if($ret['code'] == '11002'){
                createMsg('微博发送失败',1);
            } else if($ret['code'] == '11003'){
                createMsg('文章关联微博失败',1);
            } else {
                createMsg('发布失败',1);
            }
        } else {
           createMsg('分享内容不能为空',1);
        }
       
    }

