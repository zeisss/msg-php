<?php

require_once __DIR__ . '/boundaries.php';
require_once __DIR__ . '/exceptions.php';

function argIsArray($value, $name) {
	if (gettype($value) != "array") {
		throw new IllegalArgumentException("Expected '$name' to be an array, got " . gettype($value));
	}
}

function argNotNull($value, $name) {
	if ($value === NULL) {
		throw new IllegalArgumentException("Expected $name to not be empty.");
	}
}

function argNotEmpty($value, $name) {
	if (empty($value)) {
		throw new IllegalArgumentException("Expected $name to not be empty.");
	}
}

function argIsID($value, $type, $name) {
	if (empty($value) || strpos($value, "msg:$type:") !== 0) {
		throw new IllegalArgumentException("Expected $name to be of format 'msg:<type>:<id>', but got '$value'");
	}
}

class MessagingService {
	private $queues;
	private $messages;

	private function newid($type) {
		$bytes = openssl_random_pseudo_bytes(16);
		return 'msg:' . $type . ':' . bin2hex($bytes);
	}

	public function MessagingService($queues, $messages, $stats) {
		$this->queues = $queues;
		$this->messages = $messages;
		$this->stats = $stats;
	}

	public function getMetrics() {
		return [
			array('type' => 'gauge', 'name' => 'messages_pending', 'help' => 'Messages pending in all queues', 'value' => $this->messages->getPendingMessageCount()),
			array('type' => 'gauge', 'name' => 'queue_count', 'help' => 'Number of created queues', 'value' => $this->queues->getQueueCount()),
			array('type' => 'gauge', 'name' => 'queue_tag_count', 'help' => 'Number of tags on all queues', 'value' => $this->queues->getTagCount())
		];
	}

	public function createQueue($createQueueReq) {
		argIsArray($createQueueReq['tags'], "tags");

		$id = $this->newid('queue');
		$this->queues->createQueue($id, $createQueueReq['tags']);

		$this->stats->counter_inc('queue_created', []);

		return array(
			'id' => $id,
			'tags' => $createQueueReq['tags']
		);
	}

	public function deleteQueue($deleteQueueReq) {
		argIsID($deleteQueueReq['queue_id'], "queue", "queue_id");

		$queue = $this->queues->getQueueById($deleteQueueReq['queue_id']);
		if ($queue == NULL) {
			throw new QueueNotFoundException();
		}

		$this->queues->deleteQueue($deleteQueueReq['queue_id']);
		$this->messages->purge($deleteQueueReq['queue_id']);
		$this->stats->counter_inc('queue_deleted', []);
		return array();
	}

	public function describeQueues($request) {
		if (empty($request['tags'])) {
			// if no tags is given, just list everything
			$queues = $this->queues->listQueues();
		} else {
			$queues = $this->queues->getQueuesByTags($request['tags']);
		}
		$this->stats->counter_inc('queues_described', []);
		return $queues;
	}

	public function pushMessage($request) {
		argIsID($request['queue_id'], "queue", 'queue_id');
		argNotEmpty($request['content_type'], 'content_type');
		argNotEmpty($request['body'], 'body');
		$queue = $this->queues->getQueueById($request['queue_id']);
		if (NULL == $queue) {
			throw new QueueNotFoundException();
		}

		$id = $this->newid('message');
		$this->messages->createMessage($id, $request['queue_id'], 
			$request['content_type'], $request['body']
		);
		$this->stats->counter_inc('message_pushed', []);

		return array(
			'message_id' => $id
		);
	}

	public function describeQueueStatus($request) {
		argIsID($request['queue_id'], "queue", 'queue_id');

		$queue = $this->queues->getQueueById($request['queue_id']);
		if (NULL == $queue) {
			throw new QueueNotFoundException();
		}

		$messageCount = $this->messages->getMessageCount($request['queue_id']);

		$this->stats->counter_inc('queue_status_described', []);

		return array(
			'message_count' => $messageCount
		);
	}

	public function popMessage($request) {
		argIsID($request['queue_id'], "queue", 'queue_id');

		$queue = $this->queues->getQueueById($request['queue_id']);
		if (NULL == $queue) {
			throw new QueueNotFoundException();
		}

		$message = $this->messages->getNextMessage($queue['id']);
		if (empty($message)) {
			return NULL;
		}
		$this->messages->deleteMessage($queue['id'], $message['id']);

		$this->stats->counter_inc('message_poped', []);

		return array(
			'id' => $message['id'],
			'content_type' => $message['content_type'],
			'body' => $message['body'],
			'created_at' => $message['created_at']
		);
	}

	public function purgeQueue($request) {
		argIsID($request['queue_id'], "queue", 'queue_id');

		$queue = $this->queues->getQueueById($request['queue_id']);
		if (NULL == $queue) {
			throw new QueueNotFoundException();
		}

		$this->messages->purge($queue['id']);
		$this->stats->counter_inc('queue_purged', []);

		return array(); # we always return sthg
	}

	public function updateQueueTags($request) {
		argIsID($request['queue_id'], "queue", 'queue_id');
		argIsArray($request['tags'], 'tags');

		$queue = $this->queues->getQueueById($request['queue_id']);
		if (NULL == $queue) {
			throw new QueueNotFoundException();
		}
		$this->queues->deleteTags($queue['id']);
		$this->queues->insertTags($queue['id'], $request['tags']);
		$this->stats->counter_inc('queue_tags_updated', []);

		return array(); # we always return sthg
	}
}
