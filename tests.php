<?php

require_once __DIR__ . '/lib/messaging.php';
require_once __DIR__ . '/lib/boundaries/inmemory.php';


// Helpers
function assertNotEmpty($value) {
	assert(!empty($value), 'value must not be empty');
}
function assertNotNull($value) {
	assert($value !== NULL, 'value must not be null');
}

function assertEquals($expected, $value) {
	assert($expected === $value, 'value must be equal ' . $expected);
}

function assertNull($value) {
	assert(NULL === $value, "value must be empty");
}
function assertEmpty($value) {
	assert(empty($value), "value must be empty");
}

// Inits
function newTestMessagingSystem($queues = NULL, $messages = NULL) {
	return new MessagingService(
		$queues === NULL ? new RAMQueueStorage() : $queues,
		$messages === NULL ? new RAMMessageStorage() : $messages
	);
}

function givenExistingQueue($service, $tags = []) {
	$queue = $service->createQueue(array('tags' => $tags));
	assertNotEmpty($queue['id']);
	return $queue['id'];
}

function givenPublishedMessage($service, $queueId) {
	$result = $service->pushMessage(array(
		'queue_id' => $queueId,
		'content_type' => 'text/plain', 
		'body' => 'Hello World!'
	));
	assertNotEmpty($result);


	$details = $service->describeQueueDetails(array('queue_id' => $queueId));
	assert($details['message_count'] >= 0, "Expected message count to be at least 1");

	return $result['id']; // message id
}

function TestCreateQueueInvalidArgs() {
	$msgs = newTestMessagingSystem();
	try {
		$queue = $msgs->createQueue(NULL);
		die('Expected TestCreateQueueInvalidArgs to fail.');
	} catch (Exception $e) {
		// good!
	}
}


function TestCreateQueue() {
	$msgs = newTestMessagingSystem();
	$queue = $msgs->createQueue(array('tags' => []));
	assertNotEmpty($queue['id']);
	assertNotNull($queue['tags']);
}

function TestCreateQueueWithTags() {
	$msgs = newTestMessagingSystem();
	$tags = [
		array('key' => 'name', 'value' => 'this-is-a-test'),
		array('key' => 'env', 'value' => 'testing'),
		array('key' => 'staging', 'value' => 'true')
	];
	$queue = $msgs->createQueue(array('tags' => $tags));
	assertNotEmpty($queue['id']);
	assertEquals($tags, $queue['tags']);
}

function TestDeleteQueueReturnsTrueForExistingQueue() {
	$msgs = newTestMessagingSystem();
	$queueId = givenExistingQueue($msgs);

	$response = $msgs->deleteQueue(array('queue_id' => $queueId));
	assertEmpty($response);
}

function TestDeleteQueuePurgesMessages() {
	$messages = new RAMMessageStorage();
	$msgs = newTestMessagingSystem(NULL, $messages);

	$queueId = givenExistingQueue($msgs);
	$messageId = givenPublishedMessage($msgs, $queueId);

	$msgs->deleteQueue(array('queue_id' => $queueId));

	$messageCount = $messages->getMessageCount($queueId);
	assert($messageCount == 0, "Expected message store to be purged on deleteQueue");
}

function TestDescribeByTags() {
	$msgs = newTestMessagingSystem();

	$tags = [array('key' => 'test', 'value' => 'true')];
	$id = givenExistingQueue($msgs, $tags);
	$id2 = givenExistingQueue($msgs, []);

	// Fetch queues
	$queues = $msgs->describeQueues(array('tags' => $tags));
	assert(sizeof($queues) == 1, "Expected queues to be length=1");
	assert($queues[0]['id'] == $id, "Expected '$id', but got ". $queues[0]['id']);
}

function TestDescribeWithoutTags() {
	$msgs = newTestMessagingSystem();

	$tags = [array('key' => 'test', 'value' => 'true')];
	$id = givenExistingQueue($msgs, $tags);

	// Fetch queues
	$queues = $msgs->describeQueues(array());
	assert(sizeof($queues) == 1, "Expected queues to be length=1");
	assert($queues[0]['id'] == $id, "Expected '$id', but got ". $queues[0]['id']);
}

function TestMessagingCycle() {
	$msgs = newTestMessagingSystem();

	$id = givenExistingQueue($msgs);

	$pushMessageResp = $msgs->pushMessage(array(
		'queue_id' => $id,
		'content_type' => 'text/plain',
		'body' => 'Hello World!'
	));
	assertNotNull($pushMessageResp);
	assertNotEmpty($pushMessageResp['message_id']);

	$details = $msgs->describeQueueDetails(array('queue_id' => $id));
	assert($details['message_count'] == 1, "Expected message count to be 1");

	// Pop the message
	$popMessageResponse = $msgs->popMessage(array('queue_id' => $id));
	assertNotNull($popMessageResponse);
	assertEquals($pushMessageResp['id'], $popMessageResponse['message_id']);
	assertEquals("Hello World!", $popMessageResponse['body']);
	assertEquals('text/plain', $popMessageResponse['content_type']);
	assertNotEmpty($popMessageResponse['created_at']);

	// Ensure the message is gone
	$details = $msgs->describeQueueDetails(array('queue_id' => $id));
	assert($details['message_count'] === 0, "Expected message count to be 0");

	// Pop another message
	$popMessageResponse = $msgs->popMessage(array('queue_id' => $id));
	assertNull($popMessageResponse);
}

function TestPurge() {
	$msgs = newTestMessagingSystem();
	$queueId = givenExistingQueue($msgs);
	$message = givenPublishedMessage($msgs, $queueId);

	$response = $msgs->purgeQueue(array('queue_id' => $queueId));
	assertNotNull($response);

	$details = $msgs->describeQueueDetails(array('queue_id' => $queueId));
	assert($details['message_count'] == 0, "Expected message count to be 0");
}

function TestPushMessagesFailsWithWrongQueue() {
	$msgs = newTestMessagingSystem();
	try {
		$queue = $msgs->pushMessage(NULL);
		die('Expected TestPushMessagesFailsWithWrongQueue#1 to fail.');
	} catch (Exception $e) {
		// good!
	}

	try {
		$queue = $msgs->pushMessage(array());
		die('Expected TestPushMessagesFailsWithWrongQueue#2 to fail.');
	} catch (Exception $e) {
		// good!
	}
}

function TestUpdateRemovesTags() {
	$msgs = newTestMessagingSystem();
	$tags = [array('key' => 'under-test', 'value' => 'true')];
	$queueId = givenExistingQueue($msgs, $tags);

	$msgs->updateQueueTags(array(
		'queue_id' => $queueId,
		'tags' => []
	));

	$queues = $msgs->describeQueues(array());
	assert(sizeof($queues) == 1, "Expected only one queue");
	assert($queues[0]['id'] == $queueId, "Expected queue $queueId, but found {$queues[0]['id']}");
	assert(sizeof($queues[0]['tags']) == 0, "Expected queue to have no tags");
}


function TestUpdateAddsTags() {
	$msgs = newTestMessagingSystem();
	$tags = [array('key' => 'under-test', 'value' => 'true')];
	$queueId = givenExistingQueue($msgs, []);

	$msgs->updateQueueTags(array(
		'queue_id' => $queueId,
		'tags' => $tags
	));

	$queues = $msgs->describeQueues(array());
	assert(sizeof($queues) == 1, "Expected only one queue");
	assert($queues[0]['id'] == $queueId, "Expected queue $queueId, but found {$queues[0]['id']}");
	assert($queues[0]['tags'] == $tags, "Expected queue to have tags: $tags");
}

function TestQueueNotFoundException() {
	$msgs = newTestMessagingSystem();
	try {
		$msgs->describeQueueDetails(array('queue_id' => 'foo-bar'));
		assert(false, "Expected an Exception, got nothing");
	} catch (QueueNotFoundException $e) {

	} catch (Exception $e) {
		assert(false, "Unexpected Exception: $e");
	}

	try {
		$msgs->updateQueueTags(array('queue_id' => 'foo-bar', 'tags' => []));
		assert(false, "Expected an Exception, got nothing");
	} catch (QueueNotFoundException $e) {

	} catch (Exception $e) {
		assert(false, "Unexpected Exception: $e");
	}

	try {
		$msgs->popMessage(array('queue_id' => 'foo-bar'));
		assert(false, "Expected an Exception, got nothing");
	} catch (QueueNotFoundException $e) {

	} catch (Exception $e) {
		assert(false, "Unexpected Exception: $e");
	}

	try {
		$msgs->pushMessage(array('queue_id' => 'foo-bar', 'body' => 'bla', 'content_type' => 'text/plain'));
		assert(false, "Expected an Exception, got nothing");
	} catch (QueueNotFoundException $e) {

	} catch (Exception $e) {
		assert(false, "Unexpected Exception: $e");
	}

	try {
		$msgs->purgeQueue(array('queue_id' => 'foo-bar'));
		assert(false, "Expected an Exception, got nothing");
	} catch (QueueNotFoundException $e) {

	} catch (Exception $e) {
		assert(false, "Unexpected Exception: $e");
	}

	try {
		$msgs->deleteQueue(array('queue_id' => 'foo-bar'));
		assert(false, "Expected an Exception, got nothing");
	} catch (QueueNotFoundException $e) {

	} catch (Exception $e) {
		assert(false, "Unexpected Exception: $e");
	}
}

function run_tests() {
	TestQueueNotFoundException();

	TestCreateQueueInvalidArgs();
	TestCreateQueue();
	TestCreateQueueWithTags();

	TestDescribeWithoutTags();

	TestDeleteQueueReturnsTrueForExistingQueue();

	TestDescribeByTags();
	TestMessagingCycle();
	TestPushMessagesFailsWithWrongQueue();
	TestPurge();

	TestUpdateRemovesTags();
	TestUpdateAddsTags();
	TestDeleteQueuePurgesMessages();
}

run_tests();
