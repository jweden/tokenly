<?php
class Slick_App_LTBcoin_POP_Model extends Slick_Core_Model
{
	
	
	function __construct()
	{
		parent::__construct();
		$this->weights = $this->getScoreWeights();
		$this->coinFieldId = PRIMARY_TOKEN_FIELD;
		$this->fields = array('views', 'register', 'comments', 'posts', 'threads', 'magic-words', 'likes', 'referrals');
		
		$tokenApp = $this->get('apps', 'ltbcoin', array(), 'slug');
		$meta = new Slick_App_Meta_Model;
		$this->appMeta = $meta->appMeta($tokenApp['appId']);
		
	}

	
	public function checkFirstView($userId, $moduleId, $itemId = 0)
	{
		$get = $this->getAll('pop_firstView', array('userId' => $userId, 'moduleId' => $moduleId, 'itemId' => $itemId), array('popId'));
		if($get AND count($get) > 0){
			return true;
		}
		return false;
	}
	
	public static function recordFirstView($userId, $moduleId, $itemId = 0)
	{
		$model = new Slick_App_LTBcoin_POP_Model;
		$check = $model->checkFirstView($userId, $moduleId, $itemId);
		if($check){
			return false;
		}
		$data = array('userId' => $userId, 'moduleId' => $moduleId, 'itemId' => $itemId, 'popDate' => timestamp());
		$insert = $model->insert('pop_firstView', $data);
		if(!$insert){
			return false;
		}
		return $insert;
	}
	
	/*
	if timeframe false, get all. else should be array('start' => date, 'end' => date)
	
	*/
	public function getUserFirstViews($userId, $timeframe = false)
	{
		$values = array(':id' => $userId);
		$sql = 'SELECT popId FROM pop_firstView WHERE userId = :id';
		if(is_array($timeframe)){
			$sql .= ' AND popDate >= "'.$timeframe['start'].'" AND popDate <= "'.$timeframe['end'].'"';
		}
		$get = $this->fetchAll($sql, $values);
		
		return count($get);
	}
	
	public function getNumUserComments($userId, $timeframe = false, $minLength = 0)
	{
		$disqus = new Slick_API_Disqus;
		$username = DISQUS_DEFAULT_FORUM.'-'.md5($userId);

		$getPosts = $disqus->getUserPosts($username, false, 100);
		
		if(!$getPosts){
			return 0;
		}
		
		$numPosts = 0;
		foreach($getPosts as $post){
			if($minLength != 0 AND strlen(strip_tags($post['message'])) < $minLength){
				continue;
			}
			if($timeframe !== false AND isset($timeframe['end'])){
				
				$diff = strtotime($post['createdAt']) - strtotime($timeframe['end']);
				$diff2 = strtotime($post['createdAt']) - strtotime($timeframe['start']);
				if($diff > 0 OR $diff2 < 0){
					continue;
				}
			}
			$numPosts++;
		}
		
		return $numPosts;
	}
	
	
	/*
	$andTopics = 0/1/-1
	0 = posts only
	1 = posts and topics
	-1 = topics only
	
	*/
	public function getNumUserPosts($userId, $timeframe = false, $minLength = 0, $andTopics = 1)
	{
		$numPosts = 0;
		$numTopics = 0;
		$values = array(':id' => $userId);
		if($andTopics >= 0){
			$sql = 'SELECT postId, content FROM forum_posts WHERE userId = :id';
			if(is_array($timeframe)){
				$sql .= ' AND postTime >= "'.$timeframe['start'].'" AND postTime <= "'.$timeframe['end'].'"';
			}
			$get = $this->fetchAll($sql, $values);
			if($minLength > 0){
				foreach($get as $k => $row){
					if(strlen(strip_tags(trim($row['content']))) < $minLength){
						unset($get[$k]);
					}
				}
			}
			$numPosts =  count($get);
		}
		if($andTopics != 0){
			$sql2 = 'SELECT topicId, content FROM forum_topics WHERE userId = :id';
			if(is_array($timeframe)){
				$sql2 .= ' AND postTime >= "'.$timeframe['start'].'" AND postTime <= "'.$timeframe['end'].'"';
			}
			$get2 = $this->fetchAll($sql2, $values);
			if($minLength > 0){
				foreach($get2 as $k => $row){
					if(strlen(strip_tags(trim($row['content']))) < $minLength){
						unset($get2[$k]);
					}
				}
			}			
			$numTopics =  count($get2);
		}
		
		return $numPosts + $numTopics;
	}
	
	/*
	returns array with num users and user info list
	
	*/
	public function getNewUsers($timeframe)
	{
		$values = array();
		$sql = 'SELECT userId, username, regDate FROM users WHERE regDate >= "'.$timeframe['start'].'" AND regDate <= "'.$timeframe['end'].'"';
		$get = $this->fetchAll($sql, $values);
		
		$output = array('numUsers' => count($get), 'users' => $get);
		
		return $output;
		
	}
	
	public function getNumPublishedPosts($userId, $timeframe = false)
	{
		$values = array(':id' => $userId);
		$sql = 'SELECT postId FROM blog_posts WHERE userId = :id AND published = 1';
		if(is_array($timeframe)){
			$sql .= ' AND publishDate >= "'.$timeframe['start'].'" AND publishDate <= "'.$timeframe['end'].'"';
		}
		$get = $this->fetchAll($sql, $values);
		
		return count($get);
	}
	
	/*
	check if user is a new registrant
	
	*/
	public function checkUserIsNew($userId, $timeframe)
	{
		$get = $this->get('users', $userId, array('regDate'));
		if(!$get){
			return false;
		}
		$start = strtotime($timeframe['start']);
		$end = strtotime($timeframe['end']);
		$reg = strtotime($get['regDate']);
		
		if($reg >= $start AND $reg <= $end){
			return true;
		}
		return false;
	}
	
	
	public function getPopScore($userId, $timeframe = false, $fields = false)
	{
		$popFields = $this->fields;
		if($fields !== false){
			$popFields = $fields;
		}
		
		$output = array('userId' => $userId, 'info' => array(), 'score' => 0, 'extra' => array());
		foreach($popFields as $field){
			$score = 0;
			$num = 0;
			switch($field){
				case 'views':
					$num = $this->getUserFirstViews($userId, $timeframe);
					$score = $num * $this->weights['viewScore'];
					break;
				case 'register':
					$check = $this->checkUserIsNew($userId, $timeframe);
					if($check){
						$score = $this->weights['registerScore'];
						$num = 1;
					}
					break;
				case 'comments':
					$num = $this->getNumUserComments($userId, $timeframe);
					$score = $this->diminishScore($num, $this->weights['commentScore']);
					break;
				case 'posts':
					$num = $this->getNumUserPosts($userId, $timeframe, 0, 0);
					$score = $this->diminishScore($num, $this->weights['postScore']);
					break;
				case 'threads':
					$num = $this->getNumUserPosts($userId, $timeframe, 0, -1);
					$score = $this->diminishScore($num, $this->weights['threadScore']);				
					break;
				case 'magic-words':
					$num = $this->getNumUserWords($userId, $timeframe);
					$score = $num * $this->weights['wordScore'];
					break;
				case 'likes':
					$num = $this->getNumUserLikes($userId, $timeframe);
					$score = $this->diminishScore($num, $this->weights['likeScore']);
					break;
				case 'referrals':
					$num = $this->getNumUserActiveReferrals($userId, $timeframe);
					$score = $num * $this->weights['referralScore'];
					break;
				case 'blog-posts':
					$num = $this->getNumUserPublishedPosts($userId, $timeframe);
					$score = $num * $this->weights['publishScore'];
					break;
				case 'pov':
					$pov = $this->getUserPOV($userId, $timeframe);
					$num = count($pov['scores']);
					$score = $pov['total'];
					$output['extra'] = $pov['scores'];
					break;
				
			}
			
			$output['info'][$field] = $num;
			$output['score'] += $score;
			
		}
		
		return $output;
		
	}
	
	public function getPopScoreList($timeframe = false, $fields = false)
	{
		$profModel = new Slick_App_Profile_User_Model;
		$getUsers = $profModel->getUsersWithProfile($this->coinFieldId);
		$output = array();
		$scores = array();
		$totalScore = 0;
		foreach($getUsers as $user){
			$getScore = $this->getPopScore($user['userId'], $timeframe, $fields);
			$getScore['address'] = $user['value'];
			$getScore['username'] = $user['username'];
			$totalScore += $getScore['score'];
			$scores[] = $getScore;
		}
		
		foreach($scores as $score){
			if($score['score'] == 0){
				continue;
			}
			$score['percent'] = ($score['score'] / $totalScore) * 100;
			$output[] = $score;
		}
		
		
		return array('totalPoints' => $totalScore, 'data' => $output);
	}
	
	public function getScoreWeights()
	{
		$getApp = $this->get('apps', 'ltbcoin', array(), 'slug');
		if(!$getApp){
			return false;
		}
		$appId = $getApp['appId'];
		$meta = new Slick_App_Meta_Model;
		$output = array();
		
		$output['commentScore'] = $meta->getAppMeta($appId, 'pop-comment-weight');
		$output['postScore'] = $meta->getAppMeta($appId, 'pop-forum-post-weight');
		$output['threadScore'] = $meta->getAppMeta($appId, 'pop-forum-topic-weight');
		$output['viewScore'] = $meta->getAppMeta($appId, 'pop-view-weight');
		$output['registerScore'] = $meta->getAppMeta($appId, 'pop-register-weight');
		$output['wordScore'] = $meta->getAppMeta($appId, 'pop-listen-weight');
		$output['likeScore'] = $meta->getAppMeta($appId, 'pop-like-weight');
		$output['referralScore'] = $meta->getAppMeta($appId, 'pop-referral-weight');
		$output['publishScore'] = $meta->getAppMeta($appId, 'pop-publish-weight');
		
		foreach($output as $key => $val){
			$output[$key] = floatval($val);
		}
		
		return $output;
	}
	
	public function diminishScore($numTimes, $weight)
	{
		$total = 0;
		if($numTimes == 0){
			return $total;
		}
		for($i = 1; $i <= $numTimes; $i++){
			$total += $weight / $i;
		}
		return $total;
	}
	
	
	public function getNumUserWords($userId, $timeframe = false)
	{
		$values = array(':id' => $userId);
		$sql = 'SELECT submitId FROM pop_words WHERE userId = :id';
		if(is_array($timeframe)){
			$sql .= ' AND submitDate >= "'.$timeframe['start'].'" AND submitDate <= "'.$timeframe['end'].'"';
		}
		$get = $this->fetchAll($sql, $values);
		
		return count($get);
	}
	
	public function getNumUserLikes($userId, $timeframe = false)
	{
		if(!isset($this->likeData)){
			$sql = 'SELECT * FROM user_likes ';
			if(is_array($timeframe)){
				$sql .= ' WHERE likeTime >= "'.$timeframe['start'].'" AND likeTime <= "'.$timeframe['end'].'"';
			}
			$this->likeData = $this->fetchAll($sql);
			if(!$this->likeData){
				return false;
			}
		}
		$num = 0;
		foreach($this->likeData as $like){
			switch($like['type']){
				case 'post':
					$getItem = $this->get('forum_posts', $like['itemId']);
					break;
				case 'topic':
					$getItem = $this->get('forum_topics', $like['itemId']);
					break;
				default:
					$getItem = false;
					break;
			}
			if(!$getItem OR !isset($getItem['userId'])){
				continue;
			}
			if($getItem['userId'] == $userId AND $like['userId'] != $userId){
				$num++;
			}
		}
		return $num;	
	}
	
	public function getNumUserActiveReferrals($userId, $timeframe = false)
	{
		$sql = 'SELECT userId FROM user_referrals
				WHERE affiliateId = :userId';
		$get = $this->fetchAll($sql, array(':userId' => $userId));
		
		$minPop = floatval($this->appMeta['referral-min-active-pop']);
		
		$output = array();
		foreach($get as $row){
			$userPop = $this->getPopScore($row['userId'], $timeframe, array('views','comments','posts','threads'));
			$score = $userPop['score'];
			if($score >= $minPop){
				$output[] = $row;
			}
		}
		
		return count($output);
	}
	
	public function getNumUserPublishedPosts($userId, $timeframe = false)
	{
		$sql = 'SELECT userId FROM blog_posts
				WHERE userId = :userId AND published = 1';
		if(is_array($timeframe)){
			$sql .= ' AND publishDate >= "'.$timeframe['start'].'" AND publishDate <= "'.$timeframe['end'].'"';
		}				
		
		$get = $this->fetchAll($sql, array(':userId' => $userId));
		
		return count($get);
	}
	
	public function getUserPOV($userId, $timeframe = false)
	{
		$score = 0;
		if(!isset($this->disqus)){
			$this->disqus = new Slick_API_Disqus;
		}
		if(!isset($this->postModel)){
			$this->postModel = new Slick_App_Blog_Post_Model;
		}
		
		$sql = 'SELECT postId, userId, url, title, views FROM blog_posts
				WHERE userId = :userId AND published = 1';
		if(is_array($timeframe)){
			$sql .= ' AND publishDate >= "'.$timeframe['start'].'" AND publishDate <= "'.$timeframe['end'].'"';
		}				
		
		$get = $this->fetchAll($sql, array(':userId' => $userId));
		$pageIndex = Slick_App_Controller::$pageIndex;
		$getSite = $this->get('sites', $_SERVER['HTTP_HOST'], array(), 'domain');
		$povScores = array();
		foreach($get as $post){
			$thisScore =  $post['views'] * $this->weights['viewScore'];
			
			$getMeta = $this->postModel->getPostMeta($post['postId']);
			$post['meta'] = array();
			foreach($getMeta as $mkey => $val){
				if(!isset($getPosts[$key][$mkey])){
					$post['meta'][$mkey] = $val;
				}
			}
			
			if((isset($post['meta']['audio-url']) AND trim($post['meta']['audio-url']) != '')
				OR (isset($post['meta']['soundcloud-id']) AND trim($post['meta']['soundcloud-id']) != '')){
				//multiply by 4!
				$thisScore = $thisScore * 4;
			}
			
			
			$getIndex = extract_row($pageIndex, array('itemId' => $post['postId'], 'moduleId' => 28));
			$postURL = $getSite['url'].'/blog/post/'.$post['url'];
			if($getIndex AND count($getIndex) > 0){
				$postURL = $getSite['url'].'/'.$getIndex[count($getIndex) - 1]['url'];
			}
			
			$commentThread = $this->disqus->getThread($postURL, false);
			if($commentThread){
				$numReplies = $commentThread['thread']['posts'];
				$thisScore += intval($numReplies) * $this->weights['commentScore'];
				$post['comments'] = $numReplies;
			}
			
			
			$popScores[] = array('post' => $post, 'score' => $thisScore);
			$score += $thisScore;
		}
		
		
		return array('scores' => @$popScores, 'total' => $score);
	}
}
