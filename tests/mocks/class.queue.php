<?php
/**
 * Mock class.queue.php for PHPUnit tests
 *
 * Provides minimal mock of osTicket's Queue and QueueColumnAnnotation classes for testing
 */

if (!class_exists('Queue')) {
    class Queue {
        // Minimal mock implementation
    }
}

if (!class_exists('QueueColumnAnnotation')) {
    abstract class QueueColumnAnnotation {
        // Minimal mock implementation
        abstract public function annotate($query, $name = false);
    }
}
