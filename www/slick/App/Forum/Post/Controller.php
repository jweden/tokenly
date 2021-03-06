<?php
class Slick_App_Forum_Post_Controller extends Slick_App_ModControl
{
	function __construct()
	{
		parent::__construct();
		$this->model = new Slick_App_Forum_Post_Model;
	}
	
	public function init()
	{
		$output = parent::init();
		
		if(!isset($this->args[2])){
			$output['view'] = '404';
			return $output;
		}
		
		
		$getTopic = $this->model->get('forum_topics', $this->args[2], array(), 'url');
		if(!$getTopic){
			$output['view'] = '404';
			return $output;
		}

		
		$likeUsers = $this->model->fetchAll('SELECT u.username, u.userId, u.slug
									  FROM user_likes l
									  LEFT JOIN users u ON u.userId = l.userId
									  WHERE type = "topic" AND itemId = :id', array(':id' => $getTopic['topicId']));
		$getTopic['likeUsers'] = $likeUsers;
		$getTopic['likes'] = count($likeUsers);
		
		$getBoard = $this->model->get('forum_boards', $getTopic['boardId']);
		if($getBoard['siteId'] != $this->data['site']['siteId']){
			$output['view'] = '404';
			return $output;
		}
		
		$this->topic = $getTopic;
		$this->board = $getBoard;
		

		if(isset($this->args[3])){
			$newOutput = array();
			switch($this->args[3]){
				case 'edit':
					if(isset($this->args[4])){
						$newOutput = $this->editPost();
					}
					else{
						$newOutput = $this->editTopic();
					}
					break;
				case 'delete':
					if(isset($this->args[4])){
						$newOutput = $this->deletePost();
					}
					else{
						$newOutput = $this->deleteTopic();
					}
					break;
				case 'lock':
					$newOutput = $this->lockTopic();
					break;
				case 'unlock':
					$newOutput = $this->unlockTopic();
					break;
				case 'sticky':
					$newOutput = $this->stickyTopic();
					break;
				case 'unsticky':
					$newOutput = $this->unstickyTopic();
					break;
				case 'move':
					$newOutput = $this->moveTopic();
					break;
				case 'like':
					if(isset($this->args[4])){
						$newOutput = $this->likePost();
					}
					else{
						$newOutput = $this->likeTopic();
					}
					break;
				case 'unlike':
					if(isset($this->args[4])){
						$newOutput = $this->unlikePost();
					}
					else{
						$newOutput = $this->unlikeTopic();
					}
					break;
				case 'subscribe':
					$newOutput = $this->subscribeTopic();
					break;
				case 'unsubscribe':
					$newOutput = $this->unsubscribeTopic();
					break;
				case 'report':
					$newOutput = $this->reportPost();
					break;
				default:
					$output['view'] = '404';
					break;
			}
			
		
			
			$output = array_merge($newOutput , $output);
			return $output;
			
		}
		else{
			if($this->data['user']){
				Slick_App_LTBcoin_POP_Model::recordFirstView($this->data['user']['userId'], $this->data['module']['moduleId'], $getTopic['topicId']);
			}	
			
		}

		$profModel = new Slick_App_Profile_User_Model;
		$getTopic['author'] = $profModel->getUserProfile($getTopic['userId'], $this->data['site']['siteId']);
		$output['board'] = $getBoard;
		$output['topic'] = $getTopic;
		$output['view'] = 'topic';
		$output['title'] = $getTopic['title'].' - '.$getBoard['name'];
		
		$output['page'] = 1;
		$output['totalReplies'] = $this->model->count('forum_posts', 'topicId', $getTopic['topicId']);
		$output['numPages'] = ceil($output['totalReplies'] / $this->data['app']['meta']['postsPerPage']);
		if(isset($_GET['page'])){
			$page = intval($_GET['page']);
			if($page > 1 AND $page <= $output['numPages']){
				$output['page'] = $page;
			}
		}
		
		$output['replies'] = $this->model->getTopicReplies($getTopic['topicId'], $this->data, $output['page']);
		
		if($this->data['user'] AND posted()){
			$output = array_merge($output, $this->postReply());
			return $output;
		}

		$output['reportedPosts'] = false;
		if($this->data['user']){
			$output['form'] = $this->model->getReplyForm();
			$meta = new Slick_App_Meta_Model;
			$output['reportedPosts'] = $meta->getUserMeta($this->data['user']['userId'], 'reportedPosts');
			if($output['reportedPosts']){
				$output['reportedPosts'] = json_decode($output['reportedPosts'], true);
				$topicReported = extract_row($output['reportedPosts'], array('type' => 'topic', 'itemId' => $getTopic['topicId']));
				if($topicReported){
					$output['topic']['isReported'] = true;
				}
				foreach($output['replies'] as &$reply){
					$replyReported = extract_row($output['reportedPosts'], array('type' => 'post', 'itemId' => $reply['postId']));
					if($replyReported){
						$reply['isReported'] = true;
					}
				}
			}
		}
		
		if(!$this->data['user'] OR ($this->data['user'] AND $this->data['user']['userId'] != $getTopic['userId'])){
			if(!isset($_SESSION['viewed-topics'])){
				$_SESSION['viewed-topics'] = array();
			}
			if(!in_array($getTopic['topicId'], $_SESSION['viewed-topics'])){
				$this->model->edit('forum_topics', $getTopic['topicId'], array('views' => ($getTopic['views'] + 1)));
				$_SESSION['viewed-topics'][] = $getTopic['topicId'];
			}
		}
		
		return $output;
	}
	
	private function postReply()
	{
		$output = array();


		if(!$this->data['user'] OR !$this->data['perms']['canPostReply']){
			$output['view'] = '404';
			return $output;
		}

		$form = $this->model->getReplyForm();
		$data = $form->grabData();
		
		if($this->topic['locked'] != 0){
			$output['replyMessage'] = 'This thread is locked';
			return $output;
		}
		
		$data['topicId'] = $this->topic['topicId'];
		$data['userId'] = $this->data['user']['userId'];
		try{
			$this->data['topic'] = $this->topic;
			$post = $this->model->postReply($data, $this->data);
		}
		catch(Exception $e){
			http_response_code(400);
			$post = false;
			$output['replyMessage'] = $e->getMessage();
			$output['form'] = $form;
			return $output;
		}
		
		$numReplies = $this->model->count('forum_posts', 'topicId', $this->topic['topicId']);
		$numPages = ceil($numReplies / $this->data['app']['meta']['postsPerPage']);
		$page = '';
		if($numPages > 1){
			$page = '?page='.$numPages;
		}
		
		if($post){
			$this->redirect($this->site.'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/'.$this->topic['url'].$page.'#post-'.$post['postId']);
			return $output;
		}
		
		return $output;
	}
	
	private function editPost()
	{
		$output = array();
		
		$getPost = $this->model->get('forum_posts', $this->args[4]);
		if(!$this->data['user'] OR !$getPost OR $getPost['buried'] == 1
			OR (($getPost['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canEditOther'])
			OR ($getPost['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canEditSelf']))){
			$output['view'] = '403';
			return $output;
		}
		
		$output['view'] = 'post-form';
		$output['form'] = $this->model->getReplyForm();
		$output['form']->setValues($getPost);
		$output['post'] = $getPost;
		$output['title'] = 'Edit Post - '.$this->topic['title'];
		$output['message'] = '';
		$output['topic'] = $this->topic;
		$output['board'] = $this->board;
		
		if(posted()){
			$data = $output['form']->grabData();
			
			try{
				$this->data['topic'] = $this->topic;
				$edit = $this->model->editPost($getPost['postId'], $data, $this->data);
			}
			catch(Exception $e){
				$edit = false;
				$output['message'] = $e->getMessage();
			}
			
			if($edit){
				$this->redirect($this->data['site']['url'].$this->moduleUrl.'/'.$this->topic['url']);
			}
		}
		
		return $output;
	}
	
	private function editTopic()
	{
		$output = array();
		
		if(!$this->data['user']
			OR (($this->topic['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canEditOther'])
			OR ($this->topic['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canEditSelf']))){
			$output['view'] = '403';
			return $output;
		}
		
		$boardModel = new Slick_App_Forum_Board_Model;
		$output['view'] = '../Board/topic-form';
		$output['form'] = $boardModel->getTopicForm();
		$output['form']->setValues($this->topic);
		$output['board'] = $this->board;
		$output['topic'] = $this->topic;
		$output['message'] = '';
		$output['title'] = 'Edit Thread - '.$this->topic['title'];
		$output['mode'] = 'edit';
		
		if(posted()){
			$data = $output['form']->grabData();
			try{
				$edit = $this->model->editTopic($this->topic['topicId'], $data, $this->data);
			}
			catch(Exception $e){
				$output['message'] = $e->getMessage();
				$edit = false;
			}
			
			if($edit){
				$this->redirect($this->data['site']['url'].$this->moduleUrl.'/'.$edit['url']);
			}
		}
		
		return $output;
	}
	
	private function deletePost()
	{
		$output = array();
		
		$getPost = $this->model->get('forum_posts', $this->args[4]);
		$getPost = $this->model->get('forum_posts', $this->args[4]);
		if(!$this->data['user'] OR !$getPost OR $getPost['buried'] == 1
			OR (($getPost['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canBuryOther'])
			OR ($getPost['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canBurySelf']))){
			$output['view'] = '403';
			return $output;
		}
		
		$delete = $this->model->edit('forum_posts', $getPost['postId'], array('buried' => 1, 'content' => '[deleted]'));
		$this->redirect($this->data['site']['url'].$this->moduleUrl.'/'.$this->topic['url']);
		$output['view'] = 'topic';
		
		return $output;
	}
	
	private function deleteTopic()
	{
		$output = array();
		
		if(!$this->data['user']
			OR (($this->topic['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canDeleteOtherTopic'])
			OR ($this->topic['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canDeleteSelfTopic']))){
			$output['view'] = '403';
			return $output;
		}
		
		$delete = $this->model->delete('forum_topics', $this->topic['topicId']);
		$this->redirect($this->data['site']['url'].'/'.$this->data['app']['url'].'/board/'.$this->board['slug']);
		$output['view'] = 'topic';
		
		return $output;
	}
	
	private function lockTopic()
	{
		$output = array();

		if(!$this->data['user']
			OR (($this->topic['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canLockOther'])
			OR ($this->topic['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canLockSelf']))){
			$output['view'] = '403';
			return $output;
		}
		
		$lock = $this->model->edit('forum_topics', $this->topic['topicId'], array('locked' => 1, 'lockTime' => timestamp(), 'lockedBy' => $this->data['user']['userId']));
		$this->redirect($this->data['site']['url'].$this->moduleUrl.'/'.$this->topic['url']);
		
		return $output;
	}

	private function unlockTopic()
	{
		$output = array();

		if(!$this->data['user']
			OR (($this->topic['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canLockOther'])
			OR ($this->topic['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canLockSelf']))){
			$output['view'] = '403';
			return $output;
		}
		
		$lock = $this->model->edit('forum_topics', $this->topic['topicId'], array('locked' => 0));
		$this->redirect($this->data['site']['url'].$this->moduleUrl.'/'.$this->topic['url']);
		
		return $output;
	}
	
	private function stickyTopic()
	{
		$output = array();

		if(!$this->data['user']
			OR (($this->topic['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canStickyOther'])
			OR ($this->topic['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canStickySelf']))){
			$output['view'] = '403';
			return $output;
		}
		
		$sticky = $this->model->edit('forum_topics', $this->topic['topicId'], array('sticky' => 1));
		$this->redirect($this->data['site']['url'].$this->moduleUrl.'/'.$this->topic['url']);
		
		return $output;
	}

	private function unstickyTopic()
	{
		$output = array();

		if(!$this->data['user']
			OR (($this->topic['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canStickyOther'])
			OR ($this->topic['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canStickySelf']))){
			$output['view'] = '403';
			return $output;
		}
		
		$sticky = $this->model->edit('forum_topics', $this->topic['topicId'], array('sticky' => 0));
		$this->redirect($this->data['site']['url'].$this->moduleUrl.'/'.$this->topic['url']);
		
		return $output;
	}
	
	private function moveTopic()
	{
		$output = array();
		if(!$this->data['user']
			OR (($this->topic['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canMoveOther'])
			OR ($this->topic['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canMoveSelf']))){
			$output['view'] = '403';
			return $output;
		}
		
		$output['view'] = 'move-topic';
		$output['form'] = $this->model->getMoveTopicForm($this->data['site']);
		$output['topic'] = $this->topic;
		$output['board'] = $this->board;
		$output['message'] = '';
		$output['title'] = 'Move Thread - '.$this->topic['title'];
		$output['form']->setValues($this->topic);
		
		if(posted()){
			$data = $output['form']->grabData();
			try{
				$move = $this->model->moveTopic($this->topic['topicId'], $data);
			}
			catch(Exception $e){
				$output['message'] = $e->getMessage();
				$move = false;
			}
			
			$boardModule = $this->model->get('modules', 'forum-board', array(), 'slug');
			if($move){
				$this->redirect($this->data['site']['url'].'/'.$this->data['app']['url'].'/'.$boardModule['url'].'/'.$move['slug']);
			}
		}
		
		
		return $output;
		
	}
	
	private function likeTopic()
	{
		ob_end_clean();
		header('Content-Type: text/json');
		$output = array();
		if(!$this->data['user']){
			http_response_code(400);
			$output['error'] = 'Not logged in';
			echo json_encode($output);
			die();
		}
		
		$getLike = $this->model->fetchSingle('SELECT *
											  FROM user_likes
											  WHERE userId = :userId AND itemId = :id AND type = "topic"',
											 array(':userId' => $this->data['user']['userId'], ':id' => $this->topic['topicId']));
		if($getLike){
			http_response_code(400);
			$output['error'] = 'Already liked';
			echo json_encode($output);
			die();
		}
		
		$like = $this->model->insert('user_likes', array('userId' => $this->data['user']['userId'],
														'itemId' => $this->topic['topicId'], 'type' => 'topic', 'likeTime' => timestamp()));
		if(!$like){
			http_response_code(400);
			$output['error'] = 'Error adding like';
			echo json_encode($output);
			die();
		}
		Slick_App_Meta_Model::notifyUser($this->topic['userId'], '<a href="'.$this->data['site']['url'].'/profile/user/'.$this->data['user']['slug'].'">'.$this->data['user']['username'].'</a> likes your forum thread
		<a href="'.$this->data['site']['url'].'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/'.$this->topic['url'].'">'.$this->topic['title'].'</a>',
				$this->topic['topicId'], 'like-topic-'.$this->data['user']['userId']);
		
		$output['result'] = 'success';
		echo json_encode($output);
		die();
	}
	
	private function unlikeTopic()
	{
		ob_end_clean();
		header('Content-Type: text/json');
		$output = array();
		if(!$this->data['user']){
			http_response_code(400);
			$output['error'] = 'Not logged in';
			echo json_encode($output);
			die();
		}
		
		$getLike = $this->model->fetchSingle('SELECT *
											  FROM user_likes
											  WHERE userId = :userId AND itemId = :id AND type = "topic"',
											 array(':userId' => $this->data['user']['userId'], ':id' => $this->topic['topicId']));
		if(!$getLike){
			http_response_code(400);
			$output['error'] = 'Not yet liked..';
			echo json_encode($output);
			die();
		}
		
		$like = $this->model->delete('user_likes', $getLike['likeId']);
		if(!$like){
			http_response_code(400);
			$output['error'] = 'Error un-like-ing';
			echo json_encode($output);
			die();
		}
		
		$output['result'] = 'success';
		echo json_encode($output);
		die();
	}
	
	private function likePost()
	{
		ob_end_clean();
		header('Content-Type: text/json');
		$output = array();
		if(!$this->data['user']){
			http_response_code(400);
			$output['error'] = 'Not logged in';
			echo json_encode($output);
			die();
		}
		
		$getPost = $this->model->get('forum_posts', $this->args[4]);
		if(!$getPost OR $getPost['topicId'] != $this->topic['topicId']){
			http_response_code(400);
			$output['error'] = 'Invalid post';
			echo json_encode($output);
			die();
		}
		
		$getLike = $this->model->fetchSingle('SELECT *
											  FROM user_likes
											  WHERE userId = :userId AND itemId = :id AND type = "post"',
											 array(':userId' => $this->data['user']['userId'], ':id' => $getPost['postId']));
		if($getLike){
			http_response_code(400);
			$output['error'] = 'Already liked';
			echo json_encode($output);
			die();
		}
		
		$like = $this->model->insert('user_likes', array('userId' => $this->data['user']['userId'],
														'itemId' => $getPost['postId'], 'type' => 'post', 'likeTime' => timestamp()));
		if(!$like){
			http_response_code(400);
			$output['error'] = 'Error adding like';
			echo json_encode($output);
			die();
		}
		
		$postPage = $this->model->getPostPage($getPost['postId'], $this->data['app']['meta']['postsPerPage']);
		$andPage = '';
		if($postPage > 1){
			$andPage = '?page='.$postPage;
		}
		
		if($getPost['userId'] != $this->data['user']['userId']){
			Slick_App_Meta_Model::notifyUser($getPost['userId'], '<a href="'.$this->data['site']['url'].'/profile/user/'.$this->data['user']['slug'].'">'.$this->data['user']['username'].'</a> likes your forum post in
			<a href="'.$this->data['site']['url'].'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/'.$this->topic['url'].$andPage.'#post-'.$getPost['postId'].'">'.$this->topic['title'].'.</a>',
					$getPost['postId'], 'like-post-'.$this->data['user']['userId']);
		}
		
		$output['result'] = 'success';
		echo json_encode($output);
		die();
	}
	
	private function unlikePost()
	{
		ob_end_clean();
		header('Content-Type: text/json');
		$output = array();
		if(!$this->data['user']){
			http_response_code(400);
			$output['error'] = 'Not logged in';
			echo json_encode($output);
			die();
		}
		
		$getPost = $this->model->get('forum_posts', $this->args[4]);
		if(!$getPost OR $getPost['topicId'] != $this->topic['topicId']){
			http_response_code(400);
			$output['error'] = 'Invalid post';
			echo json_encode($output);
			die();
		}
		
		$getLike = $this->model->fetchSingle('SELECT *
											  FROM user_likes
											  WHERE userId = :userId AND itemId = :id AND type = "post"',
											 array(':userId' => $this->data['user']['userId'], ':id' => $getPost['postId']));
		if(!$getLike){
			http_response_code(400);
			$output['error'] = 'Not yet liked..';
			echo json_encode($output);
			die();
		}
		
		$like = $this->model->delete('user_likes', $getLike['likeId']);
		if(!$like){
			http_response_code(400);
			$output['error'] = 'Error un-like-ing';
			echo json_encode($output);
			die();
		}
		
		$output['result'] = 'success';
		echo json_encode($output);
		die();
	}
	
	private function subscribeTopic()
	{
		ob_end_clean();
		header('Content-Type: text/json');
		$output = array();
		if(!$this->data['user']){
			http_response_code(400);
			$output['error'] = 'Not logged in';
			echo json_encode($output);
			die();
		}
		
		$getSubs = $this->model->getAll('forum_subscriptions', array('userId' => $this->data['user']['userId'], 'topicId' => $this->topic['topicId']));
		
		if(count($getSubs) > 0){
			$output['error'] = 'Already subscribed to this topic!';
		}
		else{
			$insert = $this->model->insert('forum_subscriptions', array('userId' => $this->data['user']['userId'], 'topicId' => $this->topic['topicId']));
			if(!$insert){
				$output['error'] = 'Error subscribing, please try again';
			}
			else{
				$output['result'] = 'success';
			}
		}
		
		echo json_encode($output);
		die();
	}
	
	private function unsubscribeTopic()
	{
		ob_end_clean();
		header('Content-Type: text/json');
		$output = array();
		if(!$this->data['user']){
			http_response_code(400);
			$output['error'] = 'Not logged in';
			echo json_encode($output);
			die();
		}
		$getSubs = $this->model->getAll('forum_subscriptions', array('userId' => $this->data['user']['userId'], 'topicId' => $this->topic['topicId']));
		
		if(count($getSubs) == 0){
			$output['error'] = 'Not yet subscribed to this topic!';
		}
		else{
			$delete = $this->model->sendQuery('DELETE FROM forum_subscriptions WHERE userId = :userId AND topicId = :topicId',
							array(':userId' => $this->data['user']['userId'], ':topicId' => $this->topic['topicId']));
			if(!$delete){
				$output['error'] = 'Error unsubscribing, please try again';
			}
			else{
				$output['result'] = 'success';
			}
		}
		
		
		echo json_encode($output);
		die();
	}
	
	private function reportPost()
	{
		ob_end_clean();
		header('Content-Type: text/json');
		$output = array();
		if(!$this->data['user']){
			http_response_code(400);
			$output['error'] = 'Not logged in';
			echo json_encode($output);
			die();
		}

		$meta = new Slick_App_Meta_Model;
		$reportedPosts = $meta->getUserMeta($this->data['user']['userId'], 'reportedPosts');
		if(!$reportedPosts){
			$reportedPosts = array();
		}
		else{
			$reportedPosts = json_decode($reportedPosts, true);
		}
		
		if(!isset($_POST['itemId']) OR !isset($_POST['type'])){
			http_response_code(400);
			$output['error'] = 'Invalid parameters';
			echo json_encode($output);
			die();
		}
		
		$getItem = false;
		$reportMessage = 'a post';
		switch($_POST['type']){
			case 'topic':
				$getItem = $this->model->get('forum_topics', $_POST['itemId']);
				if($getItem){
					$reportMessage = ' the thread <a href="'.$this->data['site']['url'].'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/'.$getItem['url'].'">'.$getItem['title'].'</a>';
				}
				break;
			case 'post':
				$getItem = $this->model->get('forum_posts', $_POST['itemId']);
				if($getItem){
					$getTopic = $this->model->get('forum_topics', $getItem['topicId']);
					$getPoster = $this->model->get('users', $getItem['userId'], array('userId', 'slug', 'username'));
					$postPage = $this->model->getPostPage($getItem['postId'], $this->data['app']['meta']['postsPerPage']);
					
					$reportMessage = ' a post by <a href="'.$this->data['site']['url'].'/profile/user/'.$getPoster['slug'].'">'.$getPoster['username'].'</a> in the thread <a href="'.$this->data['site']['url'].'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/'.$getTopic['url'].'?page='.$postPage.'#post-'.$getItem['postId'].'">'.$getTopic['title'].'</a>';
				
				}

				break;
		}
		
		if(!$getItem){
			http_response_code(400);
			$output['error'] = 'Post not found';
			echo json_encode($output);
			die();
		}
		
		foreach($reportedPosts as $report){
			$hasReported = false;
			switch($report['type']){
				case 'topic':
					if(isset($getItem['topicId']) AND $getItem['topicId'] == $report['itemId']){
						$hasReported = true;
					}
					break;
				case 'post':
					if(isset($getItem['postId']) AND $getItem['postId'] == $report['itemId']){
						$hasReported = true;
					}
					break;
			}
			if($hasReported){
				http_response_code(400);
				$output['error'] = 'Post already reported';
				echo json_encode($output);
				die();
			}
		}
		
		//notify users
		$getPerms = $this->model->getAll('app_perms', array('appId' => $this->data['app']['appId']));
		$getPerm = extract_row($getPerms, array('permKey' => 'canReceiveReports'));
		if($getPerm){
			$getPerm = $getPerm[0];
			$permGroups = $this->model->getAll('group_perms', array('permId' => $getPerm['permId']));
			$notifyList = array();
			foreach($permGroups as $permGroup){
				$groupUsers = $this->model->getAll('group_users', array('groupId' => $permGroup['groupId']));
				foreach($groupUsers as $gUser){
					if(!in_array($gUser['userId'], $notifyList)){
						$notifyList[] = $gUser['userId'];
					}
				}
			}
			
			foreach($notifyList as $notifyUser){
				$notify = Slick_App_Meta_Model::notifyUser($notifyUser, '<a href="'.$this->data['site']['url'].'/profile/user/'.$this->data['user']['slug'].'">'.$this->data['user']['username'].'</a> has flagged/reported '.$reportMessage.' - please investigate.',
								$_POST['itemId'], 'report-'.$_POST['type'], true);
				
			}
		}
		
		$reportedPosts[] = array('type' => $_POST['type'], 'itemId' => $_POST['itemId']);
		$update = $meta->updateUserMeta($this->data['user']['userId'], 'reportedPosts', json_encode($reportedPosts));
		if(!$update){
			http_response_code(400);
			$output['error'] = 'Error reporting post';
			echo json_encode($output);
			die();
		}
		
		$output['result'] = 'success';
		
		echo json_encode($output);
		die();
	}

}
