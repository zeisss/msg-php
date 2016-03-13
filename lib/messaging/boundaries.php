<?php

interface QueueStorage {
	function createQueue($id, $tags);
	function listQueues();
	function getQueueById($id);
	function getQueuesByTags($tags);
	function deleteTags($id);
	function insertTags($id, $tags);
	function deleteQueue($queue);
}

interface MessageStorage {
	function createMessage($messageId, $queueId, $contentType, $message);
	function deleteMessage($queueId, $messageId);
	function getNextMessage($queueId);
	function getMessageCount($queueId);
	function purge($queueId);
}
