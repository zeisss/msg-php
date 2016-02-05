<?php

require_once __DIR__ . '/../messaging/boundaries.php';

class RAMQueueStorage implements QueueStorage {
	private $queues;

	public function RAMQueueStorage() {
		$this->queues = [];
	}

	function createQueue($id, $tags) {
		$queue = array('id' => $id, 'tags' => $tags);
		$this->queues[] = $queue;
		return $id;
	}
	function listQueues() {
		return $this->queues;
	}
	function getQueueById($id) {
		foreach($this->queues as $queue) {
			if ($queue['id'] == $id) {
				return $queue;
			}
		}
		return NULL;
	}
	function getQueuesByTags($tags) {
		$result = [];
		foreach($this->queues as $queue) {
			if ($queue['tags'] == $tags) {
				$result[] = $queue;
			}
		}
		return $result;
	}
	function deleteTags($id) {
		foreach($this->queues as $idx => $queue) {
			if ($queue['id'] == $id) {
				$this->queues[$idx]['tags'] = [];
				return TRUE;
			}
		}
		return FALSE;
	}
	function insertTags($id, $tags) {
		foreach($this->queues as $idx => $queue) {
			if ($queue['id'] == $id) {
				foreach($tags AS $tag) {
					$this->queues[$idx]['tags'][] = $tag;
				}
				return TRUE;
			}
		}
		return FALSE;
	}
	function deleteQueue($id) {
		foreach($this->queues as $idx => $queue) {
			if ($queue['id'] == $id) {
				unset($this->queues[$idx]);
				return TRUE;
			}
		}
		return FALSE;
	}
}

class RAMMessageStorage implements MessageStorage {
	private $messages;
	public function RAMMessageStorage() {
		$this->messages = [];
	}

	function createMessage($id, $queueId, $contentType, $message) {
		$time = new DateTime("now", new DateTimeZone("UTC"));
		$message = array(
			'id' => $id,
			'queue_id' => $queueId,
			'content_type' => $contentType,
			'body' => $message,
			'created_at' => $time->format(DateTime::ISO8601)
		);
		$this->messages[] = $message;
	}

	function deleteMessage($queueId, $messageId) {
		foreach($this->messages as $idx => $message) {
			if ($message['id'] == $messageId && $message['queue_id'] == $queueId) {
				unset($this->messages[$idx]);
				return TRUE;
			}
		}
		return FALSE;
	}

	function getNextMessage($queueId) {
		foreach($this->messages as $message) {
			if ($message['queue_id'] == $queueId) {
				return $message;
			}
		}
		return NULL;
	}
	function getMessageCount($queueId) {
		$count = 0;
		foreach($this->messages as $message) {
			if ($message['queue_id'] == $queueId) {
				$count++;
			}
		}
		return $count;
	}

	function purge($queueId) {
		foreach($this->messages as $idx => $message) {
			if ($message['queue_id'] == $queueId) {
				unset($this->messages[$idx]);
			}
		}
	}
}

