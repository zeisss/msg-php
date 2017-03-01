<?php

require_once __DIR__ . '/../boundaries.php';

class MysqlQueueStorage implements QueueStorage, QueueStorageMetrics {
	var $pdo;

	public function MysqlQueueStorage($pdo) {
		$this->pdo = $pdo;
	}

	public function createQueue($id, $tags) {
		$stmt = $this->pdo->prepare('INSERT INTO `msg_queues` (id) VALUES (?)');
    	$stmt->execute(array($id));

		$this->insertTags($id, $tags);
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

	public function getQueuesByTags($tags) {
		$sql = 'SELECT q.id, t.tag, t.value FROM `msg_queues` q LEFT JOIN `msg_queue_tags` t ON q.id = t.queue_id WHERE q.id IN (SELECT DISTINCT queue_id FROM `msg_queue_tags` WHERE ';
		$args = [];

		$first = false;
		foreach($tags as $key => $value) {
			$args[] = $key;
			$args[] = $value;

			$sql = $sql . "(tag = ? AND value = ?)";

			if (!$first) {
				$sql . ' AND ';
			}
			$first = false;
		}
		$sql .= ")";
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($args);
		$result = $stmt->fetchAll();

		return $this->fromResultSet($result);
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

	public function deleteTags($id) {
		$stmt = $this->pdo->prepare('DELETE FROM `msg_queue_tags` WHERE queue_id = ?');
		$stmt->execute([$id]);
	}

	public function insertTags($id, $tags) {
		$stmt = $this->pdo->prepare('INSERT INTO `msg_queue_tags` (queue_id, tag, value) VALUES (?,?,?)');
		foreach($tags as $tag) {
			$stmt->execute([$id, $tag['key'], $tag['value']]);
		}
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

	public function getQueueCount() {
		$sql = 'SELECT COUNT(*) AS cnt FROM `msg_queues`';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($queueId));
		$rows = $stmt->fetchAll();
		return $rows[0]['cnt'];
	}

	public function getTagCount() {
		$sql = 'SELECT COUNT(*) AS cnt FROM `msg_queue_tags`';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($queueId));
		$rows = $stmt->fetchAll();
		return $rows[0]['cnt'];
	}
}

class MysqlMessageStorage implements MessageStorage, MessageStorageMetrics {
	var $pdo;
	public function MysqlMessageStorage($pdo) {
		$this->pdo = $pdo;
	}
	public function createMessage($id, $queueId, $contentType, $message) {
		$sql = 'INSERT INTO `msg_messages` (id, queue_id, content_type, body) VALUES (?,?, ?,?)';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($id, $queueId, $contentType, $message));
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
		return array(
			'id' => $rows[0]['id'],
			'content_type' => $rows[0]['content_type'],
			'body' => $rows[0]['body'],
			'created_at' => $rows[0]['created_at']
		);
	}

	public function getMessageCount($queueId) {
		$sql = 'SELECT COUNT(*) AS cnt FROM `msg_messages` WHERE queue_id = ?';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($queueId));
		$rows = $stmt->fetchAll();
		return $rows[0]['cnt'];
	}

	public function purge($queueId) {
		$sql = 'DELETE FROM `msg_messages` WHERE queue_id = ?';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($queueId));
	}

	public function getPendingMessageCount() {
		$sql = 'SELECT COUNT(*) AS cnt FROM `msg_messages`';
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array($queueId));
		$rows = $stmt->fetchAll();
		return $rows[0]['cnt'];
	}
}


class MysqlMessagingStatsReporter implements MessagingStatsReporter {
	private $pdo;
	private __construct($pdo) {
		$this->pdo = $pdo;
	}
	public function counter_inc($name, $labels, $inc = 1) {
		$l = json_encode($labels);
		$sql = 'INSERT DELAYED INTO `statistics` (`name`, `labels`, `value`) VALUES (?,?,?) ' . 
           'ON DUPLICATE KEY UPDATE `value` = `value` + ?';
    	$this->pdo->prepare($sql)->execute(array($name, $l, $inc, $inc));
	}
}
