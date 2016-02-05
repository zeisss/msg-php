<?php

class QueueNotFoundException extends Exception {
	
}

class IllegalArgumentException extends Exception {
	public function IllegalArgumentException($message) {
		parent::__construct($message, 400);
	}
}