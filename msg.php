<?php

class Policy {
	const EFFECT_ALLOW = 'allow';
	const EFFECT_DENY = 'deny';

	private $id;
	private $description;
	public $usernames;
	public $resources;

	public $effect = Policy::EFFECT_ALLOW;
	public $permissions = array();

	public function hasAccess() {
		return $this->effect == Policy::EFFECT_ALLOW;
	}

	public function deny() {
		$this->effect = Policy::EFFECT_DENY;
		return $this;
	}

	public function id($id) {
		$this->id = $id;
		return $this;
	}

	public function forResource($resource) {
		$this->resources[] = $resource;
		return $this;
	}

	public function forUsername($text) {
		$this->usernames[] = $text;
		return $this;
	}

	public function description($text) {
		$this->description = $text;
		return $this;
	}

	public function permission($permission) {
		$this->permissions[] = $permission;
		return $this;
	}
}
class AccessManager {
	private $policies;

	public function AccessManager() {
		$this->policies = array();
	}

	public function newPolicy() {
		$policy = new Policy();
		$this->policies[] = $policy;
		return $policy;
	}

	public function isGranted($resource, $username, $permission) {
		$allowed = false;
		// Logic is as follows:
		// * If a policy has usernames, one must match (simplified regex)
		// * If a policy has a resource, one must match (simplified regex)
		// * One policy must contain the requested permission
		// * if any policies has effect=deny, it wins over an allow policy
		// * at least one policy must allow, other it also denies
		//
		// see also https://github.com/ory-am/ladon/blob/master/guard/guard.go 
		foreach($this->policies as $policy) {
			// Check usernames match
			if (sizeof($policy->usernames) > 0) {
				if (!AccessManager::matches($username, $policy->usernames)) {
					continue;
				}
			}

			// Check resources
			if (sizeof($policy->resources) > 0) {
				if (!AccessManager::matches($resource, $policy->resources)) {
					continue;
				}
			}

			// Check permissions (one MUST match)
			if (!AccessManager::matches($permission, $policy->permissions)) {
				continue;
			}

			// Apply result
			if (!$policy->hasAccess()) {
				#echo "isGranted($username, $prefix, $permission) = false # access\n";
				return false;
			}
			$allowed = true;
		}
		#echo "isGranted($username, $prefix, $permission) = $allowed # allowed\n";
		return $allowed;
	}

	/**
	 * Checks the $needle against a list of $patterns. Returns TRUE if any pattern matches.
	 */
	private static function matches($needle, $patterns) {
		foreach($patterns as $pattern) {
			$pattern = '/^' . str_replace('*', '.*', $pattern)  . '$/';
			$result = preg_match($pattern, $needle);
			# print $pattern . " to {$needle}\n";
			# print "> $result\n";
			if (1 === $result) {
				return true;
			}
		}
		return false;
	}
}

class KeyManager {
	private $keys;

	public function KeyManager() {
		$this->keys = array();
	}
	public function addBcryptCredentials($name, $hash) {
		$key = array(
			'access' => $name,
			'secret' => $hash
		);
		$this->keys[] = $key;
	}
	public function addKey($name, $password) {
		$this->addBcryptCredentials($name, password_hash($password, PASSWORD_DEFAULT));
	}

	public function validCredentials($name, $password) {
		foreach ($this->keys AS $credentialPair) {
			if ($credentialPair['access'] == $name && password_verify($password, $credentialPair['secret'])) {
				return true;
			}
		}
		return false;
	}
}

class QueueStorage {
	var $pdo;

	private function newid() {
		$bytes = openssl_random_pseudo_bytes(16);
		return 'msg:queue:' . bin2hex($bytes);
	}

	public function createQueue($tags) {
		$id = $this->newid();
		$stmt = $this->pdo->prepare('INSERT INTO `msg_queues` (id) VALUES (?)');
    	$stmt->execute(array($id));
		
		$stmt = $this->pdo->prepare('INSERT INTO `msg_queue_tags` (queue_id, tag, value) VALUES (?,?,?)');
    	foreach($tags as $tag) {
    		$stmt->execute([$id, $tag['key'], $tag['value']]);
    	}
    	return $id;
	}

	public function listQueues() {
		$stmt = $this->pdo->prepare('SELECT q.id, t.tag, t.value FROM `msg_queues` q LEFT JOIN `msg_queue_tags` t ON q.id = t.queue_id');
		$stmt->execute();
		$result = $stmt->fetchAll();
		return $this->fromResultSet($result);
	}

	public function getQueueById($id) {
		$sql = 'SELECT q.id, t.tag, t.value FROM `msg_queues` q LEFT JOIN `msg_queue_tags` t ON q.id = t.queue_id WHERE q.id = ? ';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($id));
		$result = $stmt->fetchAll();
		$queues = $this->fromResultSet($result);
		return $queues[0];
	}

	private function fromResultSet($resultSet) {
		$queues = [];
		$lastQueue = NULL;
		foreach($resultSet as $row) {
			if($lastQueue['id'] != $row['id']) {
				if (!empty($lastQueue)) {
					$queues[] = $lastQueue;
				}
				$lastQueue = array('id' => $row['id'], 'tags' => []);
				
			}

			if (!empty($row['tag'])) {
				$tag = array(
					'key' => $row['tag'],
					'value' => $row['value']
				);
				$lastQueue['tags'][] = $tag;
			}
		}
		if (!empty($lastQueue)) {
			$queues[] = $lastQueue;
		}
		
		return $queues;
	}

	public function deleteQueue($queue) {
		$stmt = $this->pdo->prepare('DELETE FROM `msg_queues` WHERE id = ?');
		$stmt->execute(array($queue));

		if ($stmt->rowCount() == 0) {
			return false;
		}

		$stmt = $this->pdo->prepare('DELETE FROM `msg_queue_tags` WHERE queue_id = ?');
		$stmt->execute(array($queue));
		return true;
	}
}

class MessageStorage {
	var $pdo;

	private function newid() {
		$bytes = openssl_random_pseudo_bytes(16);
		return 'msg:message:' . bin2hex($bytes);
	}

	public function createMessage($queueId, $contentType, $message) {
		$id = $this->newid();
		$sql = 'INSERT INTO `msg_messages` (id, queue_id, content_type, body) VALUES (?,?, ?,?)';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($id, $queueId, $contentType, $message));
		return $id;
	}

	public function deleteMessage($queueId, $messageId) {
		$sql = 'DELETE FROM `msg_messages` WHERE id = ? AND queue_id = ?';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($messageId, $queueId));
		return $stmt->rowCount() > 0;
	}

	public function getNextMessage($queueId) {
		$sql = 'SELECT id, content_type, body, created_at FROM `msg_messages` WHERE queue_id = ? ORDER BY created_at LIMIT 1';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($queueId));
		$rows = $stmt->fetchAll();
		if (!isset($rows[0])) {
			return NULL;
		}
		return $rows[0];
	}

	public function getMessageCount($queueId) {
		$sql = 'SELECT COUNT(*) AS cnt FROM `msg_messages` WHERE queue_id = ?';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($queueId));
		$rows = $stmt->fetchAll();
		return $rows[0]['cnt'];
	}
}

class Server {
	private $queues;
	private $messages;
	private $auth;
	private $accessManager;

	function Server($queues, $messages, $auth, $accessManager) {
		$this->queues = $queues;
		$this->messages = $messages;
		$this->auth = $auth;
		$this->accessManager = $accessManager;
	}

	public function handleRequest($host, $method, $path, $headers, $params) {
		$this->headers = $headers;
		$this->params = $params;
		$this->path = $path;
		$this->method = $method;
		$this->host = $host;

		if ($method !== "POST") {
			$this->sendMessage("Invalid request method", 
					"Only POST is supported. Please contact the documentation.", true, 405);
		}

		$action = $params['action'];
		if (empty($action)) {
			$this->sendMessage("Invalid action", 
				"Parameter 'action' required. Please provide one.", true, 400);
		}

		$authResource = $params['queue'];
		if ($action == "CreateQueue" || $action == "DescribeQueues") {
			# CreateQueue requires no queue parameter
			$authResource = "msg:queue:*";
		}

		if (empty($authResource)) {
			$this->sendMessage("Invalid request",
				"Parameter 'queue' required.", true, 400);
		}

		$this->requiresAuthentication($action, $authResource);

		try {
			switch($action) {
				case "PushMessage":
					$this->handlePushMessage();
				case "PopMessage":
					$this->handlePopMessage();
				case "DescribeQueues":
					$this->handleDescribeQueues();
				case "CreateQueue": 
					$this->handleCreateQueue();
				case "DeleteQueue":
					$this->handleDeleteQueue();
				case "DescribeQueueStatus":
					$this->handleDescribeQueueStatus();
				default:
					$this->sendMessage("Invalid action", 
						"Unknown action '${params['action']}'. Please provide a valid one.", true, 400);
			}
		} catch(Exception $e) {
			$this->sendMessage("Internal Server Error", 
						$e->getMessage(), true, 500);
		}
	}

	private function handleDeleteQueue() {
		$queue = $this->params['queue'];
		$found = $this->queues->deleteQueue($queue);

		if ($found) {
			$this->sendQueueDeletedMessage($queue);
		} else {
			$this->sendQueueNotFound();
		}	
	}

	private function handleCreateQueue() {
		// construct tags from query parameters ?tag.1.key=name&tag.1.value=demo&tag.2.
		$tags = [];
		for ($i = 1;$i < 20; $i++) {
			if (!isset($this->params["tags_{$i}_key"])) {
				break;
			}
			$key = $this->params["tags_{$i}_key"];
			$value = $this->params["tags_{$i}_value"];

			$tags[] = array('key' => $key, 'value' => $value);
		}
		$id = $this->queues->createQueue($tags);
		
		$this->sendQueueCreatedMessage(array(
			'id' => $id,
			'tags' => $tags,
		));
	}

	private function handleDescribeQueueStatus() {
		$queueId = $this->params['queue'];

		$queue = $this->queues->getQueueById($queueId);
		if (empty($queue)) {
			$this->sendQueueNotFound();
		}
		$count = $this->messages->getMessageCount($queueId);

		$this->jsonPrint(array(
			'id' => $queueId,
			'messages' => $count
		));
	}

	private function handleDescribeQueues() {
		# TODO: implement filters by tags

		$queues = $this->queues->listQueues();

		$this->jsonPrint(array(
			'queues' => $queues
		));
	}

	private function handlePushMessage() {
		$queueId = $this->params['queue'];
		$queue = $this->queues->getQueueById($queueId);
		if (empty($queue)) {
			$this->sendQueueNotFound();
		}

		$contentType = $_SERVER["CONTENT_TYPE"];
		$body = file_get_contents('php://input');

		$messageId = $this->messages->createMessage($queueId, $contentType, $body);

		$this->sendQueuePushSuccessMessage($queueId, $messageId, md5($body));
	}

	private function handlePopMessage() {
		$queueId = $this->params['queue'];
		$queue = $this->queues->getQueueById($queueId);
		if (empty($queue)) {
			$this->sendQueueNotFound();
		}

		$message = $this->messages->getNextMessage($queueId);
		if (!empty($message)) {
			$this->messages->deleteMessage($queueId, $message['id']);
		}

		$this->sendQueuePopMessage($queueId, $message);
	}

	private function sendQueueCreatedMessage($queue) {
		$this->jsonPrint(array(
			'queue' => $queue
		));
	}

	private function sendQueueDeletedMessage($queueURI) {
		$this->jsonPrint(array(
			'queue' => $queueURI
		));
	}

	private function sendQueuePushSuccessMessage($queueId, $messageId, $md5) {
		$this->jsonPrint(array(
			'queue' => $queueId,
			'message' => $messageId,
			'content-md5' => $md5,
		));
	}
	private function sendQueuePopMessage($queueId, $messageObj) {
		$this->jsonPrint(array(
			'queue' => $queueId,
			'message' => $messageObj
		));	
	}

	private function sendQueueNotFound() {
		$this->sendMessage('Queue not found.', "Queue with id '{$queue}' not found.", true, 404);
	}

	private function sendMessage($message, $detail, $error = false, $code = 201) {
	   $body = array(
	    'code' => $code,
	    'message' => $message
	  );

	  if ($error) {
	    $body['error'] = true;
	  }
	  if (!empty($detail)) {
	    $body['detail'] = $detail;
	  }

	  http_response_code($code);

	  $this->jsonPrint($body);
	}

	private function jsonPrint($obj) {
		header("Content-Type: application/json");
		die(json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");	
	}

	private function requiresAuthentication($permission, $resource) {
		$permission = 'msg::' . $permission;

		if (!$this->checkAuthentication()) {
			header('WWW-Authenticate: Basic realm="msg.php"');

			$this->sendMessage("Authentication required", "Provided credentials are invalid.", true, 401);
		} else {
			
			$granted = $this->accessManager->isGranted(
				$resource,
				$this->username, 
				$permission
			);

			if (!$granted) {
				$this->sendMessage("Forbidden", "Access denied ({$permission}) for '{$resource}'", true, 403);
			}
		}
	}

	private function checkAuthentication() {
		$auth = $this->headers['authorization'];

		$fields = explode(" ", $auth);

		if (sizeof($fields) != 2) {
			return false;
		}

		if ($fields[0] != "Basic") {
			return false;
		}

		$credentials = explode(":", base64_decode($fields[1]));
		if (sizeof($credentials) != 2) {
			return false;
		}

		if ($this->auth->validCredentials($credentials[0], $credentials[1])) {
			$this->username = $credentials[0];
			return true;
		}

		return false;
	}
}

/*

function config_pdo() {
	return # Database
	$pdo = new PDO(
		'mysql:dbname=<db>;host=<ip>', 
		'<username>', '<password>',
      	array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\'')
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
*/

function config() {
	$accessManager = new AccessManager();
	$keyManager = new KeyManager();

	require './msg-config.php';

	$queues = new QueueStorage();
	$messages = new MessageStorage();
	
	$pdo = config_pdo();
	$queues->pdo = $pdo;
	$messages->pdo = $pdo;

	return [$queues, $messages, $keyManager, $accessManager];
}

function handleRequest() {
	global $_SERVER;

	$host = $_SERVER['HTTP_HOST'];
	$method = $_SERVER['REQUEST_METHOD'];
	$path = $_SERVER['PATH_INFO'];
	$params = $_GET;
	$headers = getallheaders();
	foreach($headers AS $key => $value) {
		$headers[strtolower($key)] = $value;
	}

	list($queues, $messages, $auth, $accessManager) = config();
	$server = new Server($queues, $messages, $auth, $accessManager);
	$server->handleRequest($host, $method, $path, $headers, $params);
}

handleRequest();