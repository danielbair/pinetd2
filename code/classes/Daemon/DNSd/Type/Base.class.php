<?php

namespace Daemon\DNSd\Type;

abstract class Base {
	protected $type;
	protected $value;
	protected $pkt;

	abstract public function decode($val, array $context);
	abstract public function encode($val = NULL, $offset = NULL);

	public function __construct($pkt, $type) {
		$this->pkt = $pkt;
		$this->type = $type;
	}

	public function setValue($val) {
		$this->value = $val;
	}

	public function getType() {
		return $this->type;
	}
}

