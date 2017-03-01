<?php

class QueueNotFoundException extends Exception {
	
}

class IllegalArgumentException extends Exception {
	public function __construct($message) {
		parent::__construct($message, 400);
	}
}