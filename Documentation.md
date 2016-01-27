# msg.php

## Welcome

A very simple http based message queue inspired by Amazons SNS.

## DataStructures

### URI

URIs are string identifiers for objects inside the _msg_ service. They generally take the following format: `msg:<type>:<unique-id>`. Type is one of `queue` or `message`.

### Queue 

```
{
	"id": "msg:queue:uuid()"
	"tags": [
		{"key": "Name", "value": "Value"},
		...
	]
}
```

### QueueStatus

```
{
	"id": "msg:queue:uuid()",
	"messages": int(),
}
```

### Message

```
{
	"id": "string()",
	"content_type": "string()",
	"body": "json escaped query body",
	"created_at": "ISO8601()"
}
```

### ErrorMessage

```
{
	"message": "string()",
	["error": bool(),]
	["detail": "string()",]
	"code": int()
}
```

## Actions

Generally all actions have the same format. Send a `POST` request to `https://apps.moinz.de/msg/msg.php` with a query parameter `action` of one of the list below. Operation parameters are given via query parameters. Data from the request body is only used when explicitly stated.

### CreateQueue

Creates a new queue and returns the created queue.

Query Parameters | Description
---------------- | ----------------------
tags.N.key       | The key for the tag
tags.N.value     | The value of the tag

Response Fields  | Type  | Description
---------------- | ----- | ---------------
queue            | Queue | The queue that was created

### DescribeQueues

DescribeQueues fetches a list of Queues and returns them with their ids and tags to the client.

Query Parameters | Description
---------------- | ----------------------
filter.tag:key   | Filters for queues having _key_ as a tag with the given value as the value

Response Fields  | Type    | Description
---------------- | ------- | ---------------
queues           | []Queue | The queues that match the given filters

### DeleteQueue

DeleteQueue deletes the specified queue and all messages contained within it.

Query Parameters | Description
---------------- | ----------------------
queue            | the URI of the queue to delete

Response Fields  | Type  | Description
---------------- | ----- | ---------------
queue            | Queue | The queue that was deleted

### DescribeQueueStatus

Query Parameters | Description
---------------- | ----------------------
queue            | The URI of the queue to fetch the message from

Response Fields  | Type    | Description
---------------- | ------- | ---------------
id               | URI     | The URI of the queue
messages         | int     | Number of messages in the queue


### UpdateQueueTags

Updates the tags for the specified queue.

Query Parameters | Description
---------------- | ----------------------
queue            | The URI of the queue to fetch the message from
tags.N.key       | The key for the tag
tags.N.value     | The value of the tag

Response Fields  | Type    | Description
---------------- | ------- | ---------------
queues            | []Queue | The queues that match the given filters

### PurgeQueue

PurgeQueue removes all messages currently stored in the queue.

Query Parameters | Description
---------------- | ----------------------
queue            | The URI of the queue to fetch the message from

Response Fields  | Type    | Description
---------------- | ------- | ---------------
queue            | []Queue | The queue that was purged

### PushMessage

Creates a new message in the given queue. The body of the reuqest is taken as the message body, the request
content-type is saved along.

Query Parameters | Description
---------------- | ----------------------
queue            | The URI of the queue to push into

Response Fields  | Type  | Description
---------------- | ----- | ---------------
queue            | URI   | The URI of the queue the message was pushed into
message          | URI   | The URI of the message that was pushed
content-md5      | string | MD5 hash of the request body

### PopMessage

Returns a message and deletes it from the queue. If no message can be found, `null` is returned for the `message` field.

Query Parameters | Description
---------------- | ----------------------
queue            | The URI of the queue to fetch the message from

Response Fields  | Type    | Description
---------------- | ------- | ---------------
queue            | URI     | The URI of the queue
message          | Message | The messages that was dequeued

## Common Errors

### Invalid Method

Since the _msg_ service takes only POST requests, all other http methods are quit with a `405` error.

### Invalid Action

StatusCode: 400
If the `action` parameter is unknown.

### Invalid Request

Most actions require a `queue` parameter. If it is missing, the _msg_ services responds with a `ErrorMessage` and a 400 status code.

### QueueNotFound

When the specified `queue` cannot be found, an `ErrorMessage` and a `404` is returned.

### Internal Server Error

If an unexpected error occurs, an `ErrorMessage` with the text "Internal Server Error" and a `500` status code is returned.

## Authorization

Authorization is simply handled via htbasic auth as defined in the HTTP protocol.

## Permissions

Like the _fs-php_ service, _msg_ supports policies to fine tune access to actions and/or resources for special users.

All actions are prefixed with `msg:` and contain the name listed above, e.g. `msg::DescribeQueues`, `msg::PushMessage`.
All resource names start with `msg:` followed by the type and a unique id, e.g. `msg:queue:123ac12` or `msg:message:123141231`. Actions that do no work on a specific resource, use the resource `msg:*`. 

### Examples

```
$accessManager->newPolicy()
	->description('Eve may push into the ')
	->forUsername('eve')
	->forPermission('msg::PushMessage')
	->forResource('msg:queue:bf54e8c38a1dbe21246e268ab3109aff');

$accessManager->newPolicy()
	->description('Bob may only check queues and consume messages')
	->forUsername('bob')
	->forPermission('msg::Describe*')
	->forPermission('msg::PopMessage')
	->forResource('msg:*');
```
