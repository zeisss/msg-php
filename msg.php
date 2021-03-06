<?php

require_once __DIR__ . '/lib/iam.php';
require_once __DIR__ . '/lib/messaging/messaging.php';
require_once __DIR__ . '/lib/messaging/storage/mysql.php';

class HTTPAPIServer {
	private $auth;
	private $accessManager;
	private $stats;
	private $service;

	function __construct($auth, $accessManager, $stats, $service) {
		$this->auth = $auth;
		$this->accessManager = $accessManager;
		$this->stats = $stats;
		$this->service = $service;
	}

	public function handleRequest($host, $method, $path, $headers, $params) {
		$this->headers = $headers;
		$this->params = $params;
		$this->path = $path;
		$this->method = $method;
		$this->host = $host;

		$action = $params['action'];
		if (empty($action)) {
			$this->sendMessage("Invalid action",
				"Parameter 'action' required. Please provide one.", true, 400);
		}

		if ($action != "FetchPrometheusMetrics" && $method !== "POST") {
			$this->sendMessage("Invalid request method",
					"Only POST is supported. Please contact the documentation.", true, 405);
		}

		$authResource = "";
		if (!empty($params['queue'])) {
			$authResource = $params['queue'];
		}
		if ($action == "CreateQueue" || $action == "DescribeQueues" || $action == "FetchPrometheusMetrics") {
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
				case "DescribeQueues":
					$this->handleDescribeQueues();
				case "CreateQueue":
					$this->handleCreateQueue();
				case "PushMessage":
					$this->handlePushMessage();
				case "PopMessage":
					$this->handlePopMessage();
				case "DeleteQueue":
					$this->handleDeleteQueue();
				case "DescribeQueueStatus":
					$this->handleDescribeQueueStatus();
				case "PurgeQueue":
					$this->handlePurgeQueue();
				case "UpdateQueueTags":
					$this->handleUpdateQueueTags();
				case "FetchPrometheusMetrics":
					$this->handleFetchPrometheusMetrics();
				default:
					$this->sendMessage("Invalid action",
						"Unknown action '${params['action']}'. Please provide a valid one.", true, 400);
			}
		} catch (IllegalArgumentException $e) {
			$this->sendMessage("Invalid request", "", true, 400);
		} catch (QueueNotFoundException $e) {
			$this->sendQueueNotFound();
		} catch(Exception $e) {
			$this->sendMessage("Internal Server Error",
						$e->getMessage(), true, 500);
		}
	}

	private function handleDescribeQueues() {
		$tags = [];
		foreach($this->params as $key => $value) {
			if (strpos($key, "filter_tag:") === 0) {
				$key = substr($key, strlen("filter_tag:"));

				$tags[$key] = $value;
			}
		}

		$queues = $this->service->describeQueues(array(
			'tags' => $tags
		));

		$this->jsonPrint(array(
			'queues' => $queues
		));
	}

	private function handleCreateQueue() {
		$req = array(
			'tags' => $this->parseParamsTags()
		);
		$queue = $this->service->createQueue($req);
		$this->sendQueueCreatedMessage($queue);
	}

	private function handleDeleteQueue() {
		$found = $this->service->deleteQueue(array('queue_id' => $this->params['queue']));
		$this->sendQueueDeletedMessage($this->params['queue']);
	}

	private function handleDescribeQueueStatus() {
		$queueId = $this->params['queue'];
		$request = array('queue_id' => $queueId);
		$response = $this->service->describeQueueStatus($request);
		$this->jsonPrint(array(
			'id' => $queueId,
			'messages' => $response['message_count']
		));
	}

	private function handlePushMessage() {
		$queueId = $this->params['queue'];
		$contentType = $_SERVER["CONTENT_TYPE"];
		$body = file_get_contents('php://input');

		$response = $this->service->pushMessage(array(
			'queue_id' => $queueId,
			'content_type' =>  $contentType,
			'body' => $body
		));

		$this->sendQueuePushSuccessMessage($queueId, $response['message_id'], md5($body));
	}

	private function handlePopMessage() {
		$request = array('queue_id' => $this->params['queue']);
		$popMessageResponse = $this->service->popMessage($request);
		$this->sendQueuePopMessage($this->params['queue'], $popMessageResponse);
	}

	private function handlePurgeQueue() {
		$this->service->purgeQueue(array('queue_id' => $this->params['queue']));
		$this->sendPurgeSuccessMessage($this->params['queue']);
	}

	private function handleUpdateQueueTags() {
		$tags = $this->parseParamsTags();
		$this->service->updateQueueTags(array(
			'queue_id' => $this->params['queue'],
			'tags' => $tags
		));
		$this->sendQueueUpdatedMessage($this->params['queue']);
	}

	private function handleFetchPrometheusMetrics() {
		$metrics = array_merge(
			$this->service->getMetrics(),
			$this->stats->getMetrics()
		);

		$body = "";
		$tags = "";
		foreach($metrics as $metric) {
			if (isset($metric['help'])) {
				$body .= "# HELP ${metric['name']} ${metric['help']}\n";
			}
			if (isset($metric['type'])) {
				$body .= "# TYPE ${metric['name']} ${metric['type']}\n";
			}
			# should becomes
			# "thisisthekey{these=are,the=tags} thisisthevalue\n"
			$t = "";
			foreach ($metrics['labels'] as $name => $value) {
				$t .= ',' . $name . '="' . $value . '"';
			}
			$t = substr($t, 1);
			if ($t != "") {
				$t = '{' . $t . '}';
			}
			$body .= $metric['name'] . $t . ' ' . $metric['value'] . "\n";
		}

		header('Content-Type: plain/text; version=0.0.4');
		die($body);
	}

	// parseParamsTags parses the tag keys from the request.
	// 
	private function parseParamsTags() {
		// construct tags from query parameters ?tag.1.key=name&tag.1.value=demo&tag.2.
		$tags = [];
		for ($i = 1;$i <= 20; $i++) {
			if (!isset($this->params["tags_{$i}_key"])) {
				break;
			}
			$key = $this->params["tags_{$i}_key"];
			$value = $this->params["tags_{$i}_value"];

			$tags[] = array('key' => $key, 'value' => $value);
		}
		return $tags;
	}

	private function sendQueueUpdatedMessage($queueId) {
		$this->jsonPrint(array(
			'queue' => $queueId
		));
	}

	private function sendPurgeSuccessMessage($queueId) {
		$this->jsonPrint(array(
			'queue' => $queueId
		));
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

	# msg-config is allowed to modify $accessManager and $keyManager
	# msg-config also MUST define a function config_pdo() to return the PDOConnection
	require './msg-config.php';

	$pdo = config_pdo();
	$queues = new MysqlQueueStorage($pdo);
	$messages = new MysqlMessageStorage($pdo);
	$stats = new MysqlMessagingStatsReporter($pdo);
	$service = new MessagingService($queues, $messages, $stats);

	return [$keyManager, $accessManager, $stats, $service];
}

function handleRequest() {
	list($auth, $accessManager, $stats, $service) = config();
	$server = new HTTPAPIServer($auth, $accessManager, $stats, $service);

	global $_SERVER;

	$host = $_SERVER['HTTP_HOST'];
	$method = $_SERVER['REQUEST_METHOD'];
	$path = $_SERVER['PATH_INFO'];
	$params = $_GET;
	$headers = getallheaders();
	foreach($headers AS $key => $value) {
		$headers[strtolower($key)] = $value;
	}

	$server->handleRequest($host, $method, $path, $headers, $params);
}
// mod_proxy_fcgi doesn't provide function getallheaders()
// (as opposed to mod_fastcgi)
// Taken from: https://www.popmartian.com/tipsntricks/2015/07/14/howto-use-php-getallheaders-under-fastcgi-php-fpm-nginx-etc/
if (!function_exists('getallheaders')) {
  function getallheaders() {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
      if (substr($name, 0, 5) == 'HTTP_') {
        $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
      }
    }
    return $headers;
  }
}

handleRequest();
