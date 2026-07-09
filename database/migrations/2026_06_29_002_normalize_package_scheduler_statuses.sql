-- Normalize disposable pre-publication rows into the current package/scheduler status model.

UPDATE packages
   SET status = 'packaged'
 WHERE status IN ('ready_to_schedule', 'ready_to_publish', 'ready_for_review', 'scheduled');

UPDATE packages
   SET status = 'error'
 WHERE status = 'failed';

UPDATE scheduler_queue_items
   SET status = 'waiting'
 WHERE status IN ('scheduled', 'queued');

UPDATE scheduler_queue_items
   SET status = 'in_progress'
 WHERE status = 'processing';

UPDATE scheduler_queue_items
   SET status = 'error'
 WHERE status IN ('failed', 'retry_scheduled');

UPDATE scheduler_queue_items
   SET status = 'published'
 WHERE status = 'completed';
