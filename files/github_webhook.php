<?php
	/*
		GitHub Webhook 1.0.1
		(c) 2013 NeoPhoenix <admin@neophoenix.net>
	*/
	require_once('options.inc.php');
	if(!defined('RELATIVE_WBB_DIR')) define('RELATIVE_WBB_DIR','');
	if(!GITHUBWEBHOOK_ACTIVATE) exit;
	
	// WBB
	require_once(RELATIVE_WBB_DIR.'global.php');
	require_once(RELATIVE_WBB_DIR.'lib/data/thread/ThreadEditor.class.php');
	require_once(RELATIVE_WBB_DIR.'lib/data/board/Board.class.php');
	// WCF
	require_once(WCF_DIR.'lib/data/user/User.class.php');
	require_once(WCF_DIR.'lib/data/user/UserEditor.class.php');
	require_once(WCF_DIR.'lib/data/user/UserProfile.class.php');

	$sys = new GHWebhook(@$_POST['payload']);

	class GHWebhook
	{
		//Vars
		public $userClass = null;
		public $plugindata = array
				(
					'userID'	=> GITHUBWEBHOOK_USERID,
					'userName'	=> GITHUBWEBHOOK_USERNAME,
					'boardID'	=> GITHUBWEBHOOK_BOARDID,
					'threadID'	=> GITHUBWEBHOOK_THREADID,
					'repoList'	=> GITHUBWEBHOOK_WHITELIST
				);
		private $whitelist_ip = array('204.232.175.75','207.97.227.253','207.97.227.32','50.57.128.197','50.57.128.32','108.171.174.178','108.171.174.32','50.57.231.61','50.57.231.32','54.235.183.49','54.235.183.32','54.235.183.23','54.235.183.32','54.235.118.251','54.235.118.32','54.235.120.57','54.235.120.32','54.235.120.61','54.235.120.32','54.235.120.62','54.235.120.32');

		//Methods
		public function __construct($data)
		{
			if(!isset($data)) return;
			if(!$this->IsIpWhitelisted($_SERVER['REMOTE_ADDR'])) return;
			$post_data = json_decode($data,true);
			if(!$this->IsRepoWhitelisted($post_data['repository']['owner']['name'],$post_data['repository']['name'])) return;
			$commits = $post_data['commits'];
			$this->userClass = new User($this->plugindata['userID'],null,null);
			if($this->userClass->userID) $this->plugindata['userName'] = $this->userClass->username;
			else
			{
				$this->plugindata['userID']		= -1;
				$this->plugindata['userName']	= GITHUBWEBHOOK_USERNAME;
			}
			foreach($commits as $commit)
			{
				$sql = 'SELECT * FROM wcf'.WCF_N.'_githubdata_hash WHERE hash="'.md5($commit['id']).'"';
				$result = WCF::getDB()->sendQuery($sql);
				if(mysql_num_rows($result)) continue;
				$sql = 'INSERT INTO wcf'.WCF_N.'_githubdata_hash (timestamp,hash) VALUES ('.time().',"'.md5($commit['id']).'")';
				WCF::getDB()->sendQuery($sql);
				
				$search_for = array('%commit','%author','%email','%url','%repo_url','%repo_name','%repo_description','%repo_watchers','%repo_forks','%repo_owner_mail','%repo_owner_name');
				$replace_with = array(htmlspecialchars($commit['message']),htmlspecialchars($commit['author']['name']),htmlspecialchars($commit['author']['email']),htmlspecialchars($commit['url']),htmlspecialchars($post_data['repository']['url']),htmlspecialchars($post_data['repository']['name']),htmlspecialchars($post_data['repository']['description']),htmlspecialchars($post_data['repository']['watchers']),htmlspecialchars($post_data['repository']['forks']),htmlspecialchars($post_data['repository']['owner']['email']),htmlspecialchars($post_data['repository']['owner']['name']));

				$title = addslashes(str_replace($search_for,$replace_with,GITHUBWEBHOOK_TITLE_TEMPLATE));
				$title = addslashes(substr($title,0,GITHUBWEBHOOK_TITLE_LEN).(strlen($title)>GITHUBWEBHOOK_TITLE_LEN?'...':''));
				$pagetext = addslashes(str_replace($search_for,$replace_with,GITHUBWEBHOOK_CONTENT_TEMPLATE));
				if(!GITHUBWEBHOOK_HTML) $pagetext = htmlentities($pagetext);
				if(GITHUBWEBHOOK_POSTING == 0)
				{
					$this->AddThread
						(
							$this->plugindata['boardID'],
							' ',
							$title,
							$pagetext,
							0,
							0,
							0,
							0,
							1,
							1,
							0
						);
				}
				else if(GITHUBWEBHOOK_POSTING == 1)
				{
					$this->AddPost
						(
							$this->plugindata['threadID'],
							$title,
							$pagetext,
							0,
							0,
							1,
							1,
							0
						);
				}
			}
		}
		
		public function IsIpWhitelisted($ip)
		{
			if(in_array($ip,$this->whitelist_ip))
			{
				return true;
			}
			return false;
		}
		
		public function IsRepoWhitelisted($owner,$repo)
		{
			$subject = $owner.'/'.$repo;
			$arr = explode('\n',$this->plugindata['repoList']);
			$arr = str_replace('\r','',$arr);
			foreach($arr as $line)
			{
				if(empty($line)) continue;
				$pattern = '~^'.$line.'$~';
				$result = preg_match($pattern,$subject);
				if($result) return true;
			}
			return false;
		}
		
		public function AddThread($boardID,$prefix,$headline,$message,$important=0,$closed=0,$disabled=0,$enableSmilies=0,$enableHTML=0,$enableBBCodes=1,$enableSignature=0)
		{
			$board = new BoardEditor($boardID);
			$board->enter();
			$newThread = ThreadEditor::create
				(
					$boardID,
					0,
					$prefix,
					$headline,
					$message,
					$this->plugindata['userID'],
					$this->plugindata['userName'],
					intval($important==1),
					intval($important==2),
					$closed,
					array
					(
						'enableSmilies' 	=> $enableSmilies,
						'enableHtml' 		=> $enableHTML,
						'enableBBCodes' 	=> $enableBBCodes,
						'showSignature' 	=> $enableSignature
					),
					0,
					null,
					null,
					$disabled
				);
			if($this->userClass->userID && $board->countUserPosts)
			{
				require_once(WBB_DIR.'lib/data/user/WBBUser.class.php');
				WBBUser::updateUserPosts($this->userClass->userID,1);
				if(ACTIVITY_POINTS_PER_THREAD)
				{
					require_once(WCF_DIR.'lib/data/user/rank/UserRank.class.php');
					UserRank::updateActivityPoints(ACTIVITY_POINTS_PER_THREAD);
				}
			}
			$board->addThreads();
			$board->setLastPost($newThread);	
			WCF::getCache()->clearResource('stat');
			WCF::getCache()->clearResource('boardData');							
			$newThread->sendNotification
				(
					new Post(
								null,
								array( 
										'postID' 			=> $newThread->firstPostID,
										'message' 			=> $message,
										'enableSmilies' 	=> $enableSmilies,
										'enableHtml' 		=> $enableHTML,
										'enableBBCodes' 	=> $enableBBCodes
									)
							),
							null 
				);
			return $newThread->threadID;
		}
		
		public function AddPost($threadID,$subject,$message,$closed=0,$enableSmilies=0,$enableHTML=1,$enableBBCodes=1,$enableSignature=1)
		{		
			$Thread		= new ThreadEditor($threadID,null,null);
			$ThreadID	= $Thread->threadID;		
			$Board		= new BoardEditor($Thread->boardID);		
			$disablePost=0;
			if($Thread->isDisabled) $disablePost=1;
			$newPost=PostEditor::create($Thread->threadID,$subject,$message,$this->plugindata['userID'],$this->plugindata['userName'],array
				(
					'enableSmilies'=>$enableSmilies,
					'enableHtml'=>$enableHTML,
					'enableBBCodes'=>$enableBBCodes,
					'showSignature'=>$enableSignature
				),
				null,null,null,intval($disablePost));
			$Thread->addPost($newPost,$closed);						
			if($this->userClass->userID && $Board->countUserPosts)
			{
				require_once(WBB_DIR.'lib/data/user/WBBUser.class.php');
				WBBUser::updateUserPosts($this->userClass->userID,1);
				if(ACTIVITY_POINTS_PER_THREAD)
				{
					require_once(WCF_DIR.'lib/data/user/rank/UserRank.class.php');
					UserRank::updateActivityPoints(ACTIVITY_POINTS_PER_THREAD);
				}
			}			
			$Board->addPosts();
			$Board->setLastPost($Thread);
			WCF::getCache()->clearResource('stat');
			WCF::getCache()->clearResource('boardData');			
			$newPost->sendNotification($Thread,$Board,null);
		}
	}
?>