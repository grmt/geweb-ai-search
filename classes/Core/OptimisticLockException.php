<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class OptimisticLockException extends \RuntimeException {
    private string $currentRevision;

    public function __construct(string $message, string $currentRevision) {
        parent::__construct($message, 409);
        $this->currentRevision = $currentRevision;
    }

    public function getCurrentRevision(): string {
        return $this->currentRevision;
    }
}
